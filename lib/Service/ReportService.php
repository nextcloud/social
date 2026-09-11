<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\Report;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Moderation reports: stores reports filed locally over the client API or
 * received from remote instances as Flag activities, and tells the instance
 * admins about new ones.
 */
class ReportService {
	public function __construct(
		private ReportsRequest $reportsRequest,
		private CacheActorService $cacheActorService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private INotificationManager $notificationManager,
		private ReportForwardService $reportForwardService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A report filed by a local user over POST /api/v1/reports.
	 *
	 * @param string[] $statusIds
	 * @param bool $forward Mastodon's `forward`: also tell the instance the
	 *                      reported account is on. Ignored for a local
	 *                      account, which has no other instance to tell.
	 */
	public function reportFromLocal(
		Person $reporter, Person $target, array $statusIds, string $comment, string $category,
		bool $forward = false,
	): Report {
		$report = new Report();
		$report->setActorId($reporter->getId())
			->setAccountId($target->getId())
			->setStatusIds($statusIds)
			->setComment($comment)
			->setCategory($category)
			->setLocal(true)
			->setTargetAccount($target);

		$this->reportsRequest->save($report);
		$this->notifyAdmins($report);

		if ($forward) {
			$this->forward($report, $target);
		}

		return $report;
	}

	/**
	 * Forwards the report, and records whether the remote instance took it.
	 *
	 * Done after the report is stored, so the moderators here have it whatever
	 * the other instance does with it, and never throws: the report was filed
	 * successfully even when the forward could not be delivered.
	 */
	private function forward(Report $report, Person $target): void {
		try {
			if (!$this->reportForwardService->forward($report, $target)) {
				return;
			}

			$report->setForwarded(true);
			$this->reportsRequest->setForwarded($report->getId(), true);
		} catch (Exception $e) {
			$this->logger->warning('could not forward a report', [
				'report' => $report->getId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * A report received from a remote instance as a Flag activity. Mastodon
	 * sends `object` as a list mixing the reported account and status ids; the
	 * first id that resolves to a local account is stored as the target, the
	 * remaining ids as the reported statuses.
	 */
	public function reportFromFlag(Flag $flag): Report {
		$accountId = '';
		$statusIds = [];
		foreach ($flag->getObjectIds() as $objectId) {
			if ($accountId === '') {
				try {
					$actor = $this->cacheActorService->getFromId($objectId);
					if ($actor->isLocal()) {
						$accountId = $objectId;
						continue;
					}
				} catch (Exception $e) {
				}
			}
			$statusIds[] = $objectId;
		}

		if ($accountId === '') {
			// nothing in the report resolves to one of our accounts; keep the
			// first id as target so the report is still visible to the admin
			$accountId = $flag->getObjectIds()[0] ?? '';
			$statusIds = array_slice($flag->getObjectIds(), 1);
		}

		$report = new Report();
		$report->setActorId($flag->getActorId())
			->setAccountId($accountId)
			->setStatusIds($statusIds)
			->setComment($flag->getContent())
			->setCategory(Report::CATEGORY_OTHER)
			->setLocal(false);

		$this->reportsRequest->save($report);
		$this->notifyAdmins($report);

		return $report;
	}

	/**
	 * The reports for the moderation panel, with their target accounts filled
	 * in from what is already cached.
	 *
	 * One query for the accounts of the whole page, and no federated request
	 * on a miss: resolving per report meant up to 200 synchronous requests to
	 * other instances per admin page load, and a single unreachable one held
	 * the page for its curl timeout. A report whose account is not cached
	 * still shows — with the id it was filed against, which is what the
	 * moderator needs to act on it.
	 *
	 * @return Report[] target accounts resolved where possible
	 */
	public function getReports(bool $includeResolved = false): array {
		$reports = $this->reportsRequest->getAll($includeResolved);

		$accounts = $this->cacheActorService->getCachedFromIds(
			array_map(static fn (Report $report): string => $report->getAccountId(), $reports)
		);

		foreach ($reports as $report) {
			$account = $accounts[$report->getAccountId()] ?? null;
			if ($account !== null) {
				$report->setTargetAccount($account);
			}
		}

		return $reports;
	}

	public function countOpen(): int {
		return $this->reportsRequest->countOpen();
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function setResolved(int $id, bool $resolved): Report {
		$this->reportsRequest->getById($id); // throws when unknown
		$this->reportsRequest->setResolved($id, $resolved);

		return $this->reportsRequest->getById($id);
	}

	private function notifyAdmins(Report $report): void {
		try {
			foreach ($this->userManager->search('') as $user) {
				if (!$this->groupManager->isAdmin($user->getUID())) {
					continue;
				}

				$notification = $this->notificationManager->createNotification();
				$notification->setApp('social')
					->setDateTime(new \DateTime('now'))
					->setUser($user->getUID())
					->setObject('report', (string)$report->getId())
					->setSubject('report_new', [
						'reporter' => $report->getActorId(),
						'account' => $report->getAccountId(),
						'local' => $report->isLocal(),
					]);
				$this->notificationManager->notify($notification);
			}
		} catch (Exception $e) {
			$this->logger->warning('could not notify admins about report', ['exception' => $e]);
		}
	}
}
