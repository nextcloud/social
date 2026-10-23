#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\Social\Service\RecommendationOfflineEvaluator;
use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;

require_once __DIR__ . '/../../vendor/autoload.php';

$datasetPath = $argv[1] ?? (__DIR__ . '/fixtures/synthetic-v1.json');
$outputPath = $argv[2] ?? null;
if (!is_file($datasetPath)) {
	fwrite(STDERR, "Dataset not found: $datasetPath\nUsage: php tools/recommendations/evaluate.php [dataset.json] [report.json]\n");
	exit(2);
}

try {
	$dataset = json_decode((string)file_get_contents($datasetPath), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($dataset)) {
		throw new RuntimeException('Dataset root must be a JSON object.');
	}
	$report = (new RecommendationOfflineEvaluator(new RecommendationRanker(), new RecommendationReRanker()))->evaluate($dataset);
	$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if ($outputPath !== null) {
		if (file_put_contents($outputPath, $json) === false) {
			throw new RuntimeException("Could not write report: $outputPath");
		}
		fwrite(STDOUT, "Wrote evaluation report to $outputPath\n");
	} else {
		fwrite(STDOUT, $json);
	}
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
}
