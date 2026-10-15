<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Tools\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function cells(): array {
		return [
			'a formula' => ['=HYPERLINK("https://evil.example","x")', '\'=HYPERLINK("https://evil.example","x")'],
			'a plus' => ['+cmd|x', "'+cmd|x"],
			'a minus' => ['-2+3', "'-2+3"],
			'an at' => ['@SUM(A1)', "'@SUM(A1)"],
			'a tab' => ["\t=1", "'\t=1"],
			'a carriage return' => ["\r=1", "'\r=1"],
			'a handle' => ['carol@remote.example', 'carol@remote.example'],
			'a host' => ['bsky.social', 'bsky.social'],
			'a negative number' => ['-3', '-3'],
			'a number with a sign' => ['+1.5', '+1.5'],
			'nothing' => ['', ''],
		];
	}

	#[DataProvider('cells')]
	public function testACellThatWouldRunAsAFormulaIsWrittenAsText(string $value, string $written): void {
		$this->assertSame($written, Csv::neutralise($value));
	}

	#[DataProvider('cells')]
	public function testWhatWasWrittenReadsBackUnchanged(string $value, string $written): void {
		$this->assertSame($value, Csv::restore($written));
	}

	public function testAnApostropheSomebodyTypedIsKept(): void {
		$this->assertSame("'quoted'", Csv::restore("'quoted'"));
		$this->assertSame("'", Csv::restore("'"));
	}

	public function testACellIsQuotedOnlyWhereItHasToBe(): void {
		$this->assertSame('carol@remote.example', Csv::cell('carol@remote.example'));
		$this->assertSame('"Friends, close"', Csv::cell('Friends, close'));
		$this->assertSame('"say ""hi"""', Csv::cell('say "hi"'));
		$this->assertSame('"\'=1,2"', Csv::cell('=1,2'));
	}
}
