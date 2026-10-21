<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Reference\PostReferenceProvider;

/** Plain-text body shared by Mastodon Web Push notifications. */
final class MastodonPushContent {
	public const BODY_LENGTH = 140;

	public static function body(Stream $post, Person $actor): string {
		$content = $post->getSpoilerText() ?: $post->getContent() ?: $actor->getDescription();

		return PostReferenceProvider::excerpt($content, self::BODY_LENGTH);
	}

	public static function profileBody(Person $actor): string {
		return PostReferenceProvider::excerpt($actor->getDescription(), self::BODY_LENGTH);
	}
}
