<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Suggestion;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\SuggestionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who may not be suggested.
 *
 * That is the half of this feature that can be wrong in a way the user
 * notices: a suggestion to follow somebody you blocked is the instance
 * overruling the one decision you made about them, and a suggestion to follow
 * somebody you already follow is the instance admitting it did not look.
 *
 * Every exclusion is checked against both halves of the list — the follow
 * graph and the locally active fallback — because they are gathered by
 * different queries and a filter applied to only one of them would let the
 * same account back in through the other.
 */
class SuggestionServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private DiscoveryRequest|MockObject $discoveryRequest;
	private SuggestionService $service;

	/** @var string[] actor ids the graph walk offers */
	private array $friends = [];
	/** @var string[] actor ids the locally-active fallback offers */
	private array $active = [];
	/** @var string[] actor ids the viewer already follows */
	private array $follows = [];
	/** @var string[] actor ids blocked, muted, or blocking the viewer */
	private array $related = [];
	/** @var string[] actor ids a moderator has decided about */
	private array $moderated = [];
	/** @var string[][] the prim lists the graph walk and the fallback were asked for */
	private array $asked = [];

	protected function setUp(): void {
		$this->discoveryRequest = $this->createMock(DiscoveryRequest::class);

		$this->discoveryRequest->method('friendsOfFriendsPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->friends));
		$this->discoveryRequest->method('activeLocalPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->active));
		$this->discoveryRequest->method('followedPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->follows));
		$this->discoveryRequest->method('relatedPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->related));
		$this->discoveryRequest->method('actorsByPrims')
			->willReturnCallback(function (array $prims): array {
				$this->asked[] = $prims;

				return array_map(static function (string $prim): Person {
					$person = new Person();
					$person->setId('actor:' . $prim);

					return $person;
				}, $prims);
			});

		$directoryService = $this->createMock(DirectoryService::class);
		$directoryService->method('withoutModerated')
			->willReturnCallback(function (array $prims): array {
				$moderated = array_map('md5', $this->moderated);

				return array_values(array_filter(
					$prims, static fn (string $prim): bool => !in_array($prim, $moderated, true)
				));
			});

		$this->service = new SuggestionService($this->discoveryRequest, $directoryService);
	}

	/** @return string[] the actor ids suggested, in order */
	private function suggested(int $limit = SuggestionService::LIMIT): array {
		return array_map(
			static fn (Suggestion $s): string => $s->getAccount()->getId(),
			$this->service->suggestions(self::VIEWER, $limit)
		);
	}

	private function id(string $handle): string {
		return 'actor:' . md5($handle);
	}

	public function testTheGraphWalkIsTheFirstHalfOfTheList(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	public function testAnAccountAlreadyFollowedIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['bob', 'carol'];
		$this->follows = ['bob'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	public function testABlockedOrMutedAccountIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];
		$this->related = ['bob', 'carol'];

		$this->assertSame([], $this->suggested());
	}

	/** All three directions of `social_actor_relation` are asked for. */
	public function testBlocksMutesAndBeingBlockedAreAllAskedFor(): void {
		$asked = null;
		$this->discoveryRequest = $this->createMock(DiscoveryRequest::class);
		$this->discoveryRequest->method('relatedPrims')
			->willReturnCallback(function (string $viewerId, array $types) use (&$asked): array {
				$asked = $types;

				return [];
			});

		$directoryService = $this->createMock(DirectoryService::class);
		$directoryService->method('withoutModerated')->willReturnArgument(0);

		(new SuggestionService($this->discoveryRequest, $directoryService))
			->suggestions(self::VIEWER, SuggestionService::LIMIT);

		$this->assertSame(
			[ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_MUTE, ActorRelation::TYPE_BLOCKED_BY],
			$asked
		);
	}

	public function testTheViewerIsNeverSuggestedToThemselves(): void {
		$this->friends = [self::VIEWER];
		$this->active = [self::VIEWER, 'carol'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	public function testAModeratedAccountIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];
		$this->moderated = ['bob'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	/** The fallback fills what the graph left, and never duplicates it. */
	public function testTheFallbackDoesNotRepeatTheGraph(): void {
		$this->friends = ['bob'];
		$this->active = ['bob', 'carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	public function testTheFallbackIsNotAskedForMoreThanTheListNeeds(): void {
		$this->friends = ['bob', 'carol'];
		$this->active = ['dave', 'erin'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested(2));
	}

	public function testANewAccountWithNoGraphStillGetsSuggestions(): void {
		$this->active = ['bob', 'carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	/** Each half says where it came from, in both the old field and the new. */
	public function testEachSuggestionSaysWhereItCameFrom(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];

		$suggestions = $this->service->suggestions(self::VIEWER, SuggestionService::LIMIT);

		$this->assertSame(
			['friends_of_friends'], $suggestions[0]->jsonSerialize()['sources']
		);
		$this->assertSame('past_interactions', $suggestions[0]->jsonSerialize()['source']);
		$this->assertSame(['most_interactions'], $suggestions[1]->jsonSerialize()['sources']);
		$this->assertSame('global', $suggestions[1]->jsonSerialize()['source']);
	}

	public function testTheEntityIsASuggestion(): void {
		$this->active = ['bob'];

		$suggestion = $this->service->suggestions(self::VIEWER, SuggestionService::LIMIT)[0];

		$this->assertSame(
			['source', 'sources', 'account'], array_keys($suggestion->jsonSerialize())
		);
	}

	public function testTheListIsCappedAtWhatMastodonCapsItAt(): void {
		$this->active = array_map(static fn (int $i): string => 'user' . $i, range(1, 200));

		$this->assertCount(
			SuggestionService::MAX_LIMIT, $this->service->suggestions(self::VIEWER, 500)
		);
	}
}
