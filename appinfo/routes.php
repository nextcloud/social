<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

/**
 * Every route of this app is a `#[FrontpageRoute]` on the controller method it
 * belongs to, except the one below.
 *
 * `/api/v1/accounts/{id}` accepts slashes in `{id}` — a client may hold an
 * actor URI rather than a numeric id — so it also matches
 * `/api/v1/accounts/{account}/featured_tags` and `/api/v1/accounts/{account}/lists`,
 * and has to be offered to the matcher after them. Those two live in
 * `DiscoveryController` and `ListController`, and the server walks the
 * controller directory with a `DirectoryIterator`: the order in which two
 * *different* controllers contribute their attribute routes is whatever the
 * filesystem returns, so no arrangement of attributes can put this one last.
 * `appinfo/routes.php` is loaded after every attribute route of the app, which
 * is exactly the guarantee this route needs, so it stays here.
 *
 * Routes that only have to beat routes of their own controller need nothing
 * special: within one class the attributes are read in method-declaration
 * order.
 */
return [
	'routes' => [
		['name' => 'Api#accountGet', 'url' => '/api/v1/accounts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
	]
];
