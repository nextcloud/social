<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\UserMigration;

use ZipArchive;

/**
 * An account's export from a Mastodon server, written for a test.
 *
 * Shaped after Mastodon's documented export format (Preferences → Import and
 * export): the archive of "Request your archive" — `outbox.json` (an
 * `OrderedCollection` of `Create` and `Announce` activities, attachments
 * named by their path inside the archive under `media_attachments/files/`),
 * `actor.json` with the avatar and header as files beside it, `likes.json`
 * and `bookmarks.json` (collections of post URIs) — and the CSV files of the
 * "Data export" page: `following_accounts.csv`, `blocked_accounts.csv`,
 * `muted_accounts.csv`, `lists.csv`, `bookmarks.csv` and
 * `blocked_domains.csv`. It is not a capture of a live server.
 */
final class MastodonExport {
	public const OLD = 'https://old.roundtrip.example/users/dora';
	/** where the picture of the first post sits in the archive, as Mastodon lays it out */
	public const PICTURE = 'media_attachments/files/110/000/000/000/000/001/original/5f3a9c1e.png';

	private const PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	/**
	 * The archive: three posts of the account's own (one with a picture, one
	 * behind a warning, one unlisted reply to the first), a boost and a
	 * direct message, which are not the account's to bring, and the likes and
	 * bookmarks as collections.
	 *
	 * @param string $picture the PNG the first post carries
	 * @param string $liked the URI of a post the account liked
	 * @param string $bookmarked the URI of a post the account bookmarked
	 */
	public static function archive(string $path, string $picture, string $liked, string $bookmarked): void {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('cannot write ' . $path);
		}

		$zip->addFromString('outbox.json', self::json([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'outbox.json',
			'type' => 'OrderedCollection',
			'totalItems' => 5,
			'orderedItems' => [
				self::create(1, '2024-03-01T09:00:00Z', '<p>First light on the allotment</p>', [self::PUBLIC], [self::OLD . '/followers'], [
					'attachment' => [[
						'type' => 'Document',
						'mediaType' => 'image/png',
						'url' => '/' . self::PICTURE,
						'name' => 'a row of seedlings',
						'blurhash' => 'U00000fQfQfQfQfQfQfQfQfQfQfQ',
						'width' => 40,
						'height' => 30,
					]],
				]),
				self::create(2, '2024-03-02T09:00:00Z', '<p>The finale was a letdown</p>', [self::PUBLIC], [self::OLD . '/followers'], [
					'summary' => 'TV spoilers',
					'sensitive' => true,
				]),
				self::create(3, '2024-03-03T09:00:00Z', '<p>They came up after all</p>', [self::OLD . '/followers'], [self::PUBLIC], [
					'inReplyTo' => self::OLD . '/statuses/1',
				]),
				[
					'id' => self::OLD . '/statuses/4/activity',
					'type' => 'Announce',
					'actor' => self::OLD,
					'published' => '2024-03-04T09:00:00Z',
					'to' => [self::PUBLIC],
					'cc' => [self::OLD . '/followers'],
					'object' => $liked,
				],
				self::create(5, '2024-03-05T09:00:00Z', '<p>Only for you</p>', ['https://roundtrip.example/users/fern'], []),
			],
		]));

		$zip->addFromString('actor.json', self::json([
			'@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
			'id' => self::OLD,
			'type' => 'Person',
			'preferredUsername' => 'dora',
			'name' => 'Dora',
			'summary' => '<p>Allotment diaries</p>',
			'manuallyApprovesFollowers' => true,
			'discoverable' => true,
			'attachment' => [['type' => 'PropertyValue', 'name' => 'Plot', 'value' => '42']],
			'icon' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'avatar.png'],
			'image' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'header.png'],
			'outbox' => 'outbox.json',
			'likes' => 'likes.json',
			'bookmarks' => 'bookmarks.json',
		]));
		$zip->addFromString('likes.json', self::json([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'likes.json',
			'type' => 'OrderedCollection',
			'orderedItems' => [$liked],
		]));
		$zip->addFromString('bookmarks.json', self::json([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'bookmarks.json',
			'type' => 'OrderedCollection',
			'orderedItems' => [$bookmarked],
		]));
		$zip->addFromString(self::PICTURE, $picture);
		$zip->addFromString('avatar.png', $picture);
		$zip->addFromString('header.png', $picture);
		$zip->close();
	}

	public static function followingCsv(string ...$handles): string {
		$lines = ['Account address,Show boosts,Notify on new posts,Languages'];
		foreach ($handles as $handle) {
			$lines[] = $handle . ',true,false,';
		}

		return implode("\n", $lines) . "\n";
	}

	/** Mastodon writes the blocks as a bare list, with no header. */
	public static function blockedCsv(string ...$handles): string {
		return implode("\n", $handles) . "\n";
	}

	/** @param array<string, bool> $mutes handle => whether notifications are hidden */
	public static function mutedCsv(array $mutes): string {
		$lines = ['Account address,Hide notifications'];
		foreach ($mutes as $handle => $hidden) {
			$lines[] = $handle . ',' . ($hidden ? 'true' : 'false');
		}

		return implode("\n", $lines) . "\n";
	}

	/** @param array<string, string[]> $lists title => handles; no header, as Mastodon writes it */
	public static function listsCsv(array $lists): string {
		$lines = [];
		foreach ($lists as $title => $handles) {
			foreach ($handles as $handle) {
				$lines[] = $title . ',' . $handle;
			}
		}

		return implode("\n", $lines) . "\n";
	}

	/** One post URI a line. */
	public static function bookmarksCsv(string ...$uris): string {
		return implode("\n", $uris) . "\n";
	}

	/** One domain a line. */
	public static function blockedDomainsCsv(string ...$domains): string {
		return implode("\n", $domains) . "\n";
	}

	/**
	 * @param string[] $to
	 * @param string[] $cc
	 * @param array<string, mixed> $object
	 * @return array<string, mixed>
	 */
	private static function create(int $n, string $published, string $content, array $to, array $cc, array $object = []): array {
		$id = self::OLD . '/statuses/' . $n;

		return [
			'id' => $id . '/activity',
			'type' => 'Create',
			'actor' => self::OLD,
			'published' => $published,
			'to' => $to,
			'cc' => $cc,
			'object' => array_merge([
				'id' => $id,
				'type' => 'Note',
				'summary' => null,
				'inReplyTo' => null,
				'published' => $published,
				'url' => 'https://old.roundtrip.example/@dora/' . $n,
				'attributedTo' => self::OLD,
				'to' => $to,
				'cc' => $cc,
				'sensitive' => false,
				'content' => $content,
				'contentMap' => ['en' => $content],
				'attachment' => [],
				'tag' => [],
			], $object),
		];
	}

	/** @param array<string, mixed> $data */
	private static function json(array $data): string {
		return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}
