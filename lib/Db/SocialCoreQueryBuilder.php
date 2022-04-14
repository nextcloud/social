<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Db;


use daita\MySmallPhpTools\Db\ExtendedQueryBuilder;
use OC\SystemConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
	private int $chunk = 0;

	public function __construct(
		IDBConnection $connection, SystemConfig $systemConfig, LoggerInterface $logger, IURLGenerator $urlGenerator
	) {
		parent::__construct($connection, $systemConfig, $logger);

		$this->urlGenerator = $urlGenerator;
	}

	public function setChunk(int $chunk): self {
		$this->chunk = $chunk;
		$this->inChunk();

		return $this;
	}

	public function getChunk(): int {
		return $this->chunk;
	}

	/**
	 * Limit the request to a chunk
	 */
	public function inChunk(string $alias = '', ?ICompositeExpression $expr = null): void {
		if ($this->getChunk() === 0) {
			return;
		}

		if ($expr !== null) {
			$expr->add($this->exprLimitToDBFieldInt('chunk', $this->getChunk(), $alias));

			return;
		}
		$this->limitToDBFieldInt('chunk', $this->getChunk(), $alias);
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
		if ($id === '') {
			return '';
		}

		return hash('sha512', $id);
	}
}

