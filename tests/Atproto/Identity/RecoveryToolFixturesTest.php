<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Identity\Mnemonic;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the offline recovery tool's cross-language fixtures
 * (`tests/js/recovery/plc.test.js` is the other): phrases and the keys
 * `Mnemonic` derives from them, and one hand-over built by
 * `PlcOperation::fromCredentials()` and signed here, which the tool has
 * to reproduce byte for byte — and the operation the tool signed, whose
 * signature and CID this side has to accept.
 *
 * With SOCIAL_UPDATE_FIXTURES set this writes the PHP parts afresh; the
 * tool's own parts are then written by the vitest with the same variable.
 */
class RecoveryToolFixturesTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/recovery-tool.json';

	public static function setUpBeforeClass(): void {
		if (getenv('SOCIAL_UPDATE_FIXTURES') !== false && getenv('SOCIAL_UPDATE_FIXTURES') !== '') {
			self::write();
		}
	}

	private static function fixture(): array {
		return json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
	}

	public function testEachPhraseIsTheKeyMnemonicDerives(): void {
		$phrases = self::fixture()['phrases'];

		$this->assertGreaterThanOrEqual(4, count($phrases));
		foreach ($phrases as $phrase) {
			$this->assertSame(['did_key' => $phrase['did_key'], 'multibase' => $phrase['multibase'], 'hex' => $phrase['hex']], Mnemonic::keyForms($phrase['phrase']), $phrase['phrase']);
		}
	}

	public function testTheHandOverIsTheOneThisServerBuildsAndSigns(): void {
		$fixture = self::fixture()['operation'];
		$recovery = Mnemonic::keyFromPhrase($fixture['phrase']);

		$this->assertSame($fixture['last_cid'], PlcOperation::cid($fixture['last'])->toString());
		$unsigned = PlcOperation::fromCredentials($fixture['credentials'], [$recovery->didKey()], $fixture['last_cid']);
		$this->assertSame($fixture['unsigned_cbor_hex'], bin2hex(DagCbor::encode($unsigned)));
		$this->assertTrue(PlcOperation::verify($fixture['php_signed'], $recovery->publicKey()));
		$this->assertSame($fixture['php_cid'], PlcOperation::cid($fixture['php_signed'])->toString());
	}

	/** What the tool signed is an operation the directory takes from this key: the same bytes, a low-S signature by it. */
	public function testTheToolsOperationVerifiesHere(): void {
		$fixture = self::fixture()['operation'];
		$this->assertArrayHasKey('js_signed', $fixture, 'run the vitest with SOCIAL_UPDATE_FIXTURES=1');
		$recovery = Mnemonic::keyFromPhrase($fixture['phrase']);
		$signed = $fixture['js_signed'];

		$this->assertTrue(PlcOperation::verify($signed, PublicKey::fromDidKey($recovery->didKey())));
		$this->assertSame($fixture['js_cid'], PlcOperation::cid($signed)->toString());
		unset($signed['sig']);
		$this->assertSame($fixture['unsigned_cbor_hex'], bin2hex(DagCbor::encode($signed)));
	}

	/** The operation that gives the DID a signing key of the person's own, and the token the new server's account is created with. */
	public function testTheToolsReKeyAndServiceTokenAreAcceptedHere(): void {
		$fixture = self::fixture()['operation'];
		$recovery = Mnemonic::keyFromPhrase($fixture['phrase']);
		$signing = Mnemonic::keyFromPhrase(self::fixture()['phrases'][3]['phrase']);
		$rekey = $fixture['js_rekey']['operation'];

		$this->assertTrue(PlcOperation::verify($rekey, $recovery->publicKey()));
		$this->assertSame($fixture['js_rekey']['cid'], PlcOperation::cid($rekey)->toString());
		$this->assertSame($fixture['last_cid'], $rekey['prev']);
		$this->assertSame($signing->didKey(), $rekey['verificationMethods']['atproto']);
		$this->assertSame($recovery->didKey(), $rekey['rotationKeys'][0]);
		$this->assertEqualsCanonicalizing($fixture['last']['rotationKeys'], $rekey['rotationKeys']);
		$this->assertSame([$fixture['last']['alsoKnownAs'], $fixture['last']['services']], [$rekey['alsoKnownAs'], $rekey['services']]);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn($fixture['js_service_auth']['now']);
		$replay = $this->createStub(AtprotoOAuthRequest::class);
		$replay->method('firstUse')->willReturn(true);
		$auth = new ServiceAuth($time, $replay);
		$token = $fixture['js_service_auth']['token'];
		$this->assertSame($fixture['did'], $auth->issuer($token));
		$this->assertTrue($auth->verify($token, $signing->publicKey(), $fixture['js_service_auth']['audience'], 'com.atproto.server.createAccount'));
	}

	private static function write(): void {
		$phrases = [];
		foreach ([str_repeat("\0", 16), str_repeat("\xff", 16), random_bytes(16), random_bytes(16)] as $entropy) {
			$phrase = Mnemonic::encode($entropy);
			$phrases[] = ['phrase' => $phrase] + Mnemonic::keyForms($phrase);
		}
		$phrase = $phrases[2]['phrase'];
		$recovery = Mnemonic::keyFromPhrase($phrase);
		$server = PrivateKey::generate(Curve::K256);
		$genesis = PlcOperation::sign(PlcOperation::build([$server->didKey()], PrivateKey::generate(Curve::K256)->didKey(), 'alice.social.test', 'https://social.test', null), $server);
		$last = PlcOperation::sign(PlcOperation::build([$server->didKey(), $recovery->didKey()], $genesis['verificationMethods']['atproto'], 'alice.social.test', 'https://social.test', PlcOperation::cid($genesis)->toString()), $server);
		$lastCid = PlcOperation::cid($last)->toString();
		$credentials = [
			'rotationKeys' => [PrivateKey::generate(Curve::K256)->didKey(), $recovery->didKey()],
			'alsoKnownAs' => ['at://alice.pds.example'],
			'verificationMethods' => ['atproto' => PrivateKey::generate(Curve::K256)->didKey()],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => 'https://pds.example']],
		];
		$unsigned = PlcOperation::fromCredentials($credentials, [$recovery->didKey()], $lastCid);
		$signed = PlcOperation::sign($unsigned, $recovery);
		$fixture = [
			'phrases' => $phrases,
			'operation' => [
				'did' => PlcOperation::didOf($genesis),
				'phrase' => $phrase,
				'last' => $last,
				'last_cid' => $lastCid,
				'credentials' => $credentials,
				'unsigned_cbor_hex' => bin2hex(DagCbor::encode($unsigned)),
				'php_signed' => $signed,
				'php_cid' => PlcOperation::cid($signed)->toString(),
			],
		];
		file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}
}
