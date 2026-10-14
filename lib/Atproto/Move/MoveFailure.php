<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Exceptions\MoveErrorException;
use Throwable;

/**
 * A server a move talks to did not answer or refused, with the code the
 * page explains (§13.5); an `AtprotoException` to every caller that
 * catches those. What the person can act on is a `MoveErrorException`.
 */
class MoveFailure extends AtprotoException {
	/** the code of anything that names none */
	public const FAILED = 'move-failed';

	public function __construct(
		public readonly string $errorCode,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * The code of a failure: its own, or the first one a cause it wraps
	 * names; a refusal of the PLC directory is `plc-refused`.
	 */
	public static function codeOf(Throwable $e): string {
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof self || $cause instanceof MoveErrorException) {
				return $cause->errorCode;
			}
			if ($cause instanceof AtprotoKeysSealedException) {
				return AtprotoKeysSealedException::ERROR_CODE;
			}
			if ($cause instanceof AtprotoPlcRefusedException) {
				return 'plc-refused';
			}
		}

		return self::FAILED;
	}
}
