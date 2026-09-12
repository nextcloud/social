<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * FEP-044f's `QuoteRequest`: the quoter's server asking the quoted author's
 * server for permission to quote a post.
 *
 * `object` is the post being quoted, `instrument` the post doing the quoting —
 * which is why the quoting post has to exist before the request is sent, and
 * why the answer is an `Accept`/`Reject` of this activity rather than of the
 * post. The `result` of the `Accept` is the approval URI that then rides on the
 * quoting post as `quoteAuthorization`.
 *
 * Mastodon 4.5 sends one for every quote of a remote post and renders the quote
 * inline only once it has been approved.
 */
class QuoteRequest extends ACore implements JsonSerializable {
	public const TYPE = 'QuoteRequest';

	/** the post doing the quoting */
	private string $instrument = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getInstrument(): string {
		return $this->instrument;
	}

	public function setInstrument(string $instrument): self {
		$this->instrument = $instrument;

		return $this;
	}

	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setInstrument($this->validate(ACore::AS_ID, 'instrument', $data, ''));
	}

	/**
	 * Without `instrument` the request says that somebody wants to quote a post
	 * and not which post would be doing the quoting, so there is nothing to
	 * approve: the generic export knows nothing of it.
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getInstrument() !== '') {
			$this->addEntry('instrument', $this->getInstrument());
		}

		return parent::exportAsActivityPub();
	}

	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
