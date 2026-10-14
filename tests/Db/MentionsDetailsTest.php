<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * An edit stores its `mentions` into the `details` blob a post already has,
 * which other writers change too. The write is a compare-and-swap: one that
 * finds the blob changed since it was read reads it again and sets the key
 * on what is there, so neither writer's change is lost.
 */
#[AllowMockObjectsWithoutExpectations]
class MentionsDetailsTest extends TestCase {
	private const ID = 'https://remote.example/notes/1';

	/** @var list<array{?string, string}> what each write expected and wrote */
	private array $writes = [];

	/**
	 * @param list<string|null|false> $reads what each read of the blob answers
	 * @param list<bool> $swaps whether each write finds the blob unchanged
	 */
	private function request(array $reads, array $swaps): StreamRequest&MockObject {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['storedDetails', 'replaceDetails', 'getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn(
			$this->getMockBuilder(SocialQueryBuilder::class)->disableOriginalConstructor()->onlyMethods([])->getMock()
		);
		$request->method('storedDetails')->willReturnOnConsecutiveCalls(...$reads);
		$request->method('replaceDetails')->willReturnCallback(
			function (string $prim, ?string $expected, string $blob) use (&$swaps): bool {
				$this->assertSame(md5(self::ID), $prim);
				$this->writes[] = [$expected, $blob];

				return array_shift($swaps) ?? false;
			}
		);
		(new ReflectionProperty(StreamRequest::class, 'logger'))->setValue($request, new NullLogger());

		return $request;
	}

	private function note(): Note {
		$note = new Note();
		$note->setId(self::ID);
		$note->setDetailArray(Details::MENTIONS, [['id' => 'https://remote.example/users/bob']]);

		return $note;
	}

	public function testTheKeyIsWrittenOverTheBlobThatWasRead(): void {
		$this->request(['{"likes":3}'], [true])->updateMentions($this->note());

		$this->assertCount(1, $this->writes);
		$this->assertSame('{"likes":3}', $this->writes[0][0], 'only if it is still what was read');
		$written = json_decode($this->writes[0][1], true);
		$this->assertSame(3, $written['likes']);
		$this->assertSame('https://remote.example/users/bob', $written[Details::MENTIONS][0]['id']);
	}

	public function testAWriteThatCameInBetweenIsKept(): void {
		$this->request(
			['{"likes":3}', '{"likes":3,"quoteRefused":true}'],
			[false, true]
		)->updateMentions($this->note());

		$this->assertCount(2, $this->writes);
		$this->assertSame('{"likes":3,"quoteRefused":true}', $this->writes[1][0]);
		$written = json_decode($this->writes[1][1], true);
		$this->assertTrue($written['quoteRefused'], 'the other writer\'s change survives');
		$this->assertArrayHasKey(Details::MENTIONS, $written);
	}

	public function testItRetriesOnceAndNoMore(): void {
		$this->request(['{}', '{"a":1}', '{"a":2}'], [false, false, true])->updateMentions($this->note());

		$this->assertCount(2, $this->writes);
	}

	public function testAPostWithNoDetailsIsSwappedFromNothing(): void {
		$this->request([null], [true])->updateMentions($this->note());

		$this->assertNull($this->writes[0][0]);
	}

	public function testAPostThatIsNotThereIsLeftAlone(): void {
		$this->request([false], [])->updateMentions($this->note());

		$this->assertSame([], $this->writes);
	}
}
