<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * @deprecated in 0.7.x
 */
class RenameDocumentLocalCopy implements IRepairStep {
	private ConfigService $configService;
	private CacheDocumentsRequest $cacheDocumentsRequest;

	public function __construct(
		ConfigService $configService,
		CacheDocumentsRequest $cacheDocumentsRequest,
	) {
		$this->configService = $configService;
		$this->cacheDocumentsRequest = $cacheDocumentsRequest;
	}

	public function getName(): string {
		return 'Rename document local/resized copies';
	}

	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt('migration_rename_document_copy') === 1) {
			return;
		}

		$oldCopies = $this->cacheDocumentsRequest->getOldFormatCopies();

		$output->startProgress(count($oldCopies));
		foreach ($oldCopies as $copy) {
			$copy->setLocalCopy($this->reformat($copy->getLocalCopy()));
			$copy->setResizedCopy($this->reformat($copy->getResizedCopy()));
			$this->cacheDocumentsRequest->updateCopies($copy);
			$output->advance();
		}
		$output->finishProgress();

		$this->configService->setAppValue('migration_rename_document_copy', '1');
	}

	private function reformat(string $old): string {
		$pos = strrpos($old, '/');
		if (!$pos) {
			return $old;
		}

		return substr($old, $pos + 1);
	}
}
