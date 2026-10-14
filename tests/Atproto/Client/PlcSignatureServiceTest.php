<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\PlcSignatureService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The PLC signature a tool moving an account away asks for: the e-mailed
 * code, and the operation signed with it.
 */
#[AllowMockObjectsWithoutExpectations]
class PlcSignatureServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const HEAD = 'bafyreihead';

	private PrivateKey $instanceKey;
	private Identity $alice;
	/** @var array<string, string> the user values, by key */
	private array $values = [];
	/** @var list<string> the bodies of the e-mails sent */
	private array $mailed = [];
	private string $email = 'alice@example.org';
	private int $now = 1760000000;
	private bool $limited = false;
	private array $pending = [];
	private array $head;
	private PlcSignatureService $service;

	protected function setUp(): void {
		$this->instanceKey = PrivateKey::generate(Curve::K256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:zSigning', 'did:key:zRecovery', Identity::STATE_ACTIVE, '', 0);
		$this->head = [
			'type' => PlcOperation::TYPE_OPERATION,
			'rotationKeys' => ['did:key:zRecovery', $this->instanceKey->didKey()],
			'verificationMethods' => ['atproto' => 'did:key:zSigning'],
			'alsoKnownAs' => ['at://alice.social.test'],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => 'https://social.test']],
			'prev' => 'bafyreigenesis',
		];
		$log = $this->createMock(AtprotoPlcLogRequest::class);
		$log->method('confirmedCid')->willReturn(self::HEAD);
		$log->method('getPending')->willReturnCallback(fn (): array => $this->pending);
		$log->method('getByDid')->willReturnCallback(fn (): array => [['cid' => self::HEAD, 'operation' => $this->head, 'confirmed' => 1, 'dead' => 0]]);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('rotationKeys')->willReturnCallback(fn (): array => [$this->instanceKey]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getEMailAddress')->willReturnCallback(fn (): string => $this->email);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('addBodyText')->willReturnCallback(function (string $text): void {
			$this->mailed[] = $text;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturn($template);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('send')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$config = $this->createMock(IConfig::class);
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, string $value): void {
			$this->values[$key] = $value;
		});
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default);
		$config->method('deleteUserValue')->willReturnCallback(function (string $uid, string $app, string $key): void {
			unset($this->values[$key]);
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('ABCDE23456');
		$limiter = $this->createMock(ILimiter::class);
		$limiter->method('registerUserRequest')->willReturnCallback(function (): void {
			if ($this->limited) {
				throw new class extends \Exception implements IRateLimitExceededException {
				};
			}
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->service = new PlcSignatureService(
			$log, $keys, new Lexicon(), $users, $mailer, $factory, $config, $random, $limiter, $time, new NullLogger(),
		);
	}

	private function session(array $scopes = ['atproto', 'identity:*']): ClientSession {
		return new ClientSession('alice', $this->alice, 'sid', $scopes);
	}

	private function assertRefused(int $status, string $error, callable $call): void {
		try {
			$call();
			$this->fail('answered');
		} catch (XrpcException $e) {
			$this->assertSame([$status, $error], [$e->status, $e->error]);
		}
	}

	public function testTheCodeIsMailedAndKeptOnlyHashed(): void {
		$this->assertSame([], $this->service->procedure($this->session(), PlcSignatureService::REQUEST, []));

		$this->assertContains('ABCDE-23456', $this->mailed);
		$stored = $this->values[PlcSignatureService::TOKEN_KEY];
		$this->assertStringNotContainsString('ABCDE', $stored);
		$this->assertSame($this->now + 900, json_decode($stored, true)['expires'], 'fifteen minutes, as the reference PDS');
	}

	public function testAnAppPasswordOrANarrowerGrantIsNotEnough(): void {
		foreach ([['atproto', 'transition:generic', 'transition:email'], ['atproto', 'identity:handle']] as $scopes) {
			$this->assertRefused(403, 'InsufficientScope', fn () => $this->service->procedure($this->session($scopes), PlcSignatureService::REQUEST, []));
			$this->assertRefused(403, 'InsufficientScope', fn () => $this->service->procedure($this->session($scopes), PlcSignatureService::SIGN, ['token' => 'x']));
		}
		$this->assertSame([], $this->mailed);
	}

	public function testAnAccountWithoutAnAddressGetsNoCode(): void {
		$this->email = '';

		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::REQUEST, []));
	}

	public function testTheProposedOperationIsSignedWithThisServersKeyKeepingTheRecoveryKeyFirst(): void {
		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);
		$proposed = [
			'token' => 'abcde-23456',
			'rotationKeys' => ['did:key:zNewServer'],
			'verificationMethods' => ['atproto' => 'did:key:zNewSigning'],
			'alsoKnownAs' => ['at://alice.pds.example.com'],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => 'https://pds.example.com']],
		];

		$operation = $this->service->procedure($this->session(), PlcSignatureService::SIGN, $proposed)['operation'];

		$this->assertSame(['did:key:zRecovery', 'did:key:zNewServer'], $operation['rotationKeys']);
		$this->assertSame(['atproto' => 'did:key:zNewSigning'], $operation['verificationMethods']);
		$this->assertSame(['at://alice.pds.example.com'], $operation['alsoKnownAs']);
		$this->assertSame('https://pds.example.com', $operation['services']['atproto_pds']['endpoint']);
		$this->assertSame(self::HEAD, $operation['prev']);
		$this->assertTrue(PlcOperation::verify($operation, $this->instanceKey->publicKey()));

		$this->assertRefused(400, 'InvalidToken', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, $proposed));
	}

	public function testWhatIsNotProposedStaysAsItIs(): void {
		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);

		$operation = $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456', 'alsoKnownAs' => ['at://alice.example.org']])['operation'];

		$this->assertSame($this->head['rotationKeys'], $operation['rotationKeys']);
		$this->assertSame($this->head['services'], $operation['services']);
		$this->assertSame(['at://alice.example.org'], $operation['alsoKnownAs']);
	}

	public function testAWrongOrExpiredCodeSignsNothing(): void {
		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, []));
		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);
		$this->assertRefused(400, 'InvalidToken', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ZZZZZ-ZZZZZ']));

		$this->now += 901;
		$this->assertRefused(400, 'ExpiredToken', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456']));
	}

	public function testWhatTheLexiconDoesNotAllowIsRefused(): void {
		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);

		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456', 'rotationKeys' => 'did:key:zOne']));
		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456', 'services' => ['a', 'b']]));
	}

	public function testNothingIsSignedWhileAnOperationIsPendingOrWithoutAKeyThatCounts(): void {
		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);
		$this->pending = [['cid' => 'bafyreipending']];
		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456']));

		$this->service->procedure($this->session(), PlcSignatureService::REQUEST, []);
		$this->pending = [];
		$this->head['rotationKeys'] = ['did:key:zRecovery'];
		$this->assertRefused(400, 'InvalidRequest', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456']));
	}

	public function testBothAreRateLimited(): void {
		$this->limited = true;

		$this->assertRefused(429, 'RateLimitExceeded', fn () => $this->service->procedure($this->session(), PlcSignatureService::REQUEST, []));
		$this->assertRefused(429, 'RateLimitExceeded', fn () => $this->service->procedure($this->session(), PlcSignatureService::SIGN, ['token' => 'ABCDE-23456']));
	}
}
