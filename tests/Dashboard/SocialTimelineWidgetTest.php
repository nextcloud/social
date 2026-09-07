<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
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
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialTimelineWidgetTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var StreamService&MockObject */
	private $streamService;
	private SocialTimelineWidget $widget;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createMock(StreamService::class);

		$this->widget = new SocialTimelineWidget(
			$this->l10n,
			$this->urlGenerator,
			$this->accountService,
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	/** @return Person&MockObject */
	private function viewer(string $uid = 'alice'): Person {
		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$this->accountService->method('getActorFromUserId')->with($uid, false)->willReturn($account);
		$viewer = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);

		return $viewer;
	}

	/** @return Note&MockObject */
	private function note(string $content, string $attributedTo, int $published): Note {
		$note = $this->createMock(Note::class);
		$note->method('getContent')->willReturn($content);
		$note->method('getAttributedTo')->willReturn($attributedTo);
		$note->method('getPublishedTime')->willReturn($published);

		return $note;
	}

	/** @return Person&MockObject */
	private function author(string $name, string $username, string $avatar): Person {
		$author = $this->createMock(Person::class);
		$author->method('getName')->willReturn($name);
		$author->method('getPreferredUsername')->willReturn($username);
		$author->method('getAvatar')->willReturn($avatar);

		return $author;
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertInstanceOf(IIconWidget::class, $this->widget);
		$this->assertInstanceOf(IButtonWidget::class, $this->widget);
		$this->assertInstanceOf(IReloadableWidget::class, $this->widget);
		$this->assertSame('social_timeline', $this->widget->getId());
		$this->assertSame('Social timeline', $this->widget->getTitle());
		$this->assertSame(11, $this->widget->getOrder());
		$this->assertSame('icon-social', $this->widget->getIconClass());
		$this->assertSame(300, $this->widget->getReloadInterval());
	}

	public function testIconAndUrlComeFromTheUrlGenerator(): void {
		$this->urlGenerator->method('imagePath')->with('social', 'social-dark.svg')->willReturn('/apps/social/img/social-dark.svg');
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.timeline')->willReturn('/apps/social/timeline');

		$this->assertSame('/apps/social/img/social-dark.svg', $this->widget->getIconUrl());
		$this->assertSame('/apps/social/timeline', $this->widget->getUrl());
	}

	public function testMoreButtonOpensTheTimeline(): void {
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.timeline')->willReturn('/apps/social/timeline');

		$buttons = $this->widget->getWidgetButtons('alice');

		$this->assertCount(1, $buttons);
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		$this->assertSame('/apps/social/timeline', $buttons[0]->getLink());
		$this->assertSame('Open timeline', $buttons[0]->getText());
	}

	public function testItemsAreBuiltFromTheViewersHomeTimeline(): void {
		$viewer = $this->viewer();
		$viewer->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [
				$this->note('<p>Hello <b>world</b>, this is a fairly long post that should be truncated at one hundred and twenty characters for the dashboard tile.</p>', 'https://remote.example/users/bob', 1700000001),
				$this->createMock(Announce::class),
				$this->note('', 'https://remote.example/users/carol', 1700000002),
			];
		});
		$this->cacheActorService->method('getFromId')->willReturnMap([
			['https://remote.example/users/bob', false, $this->author('Bob B.', 'bob', 'https://remote.example/bob.png')],
			['https://remote.example/users/carol', false, $this->author('', 'carol', '')],
		]);
		$this->urlGenerator->method('linkToRoute')->with('social.Navigation.timeline', ['path' => 'home'])->willReturn('/apps/social/timeline/home');

		$items = $this->widget->getItemsV2('alice', null, 5);

		$this->assertSame(ProbeOptions::HOME, $options->getProbe());
		$this->assertSame(5, $options->getLimit());
		$this->assertSame('Follow some accounts to see their posts here', $items->getEmptyContentMessage());
		$this->assertSame('No recent posts', $items->getHalfEmptyContentMessage());
		$list = $items->getItems();
		$this->assertCount(2, $list, 'non-Note stream items are skipped');
		$this->assertSame(
			'Hello world, this is a fairly long post that should be truncated at one hundred and twenty characters for the dashboard ',
			$list[0]->getTitle(),
			'markup is stripped before truncating'
		);
		$this->assertSame(120, mb_strlen($list[0]->getTitle()));
		$this->assertSame('Bob B.', $list[0]->getSubtitle());
		$this->assertSame('https://remote.example/bob.png', $list[0]->getIconUrl());
		$this->assertSame('/apps/social/timeline/home', $list[0]->getLink());
		$this->assertSame('1700000001', $list[0]->getSinceId());
		$this->assertSame('(no content)', $list[1]->getTitle());
		$this->assertSame('carol', $list[1]->getSubtitle(), 'falls back to the username without a display name');
	}

	public function testLimitIsCappedAtTwenty(): void {
		$this->viewer();
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$this->widget->getItemsV2('alice', null, 50);

		$this->assertSame(20, $options->getLimit());
	}

	public function testUnresolvableAuthorFallsBackToTheActorId(): void {
		$this->viewer();
		$this->streamService->method('getTimeline')->willReturn([$this->note('hi', 'https://remote.example/users/gone', 1)]);
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$items = $this->widget->getItemsV2('alice')->getItems();

		$this->assertSame('https://remote.example/users/gone', $items[0]->getSubtitle());
		$this->assertSame('', $items[0]->getIconUrl());
	}

	public function testFailuresYieldAnEmptyWidgetWithAnErrorMessage(): void {
		$this->accountService->method('getActorFromUserId')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->expects($this->never())->method('getTimeline');

		$items = $this->widget->getItemsV2('nobody');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load timeline', $items->getEmptyContentMessage());
		$this->assertSame('Could not load timeline', $items->getHalfEmptyContentMessage());
	}
}
