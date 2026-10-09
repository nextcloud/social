<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Model\OAuthSession;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * OAuth for Bluesky apps against the real database. Everything that may
 * happen only once is decided by the row count of one conditional UPDATE
 * or by a unique index, not by a read followed by a write: approving a
 * request, exchanging its code, rotating a refresh token, and the
 * one-time values (DPoP proof ids). Those are pinned here, with the
 * scoping of "remove my session" to its owner and the pruning of what
 * expired.
 */
class OAuthStorageTest extends TestCase {
	private const USER = 'atproto-oauth-integration';
	private const OTHER = 'atproto-oauth-integration-other';

	private AtprotoOAuthRequest $oauth;
	private string $prefix;

	protected function setUp(): void {
		$this->oauth = Server::get(AtprotoOAuthRequest::class);
		$this->prefix = 'it-' . bin2hex(random_bytes(6)) . '-';
		$this->oauth->deleteByUser(self::USER);
		$this->oauth->deleteByUser(self::OTHER);
	}

	protected function tearDown(): void {
		$this->oauth->deleteByUser(self::USER);
		$this->oauth->deleteByUser(self::OTHER);
		foreach (['request', 'expired'] as $name) {
			$this->oauth->removeRequest($this->prefix . $name);
		}
	}

	public function testARequestIsApprovedOnceAndItsCodeExchangedOnce(): void {
		$requestId = $this->prefix . 'request';
		$params = ['redirect_uri' => 'https://app.example.com/cb', 'scope' => 'atproto', 'state' => 's', 'code_challenge' => $this->prefix . 'challenge'];
		$this->oauth->addRequest($requestId, 'https://app.example.com/client.json', ['method' => 'none'], $params, 'jkt', time() + 300);

		$request = $this->oauth->getRequest($requestId);
		$this->assertNotNull($request);
		$this->assertFalse($request->isApproved());
		$this->assertSame($params, $request->params);
		$this->assertTrue($this->oauth->challengeSeen($this->prefix . 'challenge'), 'a PKCE challenge is not taken twice');

		$this->assertTrue($this->oauth->approveRequest($requestId, self::USER, 'did:plc:alice', $this->prefix . 'code', time() + 60));
		$this->assertFalse($this->oauth->approveRequest($requestId, self::OTHER, 'did:plc:mallory', $this->prefix . 'other-code', time() + 60), 'a second approval does not replace the first');

		$approved = $this->oauth->getRequestByCode($this->prefix . 'code');
		$this->assertNotNull($approved);
		$this->assertSame(self::USER, $approved->userId);
		$this->assertNull($this->oauth->getRequestByCode($this->prefix . 'other-code'));
		$this->assertNull($this->oauth->getRequestByCode(''), 'an unapproved request has no code to be found by');

		$this->assertTrue($this->oauth->exchangeCode($approved->id, $this->prefix . 'session'));
		$this->assertFalse($this->oauth->exchangeCode($approved->id, $this->prefix . 'second-session'), 'a code is exchanged once');
		$this->assertSame($this->prefix . 'session', $this->oauth->getRequest($requestId)?->sessionId, 'the session a replay ends');
	}

	public function testARefreshTokenRotatesOnlyFromTheOneThatIsGood(): void {
		$sessionId = $this->prefix . 'session';
		$this->oauth->addSession($this->session($sessionId, self::USER, 'hash-1'));

		$this->assertTrue($this->oauth->rotateRefresh($sessionId, 'hash-1', 'hash-2', time() + 3600));
		$this->assertFalse($this->oauth->rotateRefresh($sessionId, 'hash-1', 'hash-3', time() + 3600), 'the replaced token rotates nothing');
		$this->assertFalse($this->oauth->rotateRefresh($this->prefix . 'other', 'hash-2', 'hash-3', time() + 3600), 'nor does the good one on another session');

		$this->assertSame($sessionId, $this->oauth->getSessionByRefresh('hash-2')?->sessionId);
		$this->assertNull($this->oauth->getSessionByRefresh('hash-1'));
		$this->assertSame($sessionId, $this->oauth->getSessionByPreviousRefresh('hash-1')?->sessionId, 'a replay of the old token finds the session to end');
	}

	public function testAPersonRemovesOnlyTheirOwnSessions(): void {
		$this->oauth->addSession($this->session($this->prefix . 'mine', self::USER, 'h-mine'));
		$this->oauth->addSession($this->session($this->prefix . 'theirs', self::OTHER, 'h-theirs'));
		$theirs = $this->oauth->getSessionsOfUser(self::OTHER);
		$this->assertCount(1, $theirs);

		$this->assertFalse($this->oauth->removeSessionOfUser(self::USER, $theirs[0]->id));
		$this->assertNotNull($this->oauth->getSession($this->prefix . 'theirs'));
		$this->assertSame([$this->prefix . 'mine'], array_map(static fn (OAuthSession $s): string => $s->sessionId, $this->oauth->getSessionsOfUser(self::USER)));

		$mine = $this->oauth->getSessionsOfUser(self::USER)[0];
		$this->assertSame(['method' => 'none'], $mine->clientAuth);
		$this->assertSame(['atproto', 'transition:generic'], $mine->scopes());
		$this->assertTrue($this->oauth->removeSessionOfUser(self::USER, $mine->id));
		$this->assertNull($this->oauth->getSession($this->prefix . 'mine'));
		$this->assertNull($this->oauth->getSession(''));
	}

	public function testAOneTimeValueIsTakenOnce(): void {
		$hash = hash('sha256', $this->prefix . 'jti');

		$this->assertTrue($this->oauth->firstUse($hash, time() + 600));
		$this->assertFalse($this->oauth->firstUse($hash, time() + 600));
		$this->assertTrue($this->oauth->firstUse(hash('sha256', $this->prefix . 'other jti'), time() + 600));
	}

	public function testWhatExpiredIsPruned(): void {
		$past = time() - 7 * 86400;
		$this->oauth->addRequest($this->prefix . 'expired', 'https://app.example.com/client.json', ['method' => 'none'], ['code_challenge' => $this->prefix . 'old'], 'jkt', $past);
		$this->oauth->addSession(new OAuthSession(0, $this->prefix . 'stale', self::USER, 'did:plc:alice', 'https://app.example.com/client.json', ['method' => 'none'], 'atproto', 'jkt', 'h-stale', $past, 0));
		$this->oauth->addSession($this->session($this->prefix . 'fresh', self::USER, 'h-fresh'));
		$spent = hash('sha256', $this->prefix . 'spent');
		$this->oauth->firstUse($spent, $past);

		$this->assertGreaterThanOrEqual(3, $this->oauth->prune(time()));

		$this->assertNull($this->oauth->getRequest($this->prefix . 'expired'));
		$this->assertNull($this->oauth->getSession($this->prefix . 'stale'));
		$this->assertNotNull($this->oauth->getSession($this->prefix . 'fresh'), 'a session whose refresh token is good stays');
		$this->assertTrue($this->oauth->firstUse($spent, time() + 600), 'an expired one-time value is forgotten');
	}

	private function session(string $sessionId, string $userId, string $refreshHash): OAuthSession {
		return new OAuthSession(0, $sessionId, $userId, 'did:plc:alice', 'https://app.example.com/client.json', ['method' => 'none'], 'atproto transition:generic', 'jkt', $refreshHash, time() + 86400, 0);
	}
}
