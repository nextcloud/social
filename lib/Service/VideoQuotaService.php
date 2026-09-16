<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCP\IConfig;

/**
 * How much video one account may keep here, and who is keeping it.
 *
 * There was a ceiling on how big one *file* could be (`max_video_size`, two
 * gigabytes) and none at all on how many of them one account could upload, so
 * the only real limit was the disk — and the administrator found out about it
 * from the disk. A per-file ceiling and a per-account one answer different
 * questions and neither substitutes for the other: the first is about a single
 * request, the second about a year of them.
 *
 * **Off by default.** An instance that has been running without a quota and
 * acquires one on upgrade would start refusing uploads from the accounts that
 * use it most, which is not an upgrade note anybody reads in time.
 *
 * Counted from the stored `size` rather than by walking the files: this is
 * asked on every video upload. Rows written before that column existed carry
 * `0` and are invisible to it until `MediaUsageService` has been past them,
 * which it does on the cron and fills them in as it goes.
 */
class VideoQuotaService {
	/** No quota, which is the default: the only limit is the disk. */
	public const UNLIMITED = 0;

	/** The largest quota worth naming, in megabytes. */
	public const MAX_QUOTA_MB = 10485760; // 10 TB

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private RenditionsRequest $renditionsRequest,
		private ConfigService $configService,
		private IConfig $config,
	) {
	}

	/** The quota in megabytes, or `UNLIMITED`. */
	public function quota(): int {
		$quota = $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_QUOTA);

		return ($quota > 0) ? $quota : self::UNLIMITED;
	}

	/** How many bytes of video this account is holding. */
	public function used(string $account): int {
		return $this->cacheDocumentsRequest->videoBytesOf($account);
	}

	/**
	 * Whether this account may store another video of this size.
	 *
	 * The rungs of a ladder are deliberately **not** counted against it. They
	 * are made by this server because an administrator asked for them, are
	 * several times the size of the upload, and would turn a quota somebody
	 * was told about into a quota four times smaller than the number they were
	 * given. They are counted in what an administrator is *shown*, because
	 * they are real disk.
	 */
	public function fits(string $account, int $incoming): bool {
		$quota = $this->quota();
		if ($quota === self::UNLIMITED || $account === '') {
			return true;
		}

		return ($this->used($account) + $incoming) <= ($quota * 1048576);
	}

	/**
	 * What every account is holding, most first, with the ladders included.
	 *
	 * @return array<array{account: string, bytes: int, files: int}>
	 */
	public function byAccount(int $limit = 50): array {
		return $this->cacheDocumentsRequest->videoBytesByAccount($limit);
	}

	/** How much disk every ladder on this instance is holding, together. */
	public function renditionBytes(): int {
		return $this->renditionsRequest->totalSize();
	}

	/**
	 * Where this app's files actually are.
	 *
	 * Not a setting: Nextcloud has no per-app object store, and an app that
	 * offered one would be offering something it cannot deliver. What it can
	 * do is **say** where the store is, so an administrator looking at a full
	 * disk knows whether the instance-wide `objectstore` in `config.php` is in
	 * force for it — which is the supported way to put this somewhere else,
	 * and covers Social's appdata along with everything else's.
	 *
	 * @return array{object_store: bool, class: string, bucket: string}
	 */
	public function storage(): array {
		$objectStore = $this->config->getSystemValue('objectstore', null);
		$multibucket = $this->config->getSystemValue('objectstore_multibucket', null);
		$configured = is_array($objectStore) ? $objectStore : (is_array($multibucket) ? $multibucket : null);

		return [
			'object_store' => $configured !== null,
			'class' => is_string($configured['class'] ?? null) ? $configured['class'] : '',
			'bucket' => is_string($configured['arguments']['bucket'] ?? null)
				? $configured['arguments']['bucket'] : '',
		];
	}
}
