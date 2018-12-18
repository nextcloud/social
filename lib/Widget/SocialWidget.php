<?php
declare(strict_types=1);


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


namespace OCA\Social\Widget;


use OC\User\NoUserException;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NoteService;
use OCP\Dashboard\IDashboardWidget;
use OCP\Dashboard\Model\IWidgetConfig;
use OCP\Dashboard\Model\IWidgetRequest;
use OCP\Dashboard\Model\WidgetSetup;
use OCP\Dashboard\Model\WidgetTemplate;
use OCP\IL10N;


class SocialWidget implements IDashboardWidget {


	const WIDGET_ID = 'social';

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var AccountService */
	private $accountService;

	/** @var NoteService */
	private $noteService;


	/**
	 * FortunesWidget constructor.
	 *
	 * @param string $userId
	 * @param IL10N $l10n
	 * @param AccountService $accountService
	 * @param NoteService $noteService
	 */
	public function __construct(
		$userId, IL10N $l10n, AccountService $accountService, NoteService $noteService
	) {
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->accountService = $accountService;
		$this->noteService = $noteService;
	}


	/**
	 * @return string
	 */
	public function getId(): string {
		return self::WIDGET_ID;
	}


	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->l10n->t('Social stream (ALPHA)');
	}


	/**
	 * @return string
	 */
	public function getDescription(): string {
		return $this->l10n->t('Get some last news from Social');
	}


	/**
	 * @return WidgetTemplate`
	 */
	public function getWidgetTemplate(): WidgetTemplate {
		$template = new WidgetTemplate();
		$template->addCss('widget')
				 ->addJs('widget')
				 ->setIcon('icon-social')
				 ->setContent('widget')
				 ->setInitFunction('OCA.Social.widget.init');

		return $template;
	}


	/**
	 * @return WidgetSetup
	 */
	public function getWidgetSetup(): WidgetSetup {
		$setup = new WidgetSetup();
		$setup->addSize(WidgetSetup::SIZE_TYPE_MIN, 4, 2)
			  ->addSize(WidgetSetup::SIZE_TYPE_MAX, 6, 5)
			  ->addSize(WidgetSetup::SIZE_TYPE_DEFAULT, 4, 2);

		$setup->addDelayedJob('OCA.Social.widget.refresh', 300);
		$setup->setPush('OCA.Social.widget.push');

		return $setup;
	}


	/**
	 * @param IWidgetConfig $settings
	 */
	public function loadWidget(IWidgetConfig $settings) {
	}


	/**
	 * @param IWidgetRequest $request
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws NoUserException
	 */
	public function requestWidget(IWidgetRequest $request) {
		if ($request->getRequest() === 'getStreamWidget') {
			$actor = $this->accountService->getActorFromUserId($this->userId, true);

			$request->addResultArray('stream', $this->noteService->getStreamHome($actor, 0, 1));
		}
	}


}

