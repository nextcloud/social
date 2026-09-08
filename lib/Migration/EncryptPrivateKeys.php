<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrites the actor private keys stored as bare PEM into their encrypted
 * form. New keys are written encrypted from the start; this step only exists
 * for rows created before that. Runs on every upgrade and is a no-op once no
 * plaintext key is left, so it needs no marker.
 */
class EncryptPrivateKeys implements IRepairStep {
	private IDBConnection $connection;
	private PrivateKeyCipher $keyCipher;

	public function __construct(IDBConnection $connection, PrivateKeyCipher $keyCipher) {
		$this->connection = $connection;
		$this->keyCipher = $keyCipher;
	}

	public function getName(): string {
		return 'Encrypt the stored Social actor private keys';
	}

	public function run(IOutput $output): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'private_key')
			->from(CoreRequestBuilder::TABLE_ACTORS)
			->where($select->expr()->like(
				'private_key',
				$select->createNamedParameter(
					$this->connection->escapeLikeParameter('-----BEGIN') . '%'
				)
			));

		$plain = [];
		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$plain[] = $row;
		}
		$result->closeCursor();

		if ($plain === []) {
			return;
		}

		$output->startProgress(count($plain));
		foreach ($plain as $row) {
			$update = $this->connection->getQueryBuilder();
			$update->update(CoreRequestBuilder::TABLE_ACTORS)
				->set('private_key', $update->createNamedParameter(
					$this->keyCipher->seal((string)$row['private_key'])
				))
				->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
			$update->executeStatement();
			$output->advance();
		}
		$output->finishProgress();
	}
}
