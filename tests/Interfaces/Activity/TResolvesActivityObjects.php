<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\ActivityObjectResolver;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * A real ActivityObjectResolver over a store described as a plain id => item
 * map, so a test can say what this instance already knows and let the resolver
 * do the looking up.
 */
trait TResolvesActivityObjects {
	/**
	 * @param array<string, ACore> $stored what the local store holds, by id
	 */
	protected function objectResolverOver(array $stored = []): ActivityObjectResolver {
		$follows = $this->createMock(FollowsRequest::class);
		$follows->method('getById')->willReturnCallback(
			function (string $id) use ($stored) {
				$item = $stored[$id] ?? null;
				if ($item instanceof Follow) {
					return $item;
				}

				throw new FollowNotFoundException($id);
			}
		);

		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(
			function (string $id) use ($stored) {
				$item = $stored[$id] ?? null;
				if ($item instanceof Stream) {
					return $item;
				}

				throw new StreamNotFoundException($id);
			}
		);

		$actions = $this->createMock(ActionsRequest::class);
		$actions->method('getById')->willReturnCallback(
			function (string $id) use ($stored) {
				$item = $stored[$id] ?? null;
				if ($item instanceof ACore && !($item instanceof Follow)
					&& !($item instanceof Stream) && !($item instanceof Person)) {
					return $item;
				}

				throw new ActionDoesNotExistException($id);
			}
		);

		$actors = $this->createMock(CacheActorsRequest::class);
		$actors->method('getFromId')->willReturnCallback(
			function (string $id) use ($stored) {
				$item = $stored[$id] ?? null;
				if ($item instanceof Person) {
					return $item;
				}

				throw new CacheActorDoesNotExistException($id);
			}
		);

		return new ActivityObjectResolver($streams, $follows, $actions, $actors);
	}
}
