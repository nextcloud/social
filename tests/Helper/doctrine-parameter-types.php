<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The three Doctrine classes `OCP\DB\QueryBuilder\IQueryBuilder`'s parameter
 * constants are defined in terms of.
 *
 * `doctrine/dbal` is the server's dependency, not this app's, so the standalone
 * suite has none of it — and PHP resolves `IQueryBuilder::PARAM_INT` by
 * evaluating `ParameterType::INTEGER`, which means any code path that binds a
 * typed parameter is unreachable from a test, not merely untyped in one. That
 * is why `BackfillRemoteVisibility` has no test: it binds `PARAM_INT`.
 *
 * The values are not the point and are never compared against anything — the
 * recording query builder keeps whatever it is handed. Declared in their real
 * namespaces so nothing has to be aliased, and guarded so a real DBAL on the
 * include path always wins.
 *
 * Deliberately not enums, unlike DBAL 4's own: a class constant is all that is
 * needed to make `IQueryBuilder`'s constants resolvable, and it keeps this stub
 * working whichever major version of DBAL the server is carrying.
 */

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class) && !enum_exists(ParameterType::class)) {
		class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BOOLEAN = 5;
			public const BINARY = 16;
			public const ASCII = 17;
		}
	}

	if (!class_exists(ArrayParameterType::class) && !enum_exists(ArrayParameterType::class)) {
		class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
			public const ASCII = 117;
			public const BINARY = 116;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class)) {
		class Types {
			public const BOOLEAN = 'boolean';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const TIME_MUTABLE = 'time';
			public const TIME_IMMUTABLE = 'time_immutable';
			public const JSON = 'json';
		}
	}
}
