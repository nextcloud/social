<?php declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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


	/** @var IL10N */
	private $l10n;

	/** @var IFactory */
	protected $factory;

	/** @var IManager */
	protected $contactsManager;

	/** @var IURLGenerator */
	protected $url;

	/** @var ICloudIdManager */
	protected $cloudIdManager;


	public function __construct(
		IL10N $l10n, IFactory $factory, IManager $contactsManager, IURLGenerator $url,
		ICloudIdManager $cloudIdManager
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
		return Application::APP_NAME;
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
		if ($notification->getApp() !== Application::APP_NAME) {
			throw new InvalidArgumentException();
		}

		$l10n = $this->factory->get(Application::APP_NAME, $languageCode);

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
