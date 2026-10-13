<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Renames an account from a process of its own, the way a web request or a
 * cron pass changes a profile while a test's process keeps its own copy of
 * the account's settings: `php rename-elsewhere.php <user id> <name>`.
 */

use OCA\Social\Service\AccountService;
use OCP\Server;

require __DIR__ . '/../bootstrap.php';

Server::get(AccountService::class)->setDisplayName((string)$argv[1], (string)$argv[2]);
