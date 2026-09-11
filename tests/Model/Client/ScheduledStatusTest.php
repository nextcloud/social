<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\ScheduledStatus;
use PHPUnit\Framework\TestCase;

/**
 * The entity a client gets in place of a Status, and the row it is stored as.
 *
 * A client tells the two entities apart by their keys, so the shape here is
 * not cosmetic: a ScheduledStatus that decodes as a Status tells its user the
 * post is already published.
 */
class ScheduledStatusTest extends TestCase {
	private string $timezone;

	protected function setUp(): void {
		// not UTC, so that a time formatted in the server's own zone is a
		// different string from the same instant formatted in UTC
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('Asia/Tokyo');
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}

	private function scheduled(): ScheduledStatus {
		return (new ScheduledStatus())
			->setId(12)
			->setActorId('https://social.example/@alice')
			->setScheduledAt(1760000000)
			->setParams(['text' => 'later', 'visibility' => 'public']);
	}

	public function testTheEntityIsExactlyMastodonsFourKeys(): void {
		$this->assertSame(
			['id', 'scheduled_at', 'params', 'media_attachments'],
			array_keys($this->scheduled()->jsonSerialize())
		);
	}

	/** Every id Mastodon shows a client is a string, including this one. */
	public function testTheIdIsAString(): void {
		$this->assertSame('12', $this->scheduled()->jsonSerialize()['id']);
	}

	/** UTC, whatever the server's own timezone is, and to the millisecond. */
	public function testTheTimeIsTheIso8601AClientParses(): void {
		$scheduled = $this->scheduled()->setScheduledAt((int)strtotime('2025-10-09T07:33:20Z'));

		$this->assertSame('2025-10-09T07:33:20.000Z', $scheduled->jsonSerialize()['scheduled_at']);
	}

	/**
	 * The account the post belongs to is carried so the row can be scoped to
	 * it, and is not part of what a client is shown.
	 */
	public function testTheOwnerIsNotPartOfTheEntity(): void {
		$this->assertStringNotContainsString(
			'alice', (string)json_encode($this->scheduled()->jsonSerialize())
		);
	}

	/**
	 * `params` is echoed back to whoever asks for the entity, so a client that
	 * could put arbitrary keys in it would have a place to park arbitrary
	 * content under this account's name.
	 */
	public function testParamsKeepsOnlyTheKeysMastodonDefines(): void {
		$scheduled = (new ScheduledStatus())->setParams(['text' => 'hi', 'payload' => 'anything']);

		$this->assertArrayNotHasKey('payload', $scheduled->getParams());
		$this->assertSame('hi', $scheduled->getParams()['text']);
	}

	/**
	 * A client that declares a member non-optional cannot decode an entity that
	 * leaves it out, and "the client did not ask for a poll" is an answer a
	 * scheduling UI draws.
	 */
	public function testEveryDefinedKeyIsSentEvenWhenTheClientSaidNothing(): void {
		$params = (new ScheduledStatus())->setParams([])->getParams();

		foreach (['text', 'media_ids', 'poll', 'in_reply_to_id', 'sensitive',
			'spoiler_text', 'visibility', 'language', 'scheduled_at'] as $key) {
			$this->assertArrayHasKey($key, $params);
			$this->assertNull($params[$key]);
		}
	}

	public function testARowIsReadBackAsWhatWasStored(): void {
		$scheduled = (new ScheduledStatus())->importFromDatabase([
			'id' => 12,
			'actor_id' => 'https://social.example/@alice',
			'scheduled_at' => '2025-10-09 07:33:20',
			'params' => (string)json_encode(['text' => 'later', 'visibility' => 'unlisted']),
			'creation' => '2025-10-01 09:00:00',
		]);

		$this->assertSame(12, $scheduled->getId());
		$this->assertSame('https://social.example/@alice', $scheduled->getActorId());
		$this->assertSame((int)strtotime('2025-10-09 07:33:20'), $scheduled->getScheduledAt());
		$this->assertSame('later', $scheduled->paramText());
		$this->assertSame('unlisted', $scheduled->paramString('visibility'));
	}

	/**
	 * A row whose `params` never became JSON — truncated on the way in, or
	 * written by something else — must not take the whole listing down with it.
	 */
	public function testARowWithUnreadableParamsIsStillAnEntity(): void {
		$scheduled = (new ScheduledStatus())->importFromDatabase([
			'id' => 12, 'actor_id' => 'x', 'scheduled_at' => '', 'params' => 'not json', 'creation' => '',
		]);

		$this->assertSame('', $scheduled->paramText());
		$this->assertSame(0, $scheduled->getScheduledAt());
	}

	public function testTheMediaIdsArePublishableIntegersWhateverTheClientSent(): void {
		$scheduled = (new ScheduledStatus())->setParams(['media_ids' => ['7', 8, '', 'nine', '0']]);

		$this->assertSame([7, 8], $scheduled->paramMediaIds());
	}

	public function testABooleanParamIsOnlyTrueWhenItReallyIs(): void {
		$scheduled = (new ScheduledStatus())->setParams(['sensitive' => 'yes']);

		$this->assertFalse($scheduled->paramBool('sensitive'));
		$this->assertTrue((new ScheduledStatus())->setParams(['sensitive' => true])->paramBool('sensitive'));
	}

	public function testAnEmptyPollIsNoPoll(): void {
		$this->assertNull((new ScheduledStatus())->setParams(['poll' => []])->paramPoll());
		$this->assertSame(
			['options' => ['a', 'b']],
			(new ScheduledStatus())->setParams(['poll' => ['options' => ['a', 'b']]])->paramPoll()
		);
	}

	public function testWhatIsWrittenToTheColumnIsWhatIsReadBackFromIt(): void {
		$exported = $this->scheduled()->exportParams();

		$read = (new ScheduledStatus())->importFromDatabase([
			'id' => 12, 'actor_id' => 'x', 'scheduled_at' => '', 'params' => $exported, 'creation' => '',
		]);

		$this->assertSame($this->scheduled()->getParams(), $read->getParams());
	}

	/**
	 * The attachments ride as the same entity a published status carries, so a
	 * client draws the thumbnails of a waiting post with the code it has.
	 */
	public function testTheAttachmentsAreOnTheEntityAndNotJustTheirIds(): void {
		$attachment = (new MediaAttachment())->setId('7');
		$scheduled = $this->scheduled()->setMediaAttachments([$attachment]);

		$this->assertSame([$attachment], $scheduled->jsonSerialize()['media_attachments']);
	}
}
