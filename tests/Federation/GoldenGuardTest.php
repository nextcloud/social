<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guards around the golden files.
 *
 * - Keys this app deliberately stopped sending never come back, in any
 *   document on the wire or in the client format they were retired from.
 * - The JSON-LD context documents shipped in `context/` are pinned by hash:
 *   signature normalisation resolves `@context` only from these copies, so
 *   an edit to one silently changes what every Linked Data signature, ours
 *   and everybody else's, is computed over.
 * - Every golden file is one a test still writes, so a renamed test cannot
 *   leave a stale golden that pins nothing.
 */
class GoldenGuardTest extends TestCase {
	/**
	 * The keys and where they were retired from (see git history):
	 * `hashtags` — this app's own bare-string tag list, superseded by `tag` on
	 * the wire and `tags` in the client format (4afd1724f); the bookkeeping a
	 * post carries only with complete details — `details`, `action`, `cache`,
	 * `publishedTime`, `actor_info`, `source`, `viewerLink` — which is no
	 * peer's business (0db8ca419, the outbox and featured exports).
	 */
	private const RETIRED_ON_THE_WIRE = [
		'hashtags', 'details', 'action', 'cache', 'publishedTime', 'actor_info', 'source', 'viewerLink',
	];

	/**
	 * The SHA-256 of each shipped context document. A deliberate update
	 * changes the hash here in the same commit.
	 */
	private const CONTEXT_HASHES = [
		'w3id.org.identity.v1.json' => '72929a027b0bb9a2743a687be7838e7116bfd4a8238b28bc16022d3510655a88',
		'w3id.org.security.v1.json' => 'a918361acb68f8c015bc1cb9101f255a6be41c74c27aeda7082033617b9d63e9',
		'www.w3.org.ns.activitystreams.json' => 'a2e232a3f70386e3ed87b85a1e06a2c47a737167c080b3e7fca27bfe30e9deef',
	];

	/** @return iterable<string, array{string}> */
	public static function goldens(): iterable {
		foreach (glob(GoldenFile::DIR . '/*.json') ?: [] as $path) {
			yield basename($path, '.json') => [$path];
		}
	}

	#[DataProvider('goldens')]
	public function testNoRetiredKeyIsBackOnTheWire(string $path): void {
		$document = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame([], array_values(array_intersect(self::keysOf($document), self::RETIRED_ON_THE_WIRE)));
	}

	#[DataProvider('goldens')]
	public function testEveryGoldenIsWrittenByATest(string $path): void {
		$name = basename($path, '.json');
		$sources = '';
		foreach (glob(__DIR__ . '/*GoldenTest.php') ?: [] as $test) {
			$sources .= file_get_contents($test);
		}

		$this->assertStringContainsString("'" . $name . "'", $sources, $name . '.json is pinned by no test');
	}

	/**
	 * `attachment` and `hashtags` were taken out of the client format (378f67476,
	 * 4afd1724f): Mastodon's entity carries `media_attachments` and `tags`, and a
	 * second copy under a key it does not define only confused clients.
	 */
	public function testTheClientFormatCarriesNeitherAttachmentNorHashtags(): void {
		$note = new Note();
		$note->importFromDatabase([
			'id' => 'https://cloud.example/apps/social/@alice/1', 'type' => 'Note',
			'hashtags' => '["garden"]',
			'attachments' => json_encode([['id' => '4', 'type' => 'image', 'url' => 'https://cloud.example/m/4.png']]),
		]);
		$note->setCompleteDetails(true);
		$note->setExportFormat(ACore::FORMAT_LOCAL);

		$status = json_decode((string)json_encode($note), true);

		$this->assertArrayNotHasKey('attachment', $status);
		$this->assertArrayNotHasKey('hashtags', $status);
		$this->assertArrayHasKey('media_attachments', $status);
		$this->assertArrayHasKey('tags', $status);
	}

	public function testTheShippedContextDocumentsAreTheOnesPinned(): void {
		$shipped = [];
		foreach (glob(dirname(__DIR__, 2) . '/context/*.json') ?: [] as $path) {
			$shipped[basename($path)] = hash_file('sha256', $path);
		}
		ksort($shipped);

		$this->assertSame(self::CONTEXT_HASHES, $shipped, 'a shipped JSON-LD context changed: every LD signature is computed over it');
	}

	/** Every context the signature normaliser resolves is one of the pinned files, and nothing else is. */
	public function testTheNormaliserResolvesExactlyThePinnedContexts(): void {
		$resolved = array_values(SignatureService::LOCAL_CONTEXTS);
		sort($resolved);

		$this->assertSame(array_keys(self::CONTEXT_HASHES), $resolved);
	}

	/** @return list<string> every key at any depth */
	private static function keysOf(array $document): array {
		$keys = [];
		foreach ($document as $key => $value) {
			if (is_string($key)) {
				$keys[] = $key;
			}
			if (is_array($value)) {
				$keys = array_merge($keys, self::keysOf($value));
			}
		}

		return array_values(array_unique($keys));
	}
}
