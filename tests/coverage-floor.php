<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Merges Clover reports of lib/, prints line coverage per directory and fails
 * when the merged total is under the floor in tests/coverage-floor.json.
 *
 *   php tests/coverage-floor.php unit=coverage/unit.xml integration=coverage/integration.xml
 *
 * The table goes to stdout and, in GitHub Actions, to the job summary.
 */

use OCA\Social\Tests\Helper\CloverReport;

require_once __DIR__ . '/../vendor/autoload.php';

$inputs = array_slice($argv, 1);
if ($inputs === []) {
	fwrite(STDERR, "usage: php tests/coverage-floor.php <label>=<clover.xml> ...\n");
	exit(2);
}

$root = (string)realpath(__DIR__ . '/..');
$merged = new CloverReport($root);
$reports = [];
foreach ($inputs as $input) {
	[$label, $file] = str_contains($input, '=') ? explode('=', $input, 2) : [basename($input, '.xml'), $input];
	$xml = @file_get_contents($file);
	if ($xml === false) {
		fwrite(STDERR, "no Clover report at $file\n");
		exit(2);
	}
	$report = new CloverReport($root);
	$report->addXml($xml);
	$merged->addXml($xml);
	$reports[$label] = $report;
}

$floorFile = __DIR__ . '/coverage-floor.json';
$floor = (float)(json_decode((string)file_get_contents($floorFile), true)['lines'] ?? 0);
$total = CloverReport::percent($merged->total());

$table = CloverReport::table($reports, $merged);

$raiseTo = floor(($total - 1) * 10) / 10;
$verdict = $total < $floor
	? "Line coverage $total % is under the floor of $floor % in tests/coverage-floor.json: cover the new code, or lower the floor in the same change and say why."
	: "Line coverage $total %, floor $floor %."
		. ($raiseTo >= $floor + 1 ? " The floor can be raised to $raiseTo % in tests/coverage-floor.json." : '');
$summary = "### PHP line coverage (lib/)\n\n$table\n$verdict\n";

echo $summary;
$stepSummary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($stepSummary) && $stepSummary !== '') {
	file_put_contents($stepSummary, $summary, FILE_APPEND);
}

exit($total < $floor ? 1 : 0);
