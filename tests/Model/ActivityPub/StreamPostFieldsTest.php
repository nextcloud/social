<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\TestCase;

/**
 * `tag`, `language`, `updated`, `quote` and `quoteAuthorization` read back out
 * of `social_stream`.
 *
 * All five used to be readable only by `json_decode`ing the stored wire object
 * once per timeline row, because none of them had a column;
 * `Version1000Date20260912000003` gave each one its own. Two kinds of row exist
 * on an upgraded instance and both have to work: one written before that step,
 * whose columns are empty and whose `source` still carries everything, and one
 * written after it. The schema change lands during `occ upgrade` and the
 * `BackfillStreamPostFields` repair step runs after it, so an instance really
 * does serve reads with both shapes in the table at once.
 */
class StreamPostFieldsTest extends TestCase {
	private const TAGS = [
		['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
		['type' => 'Hashtag', 'href' => 'https://cloud.example/tags/nextcloud', 'name' => '#Nextcloud'],
	];

	private const QUOTED = 'https://remote.example/users/bob/statuses/7';
	private const APPROVAL = 'https://remote.example/users/bob/statuses/7/quote_authorizations/x';

	/** A row as it was stored before the columns existed: everything in the JSON. */
	private function legacyRow(): array {
		return [
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'type' => 'Note',
			'source' => json_encode([
				'id' => 'https://cloud.example/apps/social/@alice/1',
				'tag' => self::TAGS,
				'language' => 'pt-BR',
				'updated' => '2026-09-12T10:00:00Z',
				'quote' => self::QUOTED,
				'quoteAuthorization' => self::APPROVAL,
			]),
		];
	}

	/** The same post as the columns hold it, with no wire object at all. */
	private function migratedRow(): array {
		return [
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'type' => 'Note',
			'source' => '',
			'tags' => json_encode(self::TAGS),
			'language' => 'pt-BR',
			'updated' => '2026-09-12 10:00:00',
			'quote' => self::QUOTED,
			'quote_authorization' => self::APPROVAL,
		];
	}

	private function assertCarriesEverything(Note $note): void {
		$this->assertSame(self::TAGS, $note->getTags());
		$this->assertSame('pt-BR', $note->getLanguage());
		$this->assertSame('2026-09-12T10:00:00Z', $note->getUpdated());
		$this->assertSame(self::QUOTED, $note->getQuote());
		$this->assertSame(self::APPROVAL, $note->getQuoteAuthorization());
	}

	public function testARowWrittenBeforeTheMigrationStillExposesAllFive(): void {
		$note = new Note();
		$note->importFromDatabase($this->legacyRow());

		$this->assertCarriesEverything($note);
	}

	public function testARowWrittenAfterTheMigrationNeedsNoWireObject(): void {
		$note = new Note();
		$note->importFromDatabase($this->migratedRow());

		$this->assertCarriesEverything($note);
	}

	public function testTheColumnsWinOverAWireObjectThatDisagrees(): void {
		// the wire object is re-snapshotted by the same statement that writes
		// the columns, so the two agree in practice; when they do not, it is
		// because something wrote the columns later, and the columns are what a
		// query would have filtered on
		$row = $this->migratedRow();
		$row['source'] = json_encode([
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'language' => 'de',
			'quote' => 'https://remote.example/users/carol/statuses/9',
		]);

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('pt-BR', $note->getLanguage());
		$this->assertSame(self::QUOTED, $note->getQuote());
	}

	public function testEachFieldFallsBackOnItsOwn(): void {
		// a post may legitimately have four of the five empty, so one populated
		// column must not suppress the fallback for the others
		$row = $this->legacyRow();
		$row['language'] = 'fr';

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('fr', $note->getLanguage());
		$this->assertSame(self::TAGS, $note->getTags());
		$this->assertSame(self::QUOTED, $note->getQuote());
		$this->assertSame(self::APPROVAL, $note->getQuoteAuthorization());
	}

	public function testAPostThatWasNeverEditedHasNoEditTime(): void {
		$row = $this->migratedRow();
		$row['updated'] = null;

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('', $note->getUpdated());
		$this->assertArrayNotHasKey('updated', $note->exportAsActivityPub());
	}

	public function testTheEditTimeComesBackAsTheInstantItNames(): void {
		// the column is a datetime holding UTC, so the exact text a peer sent
		// does not survive but the moment does — written the way this app
		// writes its own edits, which is what PostService::editPost() emits
		$row = $this->migratedRow();
		$row['updated'] = '2026-09-12 08:00:00';

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('2026-09-12T08:00:00Z', $note->getUpdated());
	}

	public function testAnUnreadableEditTimeIsNoEditTime(): void {
		$row = $this->migratedRow();
		$row['updated'] = 'not a date';

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('', $note->getUpdated());
	}

	public function testTheLanguageColumnIsNormalisedTheWayTheWireObjectIs(): void {
		// setLanguage() runs every value through normalizeLanguage(), so a
		// column written by an older shape of this app cannot federate a tag
		// that is not one
		$row = $this->migratedRow();
		$row['language'] = 'pt_br';

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('pt-BR', $note->getLanguage());
	}

	public function testWhatComesOutOfTheColumnsIsWhatFederates(): void {
		$note = new Note();
		$note->importFromDatabase($this->migratedRow());
		$wire = $note->exportAsActivityPub();

		$this->assertSame(self::TAGS, $wire['tag']);
		$this->assertSame(self::QUOTED, $wire['quote']);
		$this->assertSame(self::APPROVAL, $wire['quoteAuthorization']);
		$this->assertSame('2026-09-12T10:00:00Z', $wire['updated']);
	}

	public function testEmojiStillComeFromTheWireObject(): void {
		// an emoji tag carries an `icon`, and AS_TAGS validation keeps only the
		// type, href and name — so the `tags` column holds the post's tags as
		// the model holds them, which is already without the icons
		$row = $this->migratedRow();
		$row['source'] = json_encode([
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'tag' => [
				[
					'type' => 'Emoji',
					'name' => ':party:',
					'icon' => ['url' => 'https://remote.example/emoji/party.gif'],
				],
			],
		]);

		$note = new Note();
		$note->importFromDatabase($row);

		$this->assertSame('party', $note->getEmojis()[0]['shortcode']);
		// and the tags still come from the column, not from that same array
		$this->assertSame(self::TAGS, $note->getTags());
	}
}
