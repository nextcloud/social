<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Identity\PlcOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * did:plc against the directory itself: the audit logs and DID documents
 * of three public organisation accounts, as plc.directory served them on
 * 2026-10-09 (tests/Atproto/Identity/plc; `support.bsky.team` starts with
 * a legacy `create` genesis). PlcOperation has to compute exactly what the
 * directory computed: the DID from the genesis, each operation's CID that
 * the next names as `prev`, the signature check under the rotation keys of
 * the operation before, and the DID document of the latest state.
 *
 * Then a chain this app signs, with a K-256 and a P-256 rotation key, is
 * walked by the same rules: what the directory would accept of it.
 */
class PlcAuditLogTest extends TestCase {
	public static function logs(): array {
		$logs = [];
		foreach (glob(__DIR__ . '/plc/*.json') ?: [] as $path) {
			$logs[basename($path, '.json')] = [json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)];
		}

		return $logs;
	}

	#[DataProvider('logs')]
	public function testTheGenesisOperationHashesToTheDid(array $vector): void {
		$this->assertSame($vector['did'], PlcOperation::didOf($vector['audit'][0]['operation']));
	}

	#[DataProvider('logs')]
	public function testEachOperationHasTheCidTheDirectoryGaveIt(array $vector): void {
		foreach ($vector['audit'] as $entry) {
			$this->assertSame($entry['cid'], PlcOperation::cid($entry['operation'])->toString());
		}
	}

	#[DataProvider('logs')]
	public function testTheLogIsAChainSignedByTheKeysThatHadAuthority(array $vector): void {
		$this->assertGreaterThan(2, count($vector['audit']));

		$this->assertSame([], self::auditErrors($vector['did'], array_column($vector['audit'], 'operation')));
	}

	/**
	 * The atproto signing key (`verificationMethods`) signs commits, not
	 * operations: an operation signed with it has no authority, and the
	 * check says so for every real operation in the vectors.
	 */
	#[DataProvider('logs')]
	public function testTheSigningKeyHasNoAuthorityOverTheLog(array $vector): void {
		$operations = array_column($vector['audit'], 'operation');
		for ($i = 1; $i < count($operations); $i++) {
			$previous = $operations[$i - 1];
			$signing = $previous['verificationMethods']['atproto'] ?? null;
			if ($signing === null || in_array($signing, self::authority($previous), true)) {
				continue;
			}
			$this->assertFalse(PlcOperation::verify($operations[$i], PublicKey::fromDidKey($signing)));
		}
	}

	#[DataProvider('logs')]
	public function testTheDocumentIsTheOneTheDirectoryServes(array $vector): void {
		$latest = end($vector['audit'])['operation'];

		$this->assertSame($vector['document'], PlcOperation::document($vector['did'], $latest));
	}

	#[DataProvider('logs')]
	public function testAnAlteredOperationNoLongerVerifiesNorChains(array $vector): void {
		$operations = array_column($vector['audit'], 'operation');
		$last = count($operations) - 1;
		$altered = $operations[$last];
		$altered['alsoKnownAs'] = ['at://someone-else.example.com'];

		foreach (self::authority($operations[$last - 1]) as $key) {
			$this->assertFalse(PlcOperation::verify($altered, PublicKey::fromDidKey($key)), 'a changed handle breaks the signature');
		}
		$this->assertNotSame($vector['audit'][$last]['cid'], PlcOperation::cid($altered)->toString());
		$this->assertNotSame($operations[2]['prev'], PlcOperation::cid($operations[0])->toString(), 'prev pins the order: the third does not follow the first');
		$this->assertNotSame('did:plc:aaaaaaaaaaaaaaaaaaaaaaaa', PlcOperation::didOf($operations[0]));
		$this->assertNotSame($vector['did'], PlcOperation::didOf($operations[1]), 'only the genesis defines the DID');
	}

	/**
	 * What this app sends the directory (a genesis, a rotation of the
	 * signing key and the PDS, a tombstone), with a rotation key of each
	 * curve, chains and verifies under the directory's rules.
	 */
	public function testAChainThisAppSignsPassesTheSameChecks(): void {
		$rotation = PrivateKey::generate(Curve::P256);
		$recovery = PrivateKey::generate(Curve::K256);
		$rotationKeys = [$recovery->publicKey()->didKey(), $rotation->publicKey()->didKey()];

		$genesis = PlcOperation::sign(PlcOperation::build($rotationKeys, PrivateKey::generate(Curve::K256)->publicKey()->didKey(), 'alice.social.example.com', 'https://social.example.com', null), $rotation);
		$did = PlcOperation::didOf($genesis);
		$moved = PlcOperation::sign(PlcOperation::build($rotationKeys, PrivateKey::generate(Curve::K256)->publicKey()->didKey(), 'alice.example.org', 'https://pds.example.org', PlcOperation::cid($genesis)->toString()), $recovery);
		$tombstone = PlcOperation::sign(PlcOperation::tombstone(PlcOperation::cid($moved)->toString()), $rotation);

		$this->assertMatchesRegularExpression('/^did:plc:[a-z2-7]{24}$/', $did);
		$this->assertSame([], self::auditErrors($did, [$genesis, $moved, $tombstone]));

		$outsider = PlcOperation::sign(PlcOperation::tombstone(PlcOperation::cid($moved)->toString()), PrivateKey::generate(Curve::P256));
		$this->assertSame(['2: not signed by a rotation key of the operation before'], self::auditErrors($did, [$genesis, $moved, $outsider]));
	}

	/**
	 * The keys an operation gives authority over the next one: its
	 * rotation keys, or for a legacy `create` its recovery and signing key.
	 *
	 * @return list<string>
	 */
	private static function authority(array $operation): array {
		if (($operation['type'] ?? '') === 'create') {
			return array_values(array_filter([$operation['recoveryKey'] ?? null, $operation['signingKey'] ?? null], 'is_string'));
		}

		return array_values($operation['rotationKeys'] ?? []);
	}

	/**
	 * The did:plc audit rules, as the directory applies them to a log of
	 * operations none of which is nullified.
	 *
	 * @param list<array> $operations
	 * @return list<string> what is wrong, by operation index
	 */
	private static function auditErrors(string $did, array $operations): array {
		$errors = [];
		if (PlcOperation::didOf($operations[0]) !== $did) {
			$errors[] = '0: the genesis does not hash to the DID';
		}
		foreach ($operations as $i => $operation) {
			$expectedPrev = $i === 0 ? null : PlcOperation::cid($operations[$i - 1])->toString();
			if (($operation['prev'] ?? null) !== $expectedPrev) {
				$errors[] = $i . ': prev is not the operation before';
			}
			$signed = false;
			foreach (self::authority($i === 0 ? $operation : $operations[$i - 1]) as $key) {
				if (PlcOperation::verify($operation, PublicKey::fromDidKey($key))) {
					$signed = true;
					break;
				}
			}
			if (!$signed) {
				$errors[] = $i . ': not signed by a rotation key of the operation before';
			}
		}

		return $errors;
	}
}
