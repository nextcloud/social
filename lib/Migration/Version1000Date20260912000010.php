<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One authorization per (app, account): `social_client_auth`.
 *
 * `social_client` held the whole of an authorization in its own row — one
 * `auth_code`, one `auth_user_id`, one `token` — so an app row could belong to
 * exactly one person at a time. Elk, Phanpy and every other client that
 * registers one app per instance therefore signed the previous user out the
 * moment a second one signed in: `authClient()` blanked the token on purpose,
 * because leaving it would have let the old token act as the new user.
 *
 * The app registration stays where it is. What moves here is everything about
 * *who authorized it*: the code, the token, the scopes granted and the account
 * they were granted to. Unique on (client, account), so re-authorizing replaces
 * rather than accumulating, which is what Mastodon does and what keeps this
 * table the size of the number of people using the instance.
 *
 * The existing authorization on every client row is carried across, so a token
 * in use today goes on working.
 */
class Version1000Date20260912000010 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_client_auth')) {
			return null;
		}

		$table = $schema->createTable('social_client_auth');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		/** the `social_client.id` this authorization is against */
		$table->addColumn('client_id', Types::INTEGER, [
			'notnull' => true,
		]);
		/** the Nextcloud account that granted it */
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		/** their Social handle, which is what `verify_credentials` resolves */
		$table->addColumn('account', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		/** what they granted, as the JSON list the client asked for */
		$table->addColumn('scopes', Types::TEXT, [
			'notnull' => false,
		]);
		/** hashed, and emptied the moment it is exchanged */
		$table->addColumn('code', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		/** hashed; empty until the code is exchanged for it */
		$table->addColumn('token', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);
		/** when the token was last used: what the TTL and the sweep read */
		$table->addColumn('last_update', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// one authorization per app per account: re-authorizing replaces it
		$table->addUniqueIndex(['client_id', 'user_id'], 'social_ca_cu');
		// the two lookups there are, and both are on every request that uses one
		$table->addIndex(['token'], 'social_ca_tok');
		$table->addIndex(['code'], 'social_ca_code');

		return $schema;
	}

	/**
	 * Carries the authorization already on each client row into a row of its
	 * own, so a token in use today goes on working.
	 *
	 * Only rows that have one: an app registered and never authorized has
	 * nothing to move, and a row whose token was already blanked has nothing
	 * worth keeping.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_client_auth') || !$schema->hasTable('social_client')) {
			return;
		}

		$read = $this->connection->getQueryBuilder();
		$read->select('id', 'auth_user_id', 'auth_account', 'auth_scopes', 'auth_code', 'token', 'creation', 'last_update')
			->from('social_client')
			->where($read->expr()->neq('auth_user_id', $read->createNamedParameter('')));

		$moved = 0;
		$cursor = $read->executeQuery();
		while ($row = $cursor->fetch()) {
			if ((string)($row['token'] ?? '') === '' && (string)($row['auth_code'] ?? '') === '') {
				// nothing to carry: no live token and no code to exchange
				continue;
			}

			$write = $this->connection->getQueryBuilder();
			$write->insert('social_client_auth')
				->setValue('client_id', $write->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT))
				->setValue('user_id', $write->createNamedParameter((string)$row['auth_user_id']))
				->setValue('account', $write->createNamedParameter((string)($row['auth_account'] ?? '')))
				->setValue('scopes', $write->createNamedParameter((string)($row['auth_scopes'] ?? '[]')))
				->setValue('code', $write->createNamedParameter((string)($row['auth_code'] ?? '')))
				->setValue('token', $write->createNamedParameter((string)($row['token'] ?? '')))
				->setValue('creation', $write->createNamedParameter($row['creation']))
				->setValue('last_update', $write->createNamedParameter($row['last_update']));

			try {
				$write->executeStatement();
				$moved++;
			} catch (\Throwable $e) {
				// a duplicate can only mean this step already ran
				$output->warning('could not carry an authorization across: ' . $e->getMessage());
			}
		}
		$cursor->closeCursor();

		$output->info($moved . ' OAuth authorization(s) moved to social_client_auth');
	}
}
