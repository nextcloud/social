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
use Throwable;

/**
 * Rewrites the actor private keys stored as bare PEM into their encrypted
 * form. New keys are written encrypted from the start; this step only exists
 * for rows created before that. Runs on every upgrade and is a no-op once no
 * plaintext key is left, so it needs no marker.
 *
 * A row that cannot be encrypted is reported and left alone rather than
 * allowed to end the upgrade: the plaintext form is still readable, so the
 * actor keeps working and the next upgrade tries again.
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

		$encrypted = 0;
		$failed = [];
		$output->startProgress(count($plain));
		foreach ($plain as $row) {
			// one unusable row must not take the upgrade down with it: seal()
			// throws when the instance secret is missing or has changed, and
			// the admin then has to fix that with the instance in maintenance
			// mode and `occ upgrade` refusing to finish. The key stays as it is
			// — readable, and picked up by the next run of this step.
			try {
				$update = $this->connection->getQueryBuilder();
				$update->update(CoreRequestBuilder::TABLE_ACTORS)
					->set('private_key', $update->createNamedParameter(
						$this->keyCipher->seal((string)$row['private_key'])
					))
					->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
				$update->executeStatement();
				$encrypted++;
			} catch (Throwable $t) {
				$failed[] = (string)$row['id'];
				$output->warning(
					'could not encrypt the private key of the Social actor ' . $row['id']
					. ': ' . $t->getMessage()
				);
			}
			$output->advance();
		}
		$output->finishProgress();

		if ($encrypted > 0) {
			$output->info('Encrypted the private key of ' . $encrypted . ' Social actor(s)');
		}

		if ($failed !== []) {
			$output->warning(
				'The private key of ' . count($failed) . ' Social actor(s) is still stored in '
				. 'plaintext: ' . implode(', ', $failed) . '. They keep working; check that the '
				. 'instance secret is readable, and the next upgrade will try again.'
			);
		}
	}
}
