<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use OCA\Social\Atproto\OAuth\Jwk;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Tests\Atproto\OAuth\OAuthKit;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A captured DPoP proof walked second by second through every window that
 * stands between it and a second use, on a frozen clock, with the proof ids
 * in the real replay table and the maintenance prune run on every second.
 *
 * Three checks refuse a replay, each for a stretch of time: the stored proof
 * id, the server nonce the proof carries (the window it was issued in and the
 * next), and the age of its `iat` (five minutes back, one forward). There
 * must be no second at which all three have let go while the proof is still
 * fresh, and the prune must never take an id whose proof is.
 *
 * The clock stands a day in the past: the prune takes every request, session
 * and proof id that has expired by then, and a clock ahead of the wall clock
 * would take the instance's own while they still count.
 */
class DpopReplayWindowTest extends TestCase {
	use FreezesTheClock;

	private const URL = 'https://social.test/oauth/token';
	private const MAX_AGE = 300;
	private const MAX_FUTURE = 60;

	private FrozenClock $clock;
	private PrivateKey $key;
	/** @var string[] the replay rows this test wrote */
	private array $hashes = [];

	protected function setUp(): void {
		parent::setUp();
		// at the start of a nonce window, so where each window ends is known
		$this->clock = $this->freezeClock(new FrozenClock(intdiv(time() - 86400, DpopNonce::WINDOW) * DpopNonce::WINDOW));
		$this->key = PrivateKey::generate(Curve::P256);
	}

	protected function tearDown(): void {
		if ($this->hashes !== []) {
			$qb = Server::get(IDBConnection::class)->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_ATPROTO_OAUTH_REPLAY)
				->where($qb->expr()->in('hash', $qb->createNamedParameter($this->hashes, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$this->thawClock();
		parent::tearDown();
	}

	public function testACapturedProofIsRefusedOnEverySecondUntilItIsStale(): void {
		$start = $this->clock->getTime();
		$proof = $this->proof($start, $this->nonce()->current(), 'captured');
		$this->assertNotSame('', $this->verifier()->verify($proof, 'POST', self::URL));

		$nonceEnds = $start + 2 * DpopNonce::WINDOW;
		$reasons = [];
		for ($second = $start + 1; $second <= $start + self::MAX_AGE + self::MAX_FUTURE + 60; $second++) {
			$this->clock->set($second);
			Server::get(AtprotoOAuthRequest::class)->prune($second);
			$reasons[$second - $start] = $this->refusal($proof);
		}

		$expected = [];
		for ($offset = 1; $offset <= self::MAX_AGE + self::MAX_FUTURE + 60; $offset++) {
			$expected[$offset] = match (true) {
				$offset > self::MAX_AGE => 'The DPoP proof is not fresh',
				$start + $offset >= $nonceEnds => 'use_dpop_nonce',
				default => 'The DPoP proof was used before',
			};
		}
		$this->assertSame($expected, $reasons);
	}

	/**
	 * A proof dated a minute ahead is the freshest there can be: it stays
	 * fresh until six minutes after it was first used, and its id is kept
	 * exactly that long.
	 */
	public function testTheIdOfAProofDatedAheadIsKeptAsLongAsTheProofIsFresh(): void {
		$start = $this->clock->getTime();
		$this->assertSame('The DPoP proof is not fresh', $this->refusal($this->proof($start + self::MAX_FUTURE + 1, $this->nonce()->current(), 'too far')));
		$ahead = $this->proof($start + self::MAX_FUTURE, $this->nonce()->current(), 'ahead');
		$this->verifier()->verify($ahead, 'POST', self::URL);

		$hash = $this->hashOf('ahead');
		foreach ([self::MAX_AGE + self::MAX_FUTURE - 1, self::MAX_AGE + self::MAX_FUTURE] as $offset) {
			Server::get(AtprotoOAuthRequest::class)->prune($start + $offset);
			$this->assertFalse(Server::get(AtprotoOAuthRequest::class)->firstUse($hash, $start + $offset + 600), 'the id is still kept ' . $offset . ' s on');
		}

		$this->clock->set($start + self::MAX_AGE + self::MAX_FUTURE + 1);
		$this->assertSame('The DPoP proof is not fresh', $this->refusal($ahead), 'once the id may go, the proof is stale');
	}

	public function testANonceIsTakenUntilTheEndOfTheWindowAfterItsOwn(): void {
		$start = $this->clock->getTime();
		$nonce = $this->nonce()->current();
		$ends = $start + 2 * DpopNonce::WINDOW;

		$this->clock->set($ends - 1);
		$this->assertNotSame('', $this->verifier()->verify($this->proof($ends - 1, $nonce, 'last second'), 'POST', self::URL));

		$this->clock->set($ends);
		try {
			$this->verifier()->verify($this->proof($ends, $nonce, 'too late'), 'POST', self::URL);
			$this->fail('a nonce two windows old was taken');
		} catch (OAuthException $e) {
			$this->assertSame('use_dpop_nonce', $e->error);
			$this->assertSame($this->nonce()->current(), $e->headers['DPoP-Nonce'], 'the client is handed the current one');
		}
	}

	private function verifier(): DpopVerifier {
		return $this->clocked(DpopVerifier::class);
	}

	private function nonce(): DpopNonce {
		return $this->clocked(DpopNonce::class);
	}

	private function proof(int $iat, string $nonce, string $jti): string {
		$this->hashes[] = $this->hashOf($jti);

		return OAuthKit::proof($this->key, 'POST', self::URL, $iat, $nonce, '', $jti);
	}

	/** The replay row a proof id is kept under, as DpopVerifier writes it. */
	private function hashOf(string $jti): string {
		return hash('sha256', 'dpop|' . Jwk::thumbprint(OAuthKit::jwk($this->key)) . '|' . $jti);
	}

	/** Why the proof is refused now: the error for a nonce, the description otherwise. */
	private function refusal(string $proof): string {
		try {
			$this->verifier()->verify($proof, 'POST', self::URL);
		} catch (OAuthException $e) {
			return ($e->error === 'use_dpop_nonce') ? $e->error : $e->getMessage();
		}

		return 'accepted';
	}
}
