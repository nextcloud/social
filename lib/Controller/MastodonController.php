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

namespace OCA\Social\Controller;


use OC\AppFramework\Http;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;


/**
 * Class MastodonAPIController
 *
 * @package OCA\Social\Controller
 */
class MastodonController extends Controller {


	/** @var IConfig */
	private $config;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;

	/** @var IL10N */
	private $l10n;


	/**
	 * MastodonAPIController constructor.
	 *
	 * @param IL10N $l10n
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IL10N $l10n, IRequest $request, IConfig $config, IURLGenerator $urlGenerator,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->l10n = $l10n;
		$this->config = $config;

		$this->urlGenerator = $urlGenerator;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $client_name
	 * @param string $redirect_uris
	 * @param string $scopes
	 * @param string $website
	 *
	 * @return DataResponse
	 */
	public function createApp(
		$client_name, $redirect_uris, $scopes, $website
	): DataResponse {


		// TODO: check incoming data;
		$data = [
			'clientName'   => $client_name,
			'redirectUris' => $redirect_uris,
			'scopes'       => $scopes,
			'website'      => $website
		];

		$this->miscService->log(json_encode($data));
		$result = [
			'id'            => 'id',
			'client_id'     => 'client_id',
			'client_secret' => 'client_secret'
		];

		return new DataResponse($result, Http::STATUS_OK);
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $scope
	 * @param string $response_type
	 * @param string $redirect_uri
	 * @param string $client_id
	 *
	 * @param string $client_secret
	 *
	 * @return DataResponse
	 */
	public function oauthAuthorize(
		$scope, $response_type, $redirect_uri, $client_id, $client_secret
	) {

		// TODO: check incoming data;

		$data = [
			'scope'        => $scope,
			'responseType' => $response_type,
			'redirectUri'  => $redirect_uri,
			'clientId'     => $client_id,
			'clientSecret' => $client_secret
		];

		$this->miscService->log(json_encode($data));
		$result = [];

		return new DataResponse($result, Http::STATUS_OK);
	}

}

