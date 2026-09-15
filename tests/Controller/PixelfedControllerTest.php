<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\PixelfedController;
use OCA\Social\Db\DiscoverCategoriesRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Suggestion;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ArchiveService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PixelfedConfigService;
use OCA\Social\Service\PixelfedService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\StoryInteractionService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routes only the Pixelfed app asks for.
 *
 * What is checked is what a client can tell apart from the outside: that the
 * bootstrap call answers somebody who has not signed in yet — the app asks
 * before anybody has — that the discover routes ask the *shared* services rather
 * than any ranking of their own, and that "accounts you might follow" still
 * requires a viewer, because there is no anonymous answer to that question.
 */
class PixelfedControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const OTHER = 'https://cloud.example/users/bob';

	/** @var IRequest&MockObject */
	private $request;
	private IUserSession|MockObject $userSession;
	private AccountService|MockObject $accountService;
	private ClientService|MockObject $clientService;
	private PixelfedConfigService|MockObject $pixelfedConfigService;
	private TrendService|MockObject $trendService;
	private SuggestionService|MockObject $suggestionService;
	private HashtagService|MockObject $hashtagService;
	private PixelfedService|MockObject $pixelfedService;
	private StoryService|MockObject $storyService;
	private StoryInteractionService|MockObject $storyInteractionService;
	private ArchiveService|MockObject|null $archiveService = null;

	private bool $hasSession = true;
	private bool $csrf = true;

	/** @var array{period: string, limit: int, offset: int, onlyMedia: bool}|null */
	private ?array $trendAsked = null;
	/** @var array{limit: int, period: string}|null */
	private ?array $tagsAsked = null;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')
			->willReturnCallback(fn (): ?IUser => $this->hasSession ? $user : null);

		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(fn (): Person => $this->person(self::VIEWER));

		$this->clientService = $this->createMock(ClientService::class);

		$this->pixelfedConfigService = $this->createMock(PixelfedConfigService::class);
		$this->pixelfedConfigService->method('config')
			->willReturn(['open_registration' => false, 'uploader' => ['album_limit' => 10]]);

		$this->trendService = $this->createMock(TrendService::class);
		$this->trendService->method('trendingStatuses')
			->willReturnCallback(
				function (string $period, int $limit, int $offset, bool $onlyMedia = false): array {
					$this->trendAsked = compact('period', 'limit', 'offset', 'onlyMedia');

					return [$this->note()];
				}
			);

		$this->suggestionService = $this->createMock(SuggestionService::class);
		$this->suggestionService->method('suggestions')
			->willReturn([new Suggestion($this->person(self::OTHER), Suggestion::SOURCE_FRIENDS)]);

		$this->pixelfedService = $this->createMock(PixelfedService::class);
		$this->storyService = $this->createMock(StoryService::class);
		$this->storyInteractionService = $this->createMock(StoryInteractionService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->hashtagService->method('getTrending')
			->willReturnCallback(function (int $limit, string $period): array {
				$this->tagsAsked = compact('limit', 'period');

				return [['name' => 'coast']];
			});
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function note(): Note {
		$note = new Note();
		$note->setId('https://cloud.example/notes/1');
		$note->setAttributedTo(self::VIEWER);

		return $note;
	}

	private function controller(): PixelfedController {
		$this->archiveService ??= $this->createMock(ArchiveService::class);

		return new PixelfedController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->pixelfedConfigService,
			$this->trendService,
			$this->suggestionService,
			$this->hashtagService,
			$this->createMock(LinkPreviewService::class),
			$this->createMock(PlaceService::class),
			$this->pixelfedService,
			$this->storyService,
			$this->storyInteractionService,
			$this->archiveService,
			$this->createMock(DiscoverCategoriesRequest::class)
		);
	}

	/**
	 * The app asks for this before anybody has signed in, to decide whether it
	 * can talk to this server at all. Requiring a viewer would mean it could
	 * never get far enough to offer a login.
	 */
	public function testTheBootstrapConfigAnswersSomebodyWhoHasNotSignedIn(): void {
		$this->hasSession = false;

		$response = $this->controller()->config();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['open_registration']);
	}

	public function testPopularAccountsNeedsAViewer(): void {
		$this->hasSession = false;

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED, $this->controller()->popularAccounts()->getStatus()
		);
	}

	/**
	 * Mastodon wraps each account in a {source, account} suggestion; Pixelfed
	 * sends the accounts themselves. One list, two shapes.
	 */
	public function testPopularAccountsUnwrapsTheSuggestions(): void {
		$response = $this->controller()->popularAccounts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$accounts = $response->getData();
		$this->assertCount(1, $accounts);
		$this->assertInstanceOf(Person::class, $accounts[0]);
		$this->assertSame(self::OTHER, $accounts[0]->getId());
	}

	/** A discover grid is pictures; a text post is a poor thing to put in one. */
	public function testDiscoverAsksForPostsWithPicturesOnly(): void {
		$this->controller()->discoverPosts();

		$this->assertNotNull($this->trendAsked);
		$this->assertTrue($this->trendAsked['onlyMedia'], 'discover asked for text posts as well');
	}

	public function testDiscoverAnswersSomebodyWhoHasNotSignedIn(): void {
		$this->hasSession = false;

		$response = $this->controller()->discoverPosts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testDiscoverExportsInTheClientFormat(): void {
		$response = $this->controller()->discoverPosts();

		/** @var Stream[] $statuses */
		$statuses = $response->getData();
		$this->assertSame(Stream::FORMAT_LOCAL, $statuses[0]->getExportFormat());
	}

	/** A caller cannot ask for a bigger page than the route offers. */
	public function testThePageSizeIsBounded(): void {
		$this->controller()->discoverPosts(500);
		$this->assertLessThanOrEqual(20, $this->trendAsked['limit']);

		$this->controller()->discoverPosts(0);
		$this->assertGreaterThanOrEqual(1, $this->trendAsked['limit']);

		$this->controller()->discoverHashtags(500);
		$this->assertLessThanOrEqual(20, $this->tagsAsked['limit']);
	}

	public function testANegativeOffsetCannotReachTheQuery(): void {
		$this->controller()->discoverPosts(20, -50);

		$this->assertSame(0, $this->trendAsked['offset']);
	}

	public function testTheHashtagRowIsTheSameTrendingTags(): void {
		$response = $this->controller()->discoverHashtags();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['name' => 'coast']], $response->getData());
	}
	/** The app puts the story id in the body and expects Pixelfed's `{code}` back. */
	public function testMarkingAStorySeenAnswersInPixelfedsShape(): void {
		$this->storyService->expects($this->once())->method('markSeen')
			->with($this->isInstanceOf(Person::class), 42);

		$response = $this->controller()->storiesSeen(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['code' => 200], $response->getData());
	}

	public function testAReportIsFiledThroughTheServiceAndAcknowledgedTheWayTheAppReadsIt(): void {
		$this->pixelfedService->expects($this->once())->method('report')
			->with($this->isInstanceOf(Person::class), 'spam', '17', 'post', 'buy things');

		$response = $this->controller()->report('spam', '17', 'post', 'buy things');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(200, $response->getData()['code']);
	}

	/** A reason the service does not know is a 422, in Mastodon's error shape. */
	public function testAnUnknownReportReasonIsRefused(): void {
		$this->pixelfedService->method('report')
			->willThrowException(new \OCA\Social\Exceptions\InvalidResourceException('unknown report_type'));

		$response = $this->controller()->report('rude', '17', 'post', '');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	/** An account nobody here knows is a 404, whatever the app called it by. */
	public function testAnUnknownUsernameIsNotFound(): void {
		$this->pixelfedService->method('resolveAccount')
			->willThrowException(new \OCA\Social\Exceptions\CacheActorDoesNotExistException());

		$response = $this->controller()->accountByUsername('nobody');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/** Push is answered as off, and enabling it answers with what is actually the case. */
	public function testThePushRoutesTellTheTruth(): void {
		$this->pixelfedService->method('pushState')->willReturn(['notify_enabled' => false, 'has_token' => false]);

		$state = $this->controller()->pushState();

		$this->assertSame(Http::STATUS_OK, $state->getStatus());
		$this->assertFalse($state->getData()['notify_enabled']);
	}

	/** Every one of these needs somebody signed in; without one it is a 401, not a 500. */
	public function testTheViewerRoutesRefuseAnAnonymousCaller(): void {
		$this->hasSession = false;

		foreach ([
			fn () => $this->controller()->storiesCarousel(),
			fn () => $this->controller()->collectionsSelf(),
			fn () => $this->controller()->accountMutuals('7'),
			fn () => $this->controller()->composeSettings(),
			fn () => $this->controller()->nagState(),
			fn () => $this->controller()->pushCompare(),
		] as $call) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $call()->getStatus());
		}
	}

	/** The app's trend ranges land on the windows this server counts. */
	public function testTheAppsTrendRangesMapOntoThisServersWindows(): void {
		$this->controller()->discoverPostsTrending('daily');
		$this->assertSame('1d', $this->trendAsked['period']);

		$this->controller()->discoverNetworkTrending('yearly');
		$this->assertSame('10d', $this->trendAsked['period']);
	}
	public function testTheThreadRoutesGoThroughTheService(): void {
		$this->pixelfedService->expects($this->once())->method('thread')
			->with($this->isInstanceOf(Person::class), '7', 0, 40)
			->willReturn(['id' => '7', 'messages' => []]);
		$this->pixelfedService->expects($this->once())->method('deleteMessage')
			->with($this->isInstanceOf(Person::class), 12);

		$this->assertSame('7', $this->controller()->directThread('7', 0, 40)->getData()['id']);
		$this->assertSame([200], $this->controller()->directThreadDelete(12)->getData());
	}
}
