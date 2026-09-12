<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\TestCase;

/**
 * lib/Db compares `$qb->getType()` against Doctrine's statement-type constants
 * in 28 places, all on read paths. tests/stub.phpstub declared
 * `QueryBuilder::SELECT` as the string 'select' while Doctrine assigns the int
 * 0, so static analysis checked every one of those comparisons against a value
 * that cannot occur at runtime -- the comparisons were right and the thing
 * verifying them was wrong.
 *
 * The stub is not loaded by PHPUnit, so it is read rather than reflected.
 */
class QueryBuilderTypeConstantsTest extends TestCase {
	private const STUB = __DIR__ . '/../stub.phpstub';

	/** The values Doctrine\DBAL\Query\QueryBuilder actually assigns. */
	private const REAL = [
		'SELECT' => '0',
		'DELETE' => '1',
		'UPDATE' => '2',
		'INSERT' => '3',
	];

	public function testTheStubMatchesDoctrine(): void {
		$stub = (string)file_get_contents(self::STUB);

		foreach (self::REAL as $name => $value) {
			$this->assertMatchesRegularExpression(
				'/public const ' . $name . ' = ' . $value . ';/',
				$stub,
				'the stub gives QueryBuilder::' . $name . ' a value Doctrine never assigns, '
				. 'so psalm verifies the getType() comparisons against fiction'
			);
		}
	}

	public function testTheStubDoesNotInvertTheConnectionHierarchy(): void {
		$stub = (string)file_get_contents(self::STUB);

		$this->assertStringNotContainsString(
			'class Connection extends ConnectionAdapter',
			$stub,
			'core has these the other way round: ConnectionAdapter wraps Connection'
		);
	}
}
