<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\AP;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\ImportService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\Server;

/**
 * The memory of a Delete that arrived before its Create, walked through its
 * six hours on a frozen clock.
 *
 * Within them a Create of the deleted post by its author is dropped — storing
 * it would put back a post its author had taken down. Once they have passed
 * the Create lands, as it does on Mastodon, which remembers a Delete exactly
 * as long. The memory is the deleting actor's: a Delete by somebody else on
 * the same server holds nothing back.
 */
class EarlyDeleteExpiryTest extends InboxTestCase {
	use FreezesTheClock;

	private const CAROL = 'https://early.example/users/carol';
	private const MALLORY = 'https://early.example/users/mallory';
	private const POST = self::CAROL . '/statuses/117000000000000001';

	private FrozenClock $clock;

	protected function hosts(): array {
		return ['early.example'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->forgetEarlyDelete();
		$this->remoteActor(self::CAROL, 'carol');
		$this->remoteActor(self::MALLORY, 'mallory');

		$this->clock = $this->freezeClock(new FrozenClock(time()));
		$container = $this->clockedContainer();
		AP::set($container->get(AP::class));
		$this->importService = $container->get(ImportService::class);
	}

	protected function tearDown(): void {
		AP::set(null);
		$this->thawClock();
		$this->forgetEarlyDelete();
		parent::tearDown();
	}

	private function forgetEarlyDelete(): void {
		Server::get(DurableCache::class)->removeShared(DeleteInterface::EARLY_DELETE_NAMESPACE, hash('sha256', self::POST));
	}

	public function testACreateInTheLastSecondOfTheMemoryIsDropped(): void {
		$deletedAt = $this->clock->getTime();
		$this->assertTrue($this->deliver($this->delete(self::CAROL)));

		$this->clock->set($deletedAt + DeleteInterface::EARLY_DELETE_TTL - 1);
		$this->assertTrue($this->deliver($this->create()));

		$this->assertNull($this->stored(self::POST));
	}

	public function testACreateOnceTheSixHoursHavePassedIsStored(): void {
		$deletedAt = $this->clock->getTime();
		$this->assertTrue($this->deliver($this->delete(self::CAROL)));

		$this->clock->set($deletedAt + DeleteInterface::EARLY_DELETE_TTL);
		$this->assertTrue($this->deliver($this->create()));

		$this->assertNotNull($this->stored(self::POST));
	}

	public function testADeleteByAnotherAccountOfTheServerHoldsNothingBack(): void {
		$this->assertTrue($this->deliver($this->delete(self::MALLORY)));

		$this->clock->advance(1);
		$this->assertTrue($this->deliver($this->create()));

		$this->assertNotNull($this->stored(self::POST));
	}

	private function create(): string {
		return (string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::POST . '/activity',
			'type' => 'Create',
			'actor' => self::CAROL,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => [
				'id' => self::POST,
				'type' => 'Note',
				'attributedTo' => self::CAROL,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [self::CAROL . '/followers'],
				'content' => '<p>taken down before it arrived</p>',
				'published' => '2026-05-01T10:00:00Z',
			],
		]);
	}

	private function delete(string $actor): string {
		return (string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::POST . '#delete-by-' . md5($actor),
			'type' => 'Delete',
			'actor' => $actor,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => ['id' => self::POST, 'type' => 'Tombstone'],
		]);
	}
}
