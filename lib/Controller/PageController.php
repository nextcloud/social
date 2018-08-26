<?php
namespace OCA\VueExample\Controller;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;

class PageController extends Controller {
	private $userId;

	public function __construct($AppName, IRequest $request, $UserId, IL10N $l10n){
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->l10n = $l10n;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index() {
		$response = new TemplateResponse('vueexample', 'main');
		return $response;
	}

}
