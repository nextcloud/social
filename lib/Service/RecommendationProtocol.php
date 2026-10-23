<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;

/** Identifies the protocol that supplied a stored recommendation candidate. */
final class RecommendationProtocol {
	public const ACTIVITYPUB = 'activitypub';
	public const ATPROTO = 'atproto';

	public static function of(Stream $post): string {
		return BlueskyIds::isPostId($post->getId()) ? self::ATPROTO : self::ACTIVITYPUB;
	}
}
