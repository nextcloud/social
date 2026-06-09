<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\AppInfo\Application;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class SocialTimelineWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget {

	private IL10N $l10n;
	private IURLGenerator $urlGenerator;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private StreamService $streamService;
	private LoggerInterface $logger;

	public function __construct(
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		LoggerInterface $logger,
	) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->streamService = $streamService;
		$this->logger = $logger;
	}

	public function getId(): string {
		return 'social_timeline';
	}

	public function getTitle(): string {
		return $this->l10n->t('Social timeline');
	}

	public function getOrder(): int {
		return 11;
	}

	public function getIconClass(): string {
		return 'icon-social';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'social-dark.svg');
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRoute('social.Navigation.timeline');
	}

	public function load(): void {
		\OCP\Util::addStyle(Application::APP_ID, 'dashboard');
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->urlGenerator->linkToRoute('social.Navigation.timeline'),
				$this->l10n->t('Open timeline')
			),
		];
	}

	public function getReloadInterval(): int {
		return 300;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		try {
			$account = $this->accountService->getActorFromUserId($userId, false);
			$viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			$viewer->setExportFormat(ACore::FORMAT_LOCAL);
			$this->streamService->setViewer($viewer);

			$options = new ProbeOptions();
			$options->setProbe(ProbeOptions::HOME)
				->setLimit(min($limit, 20));

			$notes = $this->streamService->getTimeline($options);

			$items = [];
			foreach ($notes as $note) {
				if (!$note instanceof Note) {
					continue;
				}

				try {
					$actor = $this->cacheActorService->getFromId($note->getAttributedTo());
				} catch (\Exception $e) {
					$actor = null;
				}

				$content = strip_tags($note->getContent() ?? '');
				$content = mb_substr($content, 0, 120);

				$title = $content !== '' ? $content : $this->l10n->t('(no content)');

				$actorName = $actor
					? ($actor->getName() ?: $actor->getPreferredUsername())
					: $note->getAttributedTo();
				$subtitle = $actorName;

				$iconUrl = $actor ? $actor->getAvatar() : '';

				$link = $this->urlGenerator->linkToRoute('social.Navigation.timeline', [
					'path' => 'home',
				]);

				$sinceId = (string)$note->getPublishedTime();

				$items[] = new WidgetItem($title, $subtitle, $link, $iconUrl, $sinceId);
			}

			return new WidgetItems(
				$items,
				$this->l10n->t('Follow some accounts to see their posts here'),
				$this->l10n->t('No recent posts'),
			);
		} catch (\Exception $e) {
			$this->logger->warning('SocialTimelineWidget error', ['exception' => $e]);
			return new WidgetItems(
				[],
				$this->l10n->t('Could not load timeline'),
				$this->l10n->t('Could not load timeline'),
			);
		}
	}
}
