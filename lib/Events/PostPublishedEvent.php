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
 * A post was written on this instance and sent.
 *
 * The app had no events at all: nothing else on a Nextcloud server could know
 * that somebody had posted, so an Activity entry, a Talk message, a Flow rule
 * or an integration of somebody's own had nothing to listen to and no way to
 * ask. Every such integration had to poll the API as a client, from inside the
 * same server.
 *
 * **After the post is stored and addressed**, so a listener sees what was
 * actually published — its id, its visibility, its attachments — rather than
 * what a request asked for. A listener must not assume the post has been
 * *delivered*: delivery is a queue, and a listener that waited for it would be
 * waiting on other people's servers.
 *
 * Local posts only. Everything that arrives from elsewhere arrives through the
 * inbox, in volume, and an event per federated post would be a firehose nobody
 * asked for — that is a separate event with a separate name if anybody ever
 * wants it.
 */
class PostPublishedEvent extends Event {
	public function __construct(
		private Stream $post,
	) {
		parent::__construct();
	}

	public function getPost(): Stream {
		return $this->post;
	}

	/** The account that wrote it, as an ActivityPub actor id. */
	public function getAuthorId(): string {
		return $this->post->getAttributedTo();
	}
}
