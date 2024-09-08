<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OC\SystemConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Db\ExtendedQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class SocialCoreQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialCoreQueryBuilder extends ExtendedQueryBuilder {
	protected IURLGenerator $urlGenerator;
	private ?Person $viewer = null;

	public function __construct(
		IDBConnection $connection, SystemConfig $systemConfig, LoggerInterface $logger, IURLGenerator $urlGenerator
	) {
		parent::__construct($connection, $systemConfig, $logger);

		$this->urlGenerator = $urlGenerator;
	}


	public function hasViewer(): bool {
		return ($this->viewer !== null);
	}

	public function setViewer(Person $viewer): void {
		$this->viewer = $viewer;
	}

	public function getViewer(): Person {
		return $this->viewer;
	}

	public function prim(string $id): string {
		if ($id === '' || substr($id, 0, 4) !== 'http') {
			return '';
		}

		return md5($id);
	}
}
