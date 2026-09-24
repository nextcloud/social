<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\SwitchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

class SwitchServiceTest extends TestCase {
	private CacheActorService|MockObject $cacheActorService;
	private SwitchService $service;

	/** @var string[] */
	private array $temps = [];

	protected function setUp(): void {
		parent::setUp();

		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->service = new SwitchService($this->cacheActorService, new NullLogger());
	}

	protected function tearDown(): void {
		foreach ($this->temps as $temp) {
			@unlink($temp);
		}
		parent::tearDown();
	}

	/** @param array<string, string> $files */
	private function archive(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'switch') . '.zip';
		$this->temps[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($files as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();

		return $path;
	}

	/**
	 * Instagram's following list, in the shape the export writes.
	 *
	 * @param string[] $names
	 */
	private function following(array $names): string {
		return json_encode([
			'relationships_following' => array_map(static fn (string $name): array => [
				'title' => '',
				'media_list_data' => [],
				'string_list_data' => [[
					'href' => 'https://www.instagram.com/' . $name,
					'value' => $name,
					'timestamp' => 1719662400,
				]],
			], $names),
		]);
	}

	private function actor(string $account, string $name): Person {
		$actor = new Person();
		$actor->setId('https://threads.net/users/' . explode('@', $account)[0]);
		$actor->setAccount($account);
		$actor->setDisplayName($name);

		return $actor;
	}

	// candidates()

	/**
	 * The one mapping that exists between these networks and this one: an
	 * Instagram name is a Threads name, and Threads federates.
	 */
	public function testAnInstagramFollowListBecomesThreadsHandles(): void {
		$path = $this->archive([
			'connections/followers_and_following/following.json'
				=> $this->following(['naturephotos', 'someband']),
		]);

		$read = $this->service->candidates($path);

		$this->assertSame('instagram', $read['network']);
		$this->assertSame(
			['naturephotos@threads.net', 'someband@threads.net'], $read['handles']
		);
	}

	/** The file moved in 2023 and the archives on people's disks did not. */
	public function testTheOlderLayoutIsReadToo(): void {
		$path = $this->archive([
			'followers_and_following/following.json' => $this->following(['someone']),
		]);

		$this->assertSame(['someone@threads.net'], $this->service->candidates($path)['handles']);
	}

	/** A browser saves the archive into a folder named for the account and the day. */
	public function testAnArchiveUnpackedIntoAFolderIsStillRead(): void {
		$path = $this->archive([
			'instagram-alice-2026-01-01/connections/followers_and_following/following.json'
				=> $this->following(['someone']),
		]);

		$this->assertSame(['someone@threads.net'], $this->service->candidates($path)['handles']);
	}

	/** Two entries for one account are one account. */
	public function testTheSameNameTwiceIsOneCandidate(): void {
		$path = $this->archive([
			'following.json' => $this->following(['someone', 'Someone', 'someone']),
		]);

		$this->assertSame(['someone@threads.net'], $this->service->candidates($path)['handles']);
	}

	/**
	 * Anything that is not a name a handle can be built from is left out
	 * rather than carried into a webfinger request.
	 */
	public function testANameThatCannotBeHalfOfAHandleIsLeftOut(): void {
		$path = $this->archive([
			'following.json' => $this->following(['someone', 'not a name', 'ok_name.1']),
		]);

		$this->assertSame(
			['someone@threads.net', 'ok_name.1@threads.net'],
			$this->service->candidates($path)['handles']
		);
	}

	/** The username is read out of the profile address when the field is empty. */
	public function testTheProfileAddressStandsInForAMissingUsername(): void {
		$path = $this->archive([
			'following.json' => json_encode([
				'relationships_following' => [[
					'string_list_data' => [[
						'href' => 'https://www.instagram.com/fromthehref/',
						'value' => '',
					]],
				]],
			]),
		]);

		$this->assertSame(['fromthehref@threads.net'], $this->service->candidates($path)['handles']);
	}

	/**
	 * The HTML download is the option people pick because it is the one they
	 * can open, and being told which to ask for is the difference between a
	 * two-minute fix and giving up.
	 */
	public function testTheHtmlDownloadSaysWhatToAskInstagramFor(): void {
		$path = $this->archive([
			'connections/followers_and_following/following.html' => '<html></html>',
		]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/HTML download/');
		$this->service->candidates($path);
	}

	/** X lists the accounts it follows by number, so there is nobody to look up. */
	public function testAnXArchiveSaysWhyItsFollowListIsNoUse(): void {
		$path = $this->archive([
			'data/following.js' => 'window.YTD.following.part0 = '
				. json_encode([['following' => ['accountId' => '1526228120']]]),
		]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/by number and not by name/');
		$this->service->candidates($path);
	}

	public function testSomethingThatIsNotAZipIsSaidSo(): void {
		$path = tempnam(sys_get_temp_dir(), 'switch');
		$this->temps[] = $path;
		file_put_contents($path, 'not a zip');

		$this->expectException(InvalidResourceException::class);
		$this->service->candidates($path);
	}

	// probe()

	public function testOnlyTheNamesThatResolveComeBack(): void {
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $account): Person {
				if ($account !== 'here@threads.net') {
					throw new CacheActorDoesNotExistException();
				}

				return $this->actor('here@threads.net', 'Someone Here');
			});

		$result = $this->service->probe(['here@threads.net', 'gone@threads.net']);

		$this->assertSame(2, $result['checked']);
		$this->assertCount(1, $result['found']);
		$this->assertSame('here@threads.net', $result['found'][0]['handle']);
		$this->assertSame('Someone Here', $result['found'][0]['name']);
	}

	/**
	 * Each lookup is a request to a server that did not ask to be crawled, so
	 * a long list is walked in batches with the person watching.
	 */
	public function testOneCallLooksUpAtMostABatch(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new CacheActorDoesNotExistException());
		$handles = [];
		for ($i = 0; $i < SwitchService::PROBE_BATCH * 3; $i++) {
			$handles[] = 'name' . $i . '@threads.net';
		}

		$this->assertSame(SwitchService::PROBE_BATCH, $this->service->probe($handles)['checked']);
	}

	/**
	 * A name that is not a handle costs nothing and is not counted, so it
	 * cannot push a real one out of the batch.
	 */
	public function testSomethingThatIsNotAHandleIsNotLookedUp(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$result = $this->service->probe(['', 'noatsign', '@']);

		$this->assertSame(0, $result['checked']);
		$this->assertSame([], $result['found']);
	}

	/** A server that does not answer is the ordinary case, not an error. */
	public function testAServerThatDoesNotAnswerIsSimplyNotFound(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new \RuntimeException('timed out'));

		$this->assertSame([], $this->service->probe(['someone@threads.net'])['found']);
	}

	// announcement()

	/**
	 * The one thing this app cannot do: none of those networks lets another
	 * site post, so what is offered is the words and the address.
	 */
	public function testTheAnnouncementNamesTheAccountAndItsAddress(): void {
		$actor = $this->actor('alice@cloud.example', 'Alice');
		$actor->setId('https://cloud.example/apps/social/@alice');

		$said = $this->service->announcement($actor);

		$this->assertSame('@alice@cloud.example', $said['handle']);
		$this->assertSame('https://cloud.example/apps/social/@alice', $said['url']);
		$this->assertStringContainsString('@alice@cloud.example', $said['text']);
		$this->assertStringContainsString('https://cloud.example/apps/social/@alice', $said['text']);
	}
}
