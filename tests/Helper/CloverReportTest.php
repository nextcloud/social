<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The coverage floor in CI is only as good as the arithmetic behind it: the
 * unit and the integration suite each cover a different half of lib/ (Db is
 * almost all integration), so a merge that added the two totals, or counted a
 * method declaration as a line, would report a number neither run produced.
 */
class CloverReportTest extends TestCase {
	private const ROOT = '/srv/apps/social';

	/** @param array<string, array<int, int>> $files path below the root => line => hit count */
	private static function clover(array $files, string $root = self::ROOT): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project><package name="OCA\Social">';
		foreach ($files as $path => $lines) {
			$xml .= '<file name="' . $root . '/' . $path . '"><line num="1" type="method" name="run" count="1"/>';
			foreach ($lines as $number => $count) {
				$xml .= '<line num="' . $number . '" type="stmt" count="' . $count . '"/>';
			}
			$xml .= '</file>';
		}

		return $xml . '</package></project></coverage>';
	}

	public function testAStatementCoveredByEitherReportCountsOnceAsCovered(): void {
		$report = new CloverReport(self::ROOT);
		$report->addXml(self::clover(['lib/Db/Request.php' => [10 => 0, 11 => 3, 12 => 0]]));
		$report->addXml(self::clover(['lib/Db/Request.php' => [10 => 2, 11 => 1, 12 => 0]]));

		$this->assertSame(['covered' => 2, 'statements' => 3], $report->total());
	}

	public function testOnlyStatementLinesCount(): void {
		$report = new CloverReport(self::ROOT);
		$report->addXml(self::clover(['lib/AP.php' => [5 => 1]]));

		$this->assertSame(['covered' => 1, 'statements' => 1], $report->total(), 'the method line is not a statement');
	}

	public function testDirectoriesAreGroupedTwoLevelsDeepWithTopLevelFilesUnderLib(): void {
		$report = new CloverReport(self::ROOT);
		$report->addXml(self::clover([
			'lib/AP.php' => [1 => 1, 2 => 0],
			'lib/Service/AccountService.php' => [1 => 1],
			'lib/Model/ActivityPub/Stream.php' => [1 => 0, 2 => 1],
			'lib/Model/Client/Status.php' => [1 => 1],
		]));

		$this->assertSame([
			'lib' => ['covered' => 1, 'statements' => 2],
			'lib/Model' => ['covered' => 2, 'statements' => 3],
			'lib/Service' => ['covered' => 1, 'statements' => 1],
		], $report->byDirectory());
	}

	public function testPathsAreReadRelativeToTheRootWithOrWithoutATrailingSlash(): void {
		$report = new CloverReport(self::ROOT . '/');
		$report->addXml(self::clover(['lib/AP.php' => [1 => 1, 2 => 0]]));

		$this->assertSame(['lib' => ['covered' => 1, 'statements' => 2]], $report->byDirectory());
	}

	/** @return array<string, array{int, int, float}> */
	public static function percentages(): array {
		return [
			'rounded down, never up to a floor' => [2, 3, 66.6],
			'just under a whole number' => [7999, 10000, 79.9],
			'exact' => [1, 4, 25.0],
			'nothing to cover' => [0, 0, 100.0],
		];
	}

	#[DataProvider('percentages')]
	public function testPercentIsRoundedDownToOneDecimal(int $covered, int $statements, float $expected): void {
		$this->assertSame($expected, CloverReport::percent(['covered' => $covered, 'statements' => $statements]));
	}

	public function testTheTableHasAColumnPerReportAndOneForTheirMerge(): void {
		$unit = self::clover(['lib/Service/A.php' => [1 => 1, 2 => 0], 'lib/Db/B.php' => [1 => 0, 2 => 0]]);
		$integration = self::clover(['lib/Service/A.php' => [1 => 0, 2 => 0], 'lib/Db/B.php' => [1 => 1, 2 => 1]]);
		$merged = new CloverReport(self::ROOT);
		$reports = [];
		foreach (['unit' => $unit, 'integration' => $integration] as $label => $xml) {
			$reports[$label] = new CloverReport(self::ROOT);
			$reports[$label]->addXml($xml);
			$merged->addXml($xml);
		}

		$this->assertSame(
			"| Directory | unit | integration | merged | Statements |\n"
			. "|---|---:|---:|---:|---:|\n"
			. "| lib/Db | 0 % | 100 % | 100 % | 2 |\n"
			. "| lib/Service | 50 % | 0 % | 50 % | 2 |\n"
			. "| **total** | 25 % | 50 % | 75 % | 4 |\n",
			CloverReport::table($reports, $merged),
		);
	}

	/** @return array<string, array{string}> */
	public static function notClover(): array {
		return [
			'not XML' => ['coverage: 80 %'],
			'XML without files' => ['<?xml version="1.0"?><coverage><project/></coverage>'],
		];
	}

	#[DataProvider('notClover')]
	public function testSomethingThatIsNotACloverReportIsRefused(string $xml): void {
		$this->expectException(InvalidArgumentException::class);

		(new CloverReport(self::ROOT))->addXml($xml);
	}
}
