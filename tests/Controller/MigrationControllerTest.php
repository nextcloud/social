<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MigrationController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\PostImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Migration page's three buttons.
 *
 * `$_FILES` rather than a parameter, because that is what the framework hands
 * a multipart upload and what the controller reads; the tests set it the way a
 * request would and clear it afterwards.
 */
class MigrationControllerTest extends TestCase {
	private MigrationArchiveService|MockObject $archiveService;
	private MigrationService|MockObject $migrationService;
	private PostImportService|MockObject $postImportService;
	private AccountService|MockObject $accountService;

	protected function setUp(): void {
		parent::setUp();
		$this->archiveService = $this->createMock(MigrationArchiveService::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$_FILES = [];

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->createMock(IRequest::class));

		$this->postImportService = $this->createMock(PostImportService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn(new Person());
	}

	/** @var array<string, mixed> what the request carries */
	private array $params = [];

	protected function tearDown(): void {
		$_FILES = [];
		\OC::$server->reset();
		parent::tearDown();
	}

	private function controller(?string $userId = 'alice'): MigrationController {
		return new MigrationController(
			$this->request(),
			$userId,
			$this->archiveService,
			$this->migrationService,
			$this->postImportService,
			$this->accountService,
			new NullLogger(),
		);
	}

	/** A request that answers the parameters the post import reads. */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);

		return $request;
	}

	/** @param array<string, mixed> $file */
	private function upload(array $file): void {
		$_FILES = ['file' => $file + ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/whatever']];
	}

	// export

	public function testExportHandsBackTheArchiveAsADownload(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-export-test');
		file_put_contents($path, 'PK-not-really-a-zip');
		$this->archiveService->method('export')->with('alice')->willReturn($path);
		$this->archiveService->method('filename')->willReturn('social-alice-2026-09-13.zip');

		$response = $this->controller()->export();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('PK-not-really-a-zip', $response->render());
		$this->assertSame(
			'attachment; filename="social-alice-2026-09-13.zip"',
			$response->getHeaders()['Content-Disposition'] ?? ''
		);
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type'] ?? '');
		@unlink($path);
	}

	public function testExportWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->export()->getStatus());
	}

	public function testAFailedExportIsAnErrorRatherThanAnEmptyFile(): void {
		$this->archiveService->method('export')->willThrowException(new UserMigrationException('no room on the disk'));

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'no room on the disk'], $response->getData());
	}

	// import

	public function testImportReadsTheUploadAndReportsWhatItDid(): void {
		$this->upload(['tmp_name' => '/tmp/archive.zip']);
		$this->archiveService->expects($this->once())->method('import')
			->with('alice', '/tmp/archive.zip')
			->willReturn(['Importing the Social profile…']);

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['imported' => true, 'log' => ['Importing the Social profile…']], $response->getData());
	}

	public function testImportWithNoFileSaysSo(): void {
		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no archive was uploaded'], $response->getData());
	}

	public function testAFailedUploadIsNotTreatedAsAnArchive(): void {
		$_FILES = ['file' => ['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '', 'size' => 0]];
		$this->archiveService->expects($this->never())->method('import');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->import()->getStatus());
	}

	/** A refusal that names the limit, rather than a truncated archive. */
	public function testAnOversizedArchiveIsRefusedBeforeItIsOpened(): void {
		$this->upload(['size' => 200 * 1024 * 1024]);
		$this->archiveService->expects($this->never())->method('import');

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertStringContainsString('100 MB', $response->getData()['error']);
	}

	public function testAnArchiveWithoutSocialDataIsABadRequest(): void {
		$this->upload([]);
		$this->archiveService->method('import')
			->willThrowException(new UserMigrationException('this archive holds no Social data'));

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'this archive holds no Social data'], $response->getData());
	}

	public function testImportWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->import()->getStatus());
	}

	// follows from another server

	public function testFollowsAreReadFromTheUploadedCsv(): void {
		$path = tempnam(sys_get_temp_dir(), 'social-follows-test');
		file_put_contents($path, "Account address\nbob@remote.example\n");
		$this->upload(['tmp_name' => $path]);
		$this->migrationService->expects($this->once())->method('importFollows')
			->with('alice', "Account address\nbob@remote.example\n")
			->willReturn(['followed' => 1, 'skipped' => 0, 'failed' => []]);

		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['followed' => 1, 'skipped' => 0, 'failed' => []], $response->getData());
		@unlink($path);
	}

	public function testFollowsWithNoFileSaysSo(): void {
		$response = $this->controller()->importFollows();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no file was uploaded'], $response->getData());
	}

	public function testFollowsWithoutAnAccountIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importFollows()->getStatus());
	}
	public function testImportingPostsHandsTheUploadToTheImporterAndAnswersItsTally(): void {
		$this->withUpload('outbox.json');
		$this->postImportService->expects($this->once())
			->method('import')
			->with($this->isInstanceOf(Person::class), '/tmp/uploaded', true)
			->willReturn([
				'imported' => 12, 'skipped' => 2, 'already' => 0,
				'media' => 5, 'failed' => 0, 'total' => 14, 'capped' => false,
			]);

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(12, $response->getData()['imported']);
	}

	/** Fetching a picture tells the old server the import is happening, so it is a choice. */
	public function testTheReaderCanDeclineTheFetchFromTheOldServer(): void {
		$this->withUpload('pixelfed-statuses.json');
		$this->params = ['fetch_media' => '0'];
		$this->postImportService->expects($this->once())
			->method('import')
			->with($this->anything(), $this->anything(), false)
			->willReturn(['imported' => 1, 'skipped' => 0, 'already' => 0, 'media' => 0, 'failed' => 0, 'total' => 1, 'capped' => false]);

		$this->controller()->importPosts();
	}

	public function testImportingPostsWithoutAnUploadIsABadRequest(): void {
		$this->postImportService->expects($this->never())->method('import');

		$response = $this->controller()->importPosts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testImportingPostsWithoutAnAccountIsUnauthorized(): void {
		$this->postImportService->expects($this->never())->method('import');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->importPosts()->getStatus());
	}

	private function withUpload(string $name): void {
		$_FILES['file'] = [
			'name' => $name,
			'tmp_name' => '/tmp/uploaded',
			'error' => UPLOAD_ERR_OK,
			'size' => 1024,
			'type' => 'application/json',
		];
	}
}
