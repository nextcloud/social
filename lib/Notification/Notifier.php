<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Notification;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
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
	private IL10N $l10n;

	protected IFactory $factory;

	protected IManager $contactsManager;

	protected IURLGenerator $url;

	protected ICloudIdManager $cloudIdManager;


	public function __construct(
		IL10N $l10n, IFactory $factory, IManager $contactsManager, IURLGenerator $url,
		ICloudIdManager $cloudIdManager,
	) {
		$this->l10n = $l10n;
		$this->factory = $factory;
		$this->contactsManager = $contactsManager;
		$this->url = $url;
		$this->cloudIdManager = $cloudIdManager;
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
			case 'update_alpha3':
				$notification->setParsedSubject('The Social App has been updated to alpha3.');
				$notification->setParsedMessage(
					$l10n->t(
						'Please note that the data from alpha2 can only be migrated manually.
						A detailed documentation to guide you during this process is available using the button below.'
					)
				);
				break;

			default:
				throw new InvalidArgumentException();
		}


		foreach ($notification->getActions() as $action) {
			switch ($action->getLabel()) {
				case 'help':
					$action->setParsedLabel($l10n->t('Help'))
						->setPrimary(true);
					break;
			}

			$notification->addParsedAction($action);
		}

		return $notification;
	}
}
