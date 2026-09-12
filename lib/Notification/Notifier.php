<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Notification;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Model\Moderation;
use OCP\Contacts\IManager;
use OCP\Federation\ICloudIdManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Class Notifier
 *
 * @package OCA\Social\Notification
 */
class Notifier implements INotifier {
	public function __construct(
		private IL10N $l10n,
		protected IFactory $factory,
		protected IManager $contactsManager,
		protected IURLGenerator $url,
		protected ICloudIdManager $cloudIdManager,
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return Application::APP_ID;
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->l10n->t('Social');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 *
	 * @return INotification
	 * @throws InvalidArgumentException
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new InvalidArgumentException();
		}

		$l10n = $this->factory->get(Application::APP_ID, $languageCode);

		$notification->setIcon(
			$this->url->getAbsoluteURL($this->url->imagePath('social', 'social_dark.svg'))
		);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case 'mention':
				$notification->setParsedSubject(
					$l10n->t('%s mentioned you in a post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'favourite':
				$notification->setParsedSubject(
					$l10n->t('%s favourited your post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'reblog':
				$notification->setParsedSubject(
					$l10n->t('%s boosted your post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'follow':
				$notification->setParsedSubject(
					$l10n->t('%s is now following you', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'follow_request':
				$notification->setParsedSubject(
					$l10n->t('%s wants to follow you', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'update':
				$notification->setParsedSubject(
					$l10n->t('%s edited a post you boosted', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'report_new':
				$account = (string)($params['account'] ?? '');
				$notification->setParsedSubject(
					($params['local'] ?? true) === true
						? $l10n->t('New report about %s', [$account])
						: $l10n->t('New report about %s from another instance', [$account])
				);
				$notification->setParsedMessage(
					$l10n->t('Review it in the Social section of the administration settings.')
				);
				$notification->setLink(
					$this->url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'social'])
				);
				break;

			case 'moderation_warning':
				// the account was told nothing before this: a decision it was
				// not told about is one it can only discover by noticing that
				// its posts stopped appearing
				$text = trim((string)($params['text'] ?? ''));
				$notification->setParsedSubject(match ((string)($params['action'] ?? '')) {
					Moderation::SILENCE => $l10n->t(
						'Your account has been silenced by a moderator of this server'
					),
					Moderation::SUSPEND => $l10n->t(
						'Your account has been suspended by a moderator of this server'
					),
					default => $l10n->t('You have received a warning from a moderator of this server'),
				});
				$notification->setParsedMessage($text === ''
					? $l10n->t('No reason was given.')
					: $text);
				break;

			default:
				throw new InvalidArgumentException();
		}

		return $notification;
	}

	/**
	 * Who acted, as it is written into the sentence. An account that could not
	 * be resolved when the notification was raised leaves this empty rather
	 * than guessing a name — `%s mentioned you in a post` with nothing in
	 * front of it still says what happened.
	 */
	private function account(array $params): string {
		return (string)($params['account'] ?? '');
	}

	/**
	 * Points the notification at the post or the profile it is about, and
	 * shows the acting account's avatar instead of the app icon.
	 *
	 * Both are taken only when they are absolute http(s) URLs: the parameters
	 * come from a stored notification, whose actor may be on another server,
	 * and a relative or exotic value there would be rendered as a link out of
	 * the Nextcloud interface to something nobody vouched for.
	 */
	private function point(INotification $notification, array $params): void {
		$link = (string)($params['link'] ?? '');
		if ($this->isWebUrl($link)) {
			$notification->setLink($link);
		}

		$avatar = (string)($params['avatar'] ?? '');
		if ($this->isWebUrl($avatar)) {
			$notification->setIcon($avatar);
		}
	}

	private function isWebUrl(string $url): bool {
		return (filter_var($url, FILTER_VALIDATE_URL) !== false)
			&& (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'));
	}
}
