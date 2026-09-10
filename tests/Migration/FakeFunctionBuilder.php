<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

/**
 * The one SQL function the repair steps ask for. See FakeQueryBuilder for why
 * this implements no interface.
 */
class FakeFunctionBuilder {
	public function count($count = '', $alias = ''): string {
		return 'COUNT(' . $count . ')' . ($alias === '' ? '' : ' AS ' . $alias);
	}
}
