<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The full actor documents this instance serves, pinned as golden files
 * (see GoldenFile; `UPDATE_GOLDEN=1` regenerates them).
 *
 * Each actor goes the way a served one goes: the `social_actors` row through
 * the real ActorsRequest, into the cache with its own document as `source`,
 * and back out of the cache row — so a fact that only survives that round
 * trip through `source` (locked, fields, movedTo, alsoKnownAs) is pinned as
 * served, not as set.
 */
class OutboundActorGoldenTest extends TestCase {
	use TActivityPubMocks;
	use TFederationWorld;

	protected function setUp(): void {
		$this->installActivityPub(self::$cloud);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testALocalPerson(): void {
		GoldenFile::assertMatches('person-local', GoldenFile::wire($this->localActor()));
	}

	public function testALockedPersonSaysItApprovesFollowersByHand(): void {
		$actor = $this->localActor(['locked' => 1, 'discoverable' => 0, 'indexable' => 0]);

		GoldenFile::assertMatches('person-locked', GoldenFile::wire($actor));
	}

	public function testAMovedPersonNamesWhereItWentAndWhereItCameFrom(): void {
		$actor = $this->localActor([
			'moved_to' => 'https://new.example/users/alice',
			'also_known_as' => json_encode(['https://old.example/users/alice']),
		]);

		GoldenFile::assertMatches('person-moved', GoldenFile::wire($actor));
	}

	public function testAPersonWithProfileFieldsSendsThemAsPropertyValues(): void {
		$actor = $this->localActor([
			'fields' => json_encode([
				['name' => 'Website', 'value' => 'https://alice.example'],
				['name' => 'Pronouns', 'value' => 'she/her'],
			]),
		]);

		GoldenFile::assertMatches('person-fields', GoldenFile::wire($actor));
	}

	public function testAnAutomatedAccountIsAService(): void {
		GoldenFile::assertMatches('service-bot', GoldenFile::wire($this->localActor(['bot' => 1])));
	}

	/**
	 * A channel is a `Group` attributed to the person who runs it, which is
	 * what PeerTube looks for before it accepts a video the channel owns.
	 */
	public function testAChannelIsAGroupAttributedToItsOwner(): void {
		$actor = $this->localActor([
			'preferred_username' => 'alice_videos',
			'user_id' => 'channel/alice_videos',
			'name' => 'Alice makes videos',
			'actor_type' => 'Group',
		], static function (Person $channel): void {
			$channel->setAttributedToActors([['type' => 'Person', 'id' => self::$social . '@alice']]);
		});

		GoldenFile::assertMatches('group-channel', GoldenFile::wire($actor));
	}

	public function testTheInstanceActor(): void {
		$service = $this->realService(InstanceActorService::class, [
			\OCA\Social\Service\ConfigService::class => $this->config(),
			\OCP\IURLGenerator::class => $this->urls(),
			'logger' => new NullLogger(),
		]);

		GoldenFile::assertMatches('application-instance-actor', GoldenFile::wire($service->getActor()));
	}
}
