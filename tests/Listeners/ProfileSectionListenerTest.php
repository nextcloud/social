<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Profile\BeforeTemplateRenderedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileSectionListenerTest extends TestCase {
	private IInitialState $initialState;
	private ActorsRequest $actorsRequest;
	private array $states = [];
	private ?IUser $reader = null;

	protected function setUp(): void {
		$this->initialState = $this->createStub(IInitialState::class);
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states[$key] = $data;
			});
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		// the script registry adds the app's translations next to its script
		$l10n = $this->createStub(IFactory::class);
		$l10n->method('findLanguage')->willReturn('en');
		\OC::$server->register(IFactory::class, $l10n);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function listener(): ProfileSectionListener {
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->reader);

		return new ProfileSectionListener($this->initialState, $this->actorsRequest, $userSession);
	}

	public function testIsAnEventListener(): void {
		$this->assertInstanceOf(IEventListener::class, $this->listener());
	}

	/**
	 * Only the name of the event to build: a static provider cannot call
	 * `createMock()`, and `UserUpdatedEvent` needs an `IUser`.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function unrelatedEvents(): iterable {
		yield 'generic event' => ['generic'];
		yield 'account update' => ['user-updated'];
	}

	#[DataProvider('unrelatedEvents')]
	public function testUnrelatedEventsAreIgnored(string $eventKind): void {
		$event = match ($eventKind) {
			'generic' => new Event(),
			'user-updated' => new UserUpdatedEvent($this->createStub(IUser::class), []),
		};

		$this->listener()->handle($event);

		$this->assertSame([], $this->states);
	}

	/**
	 * The profile page is the Nextcloud user's, by their user id, and the
	 * section asked the API for an account by that id. The username is chosen
	 * when the account is made and need not be the user id, so it found
	 * nobody, or the account somebody else had named that.
	 */
	public function testTheSectionIsToldTheAccountOfTheProfilesUser(): void {
		$this->reader = $this->createStub(IUser::class);
		$actor = (new Person())->setPreferredUsername('aloha_al');
		$this->actorsRequest->method('getFromUserId')->willReturnMap([['alice', $actor]]);

		$this->listener()->handle(new BeforeTemplateRenderedEvent('alice'));

		$this->assertSame('aloha_al', $this->states['profileAccount']);
	}

	/**
	 * Nextcloud renders the profile page, and fires the event, for a visitor
	 * who is not signed in. Telling them the username would map the login in
	 * the page's address to the account named apart from it.
	 */
	public function testAVisitorIsNotToldTheAccountOfALoginItIsNamedApartFrom(): void {
		$actor = (new Person())->setPreferredUsername('aloha_al');
		$this->actorsRequest->method('getFromUserId')->willReturnMap([['alice', $actor]]);

		$this->listener()->handle(new BeforeTemplateRenderedEvent('alice'));

		$this->assertSame([], $this->states);
	}

	/** Where the username is the login, the visitor learns nothing new. */
	public function testAVisitorIsToldAnAccountNamedAsItsLogin(): void {
		$actor = (new Person())->setPreferredUsername('alice');
		$this->actorsRequest->method('getFromUserId')->willReturnMap([['alice', $actor]]);

		$this->listener()->handle(new BeforeTemplateRenderedEvent('alice'));

		$this->assertSame('alice', $this->states['profileAccount']);
	}

	public function testAUserWithoutAnAccountHasNoneToShow(): void {
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());

		$this->listener()->handle(new BeforeTemplateRenderedEvent('bob'));

		$this->assertSame('', $this->states['profileAccount']);
	}
}
