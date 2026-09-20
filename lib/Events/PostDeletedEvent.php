<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Events;

use OCA\Social\Model\ActivityPub\Stream;
use OCP\EventDispatcher\Event;

/**
 * A post written on this instance was deleted.
 *
 * The twin of `PostPublishedEvent`, and the reason that one is not enough on
 * its own: anything that copied a post somewhere else — an index, a mirror, an
 * Activity entry — needs to be told when it goes, or it keeps showing
 * something the author took back.
 *
 * Dispatched **after** the row is gone and the `Delete` is queued, with the
 * post as it last was: a listener that wants to remove its own copy needs the
 * id, and nothing else can hand it over once the row is deleted.
 */
class PostDeletedEvent extends Event {
	public function __construct(
		private Stream $post,
	) {
		parent::__construct();
	}

	public function getPost(): Stream {
		return $this->post;
	}

	public function getAuthorId(): string {
		return $this->post->getAttributedTo();
	}
}
