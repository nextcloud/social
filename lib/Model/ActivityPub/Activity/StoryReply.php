<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

/**
 * A sentence sent back to whoever posted a story.
 *
 * @see StoryInteraction for the shape and why it is Pixelfed's
 */
class StoryReply extends StoryInteraction {
	public const TYPE = 'Story:Reply';
}
