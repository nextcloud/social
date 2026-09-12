<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NavigationController;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NavigationControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var IConfig&MockObject */
	private $config;
	/** @var IInitialState&MockObject */
	private $initialState;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var CheckService&MockObject */
	private $checkService;
	/** @var IGroupManager&MockObject */
	private $groupManager;
	private array $states = [];
	private string|false $frontControllerEnv;

	protected function setUp(): void {
		$this->frontControllerEnv = getenv('front_controller_active');
		putenv('front_controller_active');

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->checkService = $this->createMock(CheckService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states['social'][$key] = $data;
			});
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example/');

		\OC::$server->register(IRequest::class, $this->request);
		\OC::$server->register(IGroupManager::class, $this->groupManager);
	}

	protected function tearDown(): void {
		if ($this->frontControllerEnv === false) {
			putenv('front_controller_active');
		} else {
			putenv('front_controller_active=' . $this->frontControllerEnv);
		}
		\OC::$server->reset();
	}

	private function controller(?string $userId = 'alice'): NavigationController {
		return new NavigationController(
			$this->createMock(IL10N::class),
			$this->request,
			$userId,
			$this->config,
			$this->initialState,
			$this->urlGenerator,
			$this->accountService,
			$this->documentService,
			$this->configService,
			$this->checkService,
			$this->createMock(MiscService::class),
			new NullLogger()
		);
	}

	private function systemValues(array $values): void {
		$this->config->method('getSystemValue')
			->willReturnCallback(fn (string $key, $default = '') => $values[$key] ?? $default);
	}

	private function configuredCloud(string $url = 'https://cloud.example/index.php'): void {
		$this->configService->method('getCloudUrl')->willReturn($url);
		$this->configService->method('getSocialUrl')->willReturn($url . '/apps/social/');
	}

	private function existingActor(): void {
		$this->accountService->method('createActor')->willThrowException(new AccountAlreadyExistsException());
	}

	private function serverData(): array {
		return $this->states['social']['serverData'];
	}

	// navigate()

	public function testNavigateRendersTheAppForAReturningUser(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->checkService->expects($this->never())->method('checkDefault');
		$this->checkService->expects($this->never())->method('checkInstallationStatus');

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame([
			'public' => false,
			'firstrun' => false,
			'setup' => false,
			'isAdmin' => false,
			'cliUrl' => 'https://cloud.example/index.php',
			'cloudAddress' => 'https://cloud.example/index.php',
		], $this->serverData());
	}

	public function testNavigateCreatesTheActorOnFirstRun(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		// the handle is derived from the user id rather than being the user id:
		// not every Nextcloud user id is a usable Fediverse handle
		$this->accountService->expects($this->once())
			->method('generateHandleFromUserId')
			->with('alice')
			->willReturn('alice');
		$this->accountService->expects($this->once())->method('createActor')->with('alice', 'alice');

		$this->controller()->navigate();

		$this->assertTrue($this->serverData()['firstrun']);
	}

	public function testNavigateAddsChecksForAdmins(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
		$this->checkService->method('checkDefault')->willReturn(['wellknown' => true]);

		$this->controller()->navigate();

		$this->assertTrue($this->serverData()['isAdmin']);
		$this->assertSame(['wellknown' => true], $this->serverData()['checks']);
	}

	public function testNavigateOmitsIndexPhpWhenFrontControllerIsActive(): void {
		$this->systemValues(['htaccess.IgnoreFrontController' => true]);
		$this->configuredCloud();
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertSame('https://cloud.example', $this->serverData()['cliUrl']);
	}

	public function testNavigateAutoConfiguresTheCloudAddressFromCliUrl(): void {
		$this->systemValues(['overwrite.cli.url' => 'https://cloud.example/']);
		// the derivation itself lives in CheckService, where it is tested; here
		// it only matters that what it answers is what gets stored and returned
		$this->checkService->method('derivedCloudAddress')->willReturn('https://cloud.example/index.php');
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->checkService->expects($this->once())->method('checkInstallationStatus')->with(true);
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://cloud.example/index.php');
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertSame('https://cloud.example/index.php', $this->serverData()['cloudAddress']);
		$this->assertFalse($this->serverData()['setup']);
	}

	public function testNavigateShowsTheSetupPageToAdminsWhenNothingIsConfigured(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->with('cloudAddress')->willReturn(null);
		$this->accountService->expects($this->never())->method('createActor');
		$this->checkService->expects($this->never())->method('checkDefault');

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertTrue($this->serverData()['setup']);
		$this->assertTrue($this->serverData()['isAdmin']);
		$this->assertArrayNotHasKey('cloudAddress', $this->serverData());
	}

	public function testNavigateStoresTheCloudAddressSubmittedByAnAdmin(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->with('cloudAddress')->willReturn('https://cloud.example/index.php');
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://cloud.example/index.php');
		$this->existingActor();

		$this->controller()->navigate();

		$this->assertTrue($this->serverData()['setup']);
		$this->assertArrayHasKey('checks', $this->serverData());
	}

	public function testNavigateContinuesForNonAdminsWhenSetupIsMissing(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getSocialUrl')->willReturn('x');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->configService->expects($this->never())->method('setCloudUrl');
		$this->existingActor();

		$response = $this->controller()->navigate();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertTrue($this->serverData()['setup']);
	}

	public function testNavigateInitialisesTheSocialUrlWhenMissing(): void {
		$this->systemValues([]);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->configService->method('getSocialUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->expects($this->once())->method('setSocialUrl');
		$this->existingActor();

		$this->controller()->navigate();
	}

	public function testNavigateSurvivesActorCreationConfigErrors(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->accountService->method('createActor')->willThrowException(new SocialAppConfigException());

		$this->assertInstanceOf(TemplateResponse::class, $this->controller()->navigate());
		$this->assertFalse($this->serverData()['firstrun']);
	}

	public function testTimelineAndAccountRenderTheSamePage(): void {
		$this->systemValues([]);
		$this->configuredCloud();
		$this->existingActor();

		$this->assertSame('main', $this->controller()->timeline('home')->getTemplateName());
		$this->assertSame('main', $this->controller()->account('alice')->getTemplateName());
	}

	// documents

	private function cachedFile(string $method, string $mime, ?bool $public): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('doc');
		$this->documentService->expects($this->once())->method($method)
			->willReturnCallback(function (string $id, string &$mimeType, bool $isPublic = false) use ($file, $mime, $public): ISimpleFile {
				$this->assertSame('doc-1', $id);
				if ($public !== null) {
					$this->assertSame($public, $isPublic);
				}
				$mimeType = $mime;

				return $file;
			});

		return $file;
	}

	/**
	 * The viewer-scoped variant: the id alone says nothing about who may read
	 * the document, so these take the asking actor as well.
	 */
	private function cachedFileAsViewer(string $method, string $mime, ?Person $viewer): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('doc');
		$this->documentService->expects($this->once())->method($method)
			->willReturnCallback(function (string $id, ?Person $asked, string &$mimeType) use ($file, $mime, $viewer): ISimpleFile {
				$this->assertSame('doc-1', $id);
				$this->assertSame($viewer, $asked);
				$mimeType = $mime;

				return $file;
			});

		return $file;
	}

	private function assertServes(FileDisplayResponse|DataResponse $response, string $mime): void {
		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($mime, $response->getHeaders()['Content-Type']);
	}

	public function testDocumentGetServesTheCachedDocumentToItsViewer(): void {
		$viewer = $this->createMock(Person::class);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', $viewer);

		$this->assertServes($this->controller()->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetAsksAsNobodyWhenThereIsNoSession(): void {
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', null);

		$this->assertServes($this->controller(null)->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetAsksAsNobodyWhenTheSessionHasNoSocialAccount(): void {
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new AccountDoesNotExistException());
		$this->cachedFileAsViewer('getFromCacheAsViewer', 'image/jpeg', null);

		$this->assertServes($this->controller()->documentGet('doc-1'), 'image/jpeg');
	}

	public function testDocumentGetPublicOnlyServesPublicDocuments(): void {
		$this->cachedFile('getFromCache', 'image/png', true);

		$this->assertServes($this->controller(null)->documentGetPublic('doc-1'), 'image/png');
	}

	public function testResizedGetServesTheResizedCopyToItsViewer(): void {
		$viewer = $this->createMock(Person::class);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$this->cachedFileAsViewer('getResizedFromCacheAsViewer', 'image/webp', $viewer);

		$this->assertServes($this->controller()->resizedGet('doc-1'), 'image/webp');
	}

	public function testResizedGetPublicOnlyServesPublicDocuments(): void {
		$this->cachedFile('getResizedFromCache', 'image/gif', true);

		$this->assertServes($this->controller(null)->resizedGetPublic('doc-1'), 'image/gif');
	}

	/** @return iterable<string, array{string, string}> */
	public static function documentEndpoints(): iterable {
		yield 'documentGet' => ['documentGet', 'getFromCacheAsViewer'];
		yield 'documentGetPublic' => ['documentGetPublic', 'getFromCache'];
		yield 'resizedGet' => ['resizedGet', 'getResizedFromCacheAsViewer'];
		yield 'resizedGetPublic' => ['resizedGetPublic', 'getResizedFromCache'];
	}

	#[DataProvider('documentEndpoints')]
	public function testMissingDocumentsAreReportedAsFailures(string $action, string $method): void {
		$this->documentService->method($method)->willThrowException(new CacheDocumentDoesNotExistException('missing'));

		$response = $this->controller()->$action('doc-404');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData(), 'internals must not leak');
	}
}
