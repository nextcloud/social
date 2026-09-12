<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Report;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\ReportForwardService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Forwarding a report to the instance that hosts the reported account.
 *
 * The thing these tests exist to pin is *who the forward names*: Mastodon
 * forwards anonymised so that the reporter is not handed to the admins of the
 * instance they just reported, and a regression that put the reporter's id
 * back into the Flag would be invisible from here — it is the receiving
 * instance that would see it.
 */
class ReportForwardServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const SPAMMER = 'https://spam.example/users/spammer';
	private const INBOX = 'https://spam.example/users/spammer/inbox';
	private const INSTANCE = 'https://cloud.example/apps/social/actor';

	private InstanceActorService|MockObject $instanceActorService;
	private CurlService|MockObject $curlService;
	private ReportForwardService $service;
	private string $privateKey = '';
	/** @var list<array{method: string, url: string, options: array}> every request handed to curl */
	private array $sent = [];

	protected function setUp(): void {
		$this->instanceActorService = $this->createMock(InstanceActorService::class);
		$this->curlService = $this->createMock(CurlService::class);

		$this->service = new ReportForwardService(
			$this->instanceActorService,
			new HttpSignatureService(
				$this->createMock(\OCA\Social\Db\ActorsRequest::class),
				$this->instanceActorService,
				new NullLogger()
			),
			$this->curlService,
			new NullLogger()
		);
	}

	private function instanceActor(): InstanceActor {
		if ($this->privateKey === '') {
			$res = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($res, $this->privateKey);
		}

		$actor = new InstanceActor();
		$actor->setId(self::INSTANCE)->setPrivateKey($this->privateKey)->setLocal(true);

		return $actor;
	}

	private function remote(string $id = self::SPAMMER, string $inbox = self::INBOX): Person {
		$person = new Person();
		$person->setId($id)->setLocal(false);
		$person->setInbox($inbox);

		return $person;
	}

	private function report(): Report {
		$report = new Report();
		$report->setId(7)
			->setActorId(self::ALICE)
			->setAccountId(self::SPAMMER)
			->setStatusIds([self::SPAMMER . '/statuses/1'])
			->setComment('this is spam')
			->setLocal(true);

		return $report;
	}

	private function captureDelivery(): void {
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options): array {
				$this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return [];
			});
	}

	private function body(): string {
		return $this->sent[0]['options']['body'];
	}

	/** @return array<string, string> */
	private function headers(): array {
		return $this->sent[0]['options']['headers'];
	}

	public function testTheForwardedFlagNamesTheInstanceAndNotTheReporter(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->assertTrue($this->service->forward($this->report(), $this->remote()));

		$body = json_decode($this->body(), true);
		$this->assertSame('Flag', $body['type']);
		$this->assertSame(self::INSTANCE, $body['actor'], 'the instance forwards, not the reporter');
		$this->assertStringNotContainsString(
			self::ALICE, $this->body(),
			'the reporter must not be exposed to the instance they reported'
		);
	}

	public function testTheFlagCarriesTheAccountThenTheReportedStatusesAndTheComment(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$body = json_decode($this->body(), true);
		$this->assertSame([self::SPAMMER, self::SPAMMER . '/statuses/1'], $body['object']);
		$this->assertSame('this is spam', $body['content']);
		$this->assertSame(self::SPAMMER, $body['to']);
	}

	public function testTheDeliveryIsSignedWithTheInstanceActorsKeyOverTheBody(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$headers = $this->headers();
		$this->assertArrayHasKey('Signature', $headers);
		$this->assertStringContainsString(
			'keyId="' . self::INSTANCE . '#main-key"', $headers['Signature']
		);
		// Mastodon refuses a POST whose signature does not cover the body
		$this->assertStringContainsString(
			'headers="(request-target) content-length date host digest"', $headers['Signature']
		);
		$this->assertSame(
			'SHA-256=' . base64_encode(hash('sha256', $this->body(), true)),
			$headers['digest']
		);
		$this->assertSame((string)strlen($this->body()), $headers['content-length']);
	}

	/** The timeout the user's own request can afford to wait for. */
	public function testTheForwardHasAShortTimeout(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$this->assertSame(3, $this->sent[0]['options']['timeout']);
	}

	public function testTheDeliveryGoesToTheReportedAccountsInbox(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$this->assertSame('post', $this->sent[0]['method']);
		$this->assertSame(self::INBOX, $this->sent[0]['url']);
	}

	public function testThePersonalInboxIsPreferredOverTheSharedOne(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$target = $this->remote();
		$target->setSharedInbox('https://spam.example/inbox');

		$this->service->forward($this->report(), $target);

		// a Flag is about one account; the shared inbox is the fallback for an
		// instance that publishes no personal one
		$this->assertSame(self::INBOX, $this->sent[0]['url']);
	}

	public function testAnEmptyBodyFromTheInboxIsASuccess(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->method('retrieveJson')->willThrowException(new RequestResultNotJsonException());

		// an inbox answers 202 with no body; that is acceptance, not failure
		$this->assertTrue($this->service->forward($this->report(), $this->remote()));
	}

	public function testAnUnreachableInstanceIsNotReportedAsForwarded(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());

		$this->assertFalse($this->service->forward($this->report(), $this->remote()));
	}

	public function testNothingIsSentWhenTheInstanceCannotSignAsItself(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn(null);
		$this->curlService->expects($this->never())->method('retrieveJson');

		$this->assertFalse($this->service->forward($this->report(), $this->remote()));
	}

	public function testALocalAccountIsNotForwardedAnywhere(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		$local = new Person();
		$local->setId(self::ALICE)->setLocal(true);
		$local->setInbox(self::ALICE . '/inbox');

		$this->assertFalse($this->service->forward($this->report(), $local));
	}

	public function testAReportThatArrivedAsAFlagIsNeverForwardedOn(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		// forwarding somebody else's report would put this instance's name on
		// it, and two instances doing that to each other is a loop
		$this->assertFalse($this->service->forward($this->report()->setLocal(false), $this->remote()));
	}

	public function testAnAccountWithNoInboxCannotBeForwardedTo(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		$this->assertFalse($this->service->forward($this->report(), $this->remote(self::SPAMMER, '')));
	}

	public function testTheSharedInboxIsUsedWhenThereIsNoPersonalOne(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$target = $this->remote(self::SPAMMER, '');
		$target->setSharedInbox('https://spam.example/inbox');

		$this->assertTrue($this->service->forward($this->report(), $target));
		$this->assertSame('https://spam.example/inbox', $this->sent[0]['url']);
	}
}
