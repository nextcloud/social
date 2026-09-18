<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * PKCE (RFC 7636) on an authorization: `code_challenge` and its method.
 *
 * The discovery document has advertised `code_challenge_methods_supported`
 * since it was written, while nothing on either endpoint read a challenge — a
 * Mastodon 4.3 client that reads the document sends one and gets a code that
 * any holder can exchange. The challenge belongs to the single authorization
 * the code names, so it lives beside the code.
 *
 * Both columns are nullable: an authorization made without PKCE has no
 * challenge, and an empty challenge is what `exchangeCode()` reads as "this
 * grant was not bound to a verifier".
 */
class Version1000Date20260918000010 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_client_auth')) {
			return null;
		}

		$table = $schema->getTable('social_client_auth');
		$changed = false;

		if (!$table->hasColumn('code_challenge')) {
			// the base64url of a SHA-256 digest is 43 characters; the column is
			// wider so a future method is not a schema change
			$table->addColumn('code_challenge', Types::STRING, [
				'notnull' => false,
				'length' => 128,
				'default' => '',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('code_challenge_method')) {
			$table->addColumn('code_challenge_method', Types::STRING, [
				'notnull' => false,
				'length' => 16,
				'default' => '',
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
