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

namespace OCA\Social\Command;


use daita\MySmallPhpTools\Exceptions\CacheItemNotFoundException;
use OC\Core\Command\Base;
use OCA\Social\AP;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;


class ExtendedBase extends Base {

	/** @var OutputInterface */
	protected $output;

	/** @var bool */
	protected $asJson = false;


	/**
	 * @param Person $actor
	 */
	protected function outputActor(Person $actor) {
		if ($this->asJson) {
			$this->output->writeln(json_encode($actor, JSON_PRETTY_PRINT));
		}

		$this->output->writeln('<info>Account</info>: ' . $actor->getAccount());
		$this->output->writeln('<info>Id</info>: ' . $actor->getId());
		$this->output->writeln('');

	}


	/**
	 * @param Stream[] $streams
	 */
	protected function outputStreams(array $streams) {
		if ($this->asJson) {
			$this->output->writeln(json_encode($streams, JSON_PRETTY_PRINT));
		}

		$table = new Table($this->output);
		$table->setHeaders(['Id', 'Source', 'Type', 'Author', 'Content']);
		$table->render();
		$this->output->writeln('');

		foreach ($streams as $item) {
			$objectId = $item->getObjectId();
			$cache = $item->getCache();
			$content = '';
			$author = '';
			if ($objectId !== '' && $cache->hasItem($objectId)) {
				try {
					$cachedObject = $cache->getItem($objectId)
										  ->getObject();

					/** @var Stream $cachedItem */
					$cachedItem = AP::$activityPub->getItemFromData($cachedObject);
					$content = $cachedItem->getContent();
					$author = $cachedItem->getActor()
										 ->getAccount();
				} catch (CacheItemNotFoundException $e) {
				} catch (ItemUnknownException $e) {
				} catch (RedundancyLimitException $e) {
				} catch (SocialAppConfigException $e) {
				}
			} else {
				$content = $item->getContent();
				$author = $item->getActor()
							   ->getAccount();
			}

			$table->appendRow(
				[
					'<comment>' . $item->getId() . '</comment>',
					'<info>' . $item->getActor()
									->getAccount() . '</info>',
					$item->getType(),
					'<info>' . $author . '</info>',
					$content,
				]
			);
		}
	}


	/**
	 * @param Stream $stream
	 */
	protected function outputStream(Stream $stream) {
		$actor = $stream->getActor();
		$this->output->writeln('id: <comment>' . $stream->getId() . '</comment>');
		$this->output->writeln(
			'author: <comment>' . $actor->getAccount() . '</comment>'
		);
		$this->output->writeln('type: <info>' . $stream->getType() . '</info>');
	}

}
