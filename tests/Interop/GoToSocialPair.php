<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

/**
 * Our `admin` and GoToSocial's `interop`, following each other.
 *
 * Every test starts from that: a post reaches the other side because somebody
 * there follows its author, so both follows are made (or found standing)
 * before each test. Both accounts are found by handle through each side's own
 * search, which is already the first proof that each side will fetch the
 * other's actor — and GoToSocial fetches nothing unsigned and answers nothing
 * unsigned (its "secure mode", Mastodon's `AUTHORIZED_FETCH`), so a resolve
 * in either direction is a signed request that passed the other side's
 * verifier.
 *
 * Our side is driven through this app's own client API over HTTPS, as a
 * phone app drives it; only the delivery queue is touched directly, drained
 * here and now rather than waited for from cron.
 */
trait GoToSocialPair {
	use LocalSide;

	protected const USER = 'admin';
	/** a second reader here, whom a direct message must not reach */
	protected const BYSTANDER = 'bystander';

	protected GoToSocial $gts;
	protected Here $here;
	/** our id for GoToSocial's `interop` */
	protected string $interopHere = '';
	/** GoToSocial's id for our `admin` */
	protected string $adminThere = '';

	/**
	 * Why pairing failed, once it has: every later test would wait out the
	 * same timeouts to fail the same way, so they fail at once instead.
	 */
	private static string $pairingFailed = '';

	protected function setUpPair(): void {
		$gts = GoToSocial::fromEnvironment();
		if ($gts === null) {
			$this->markTestSkipped('no GoToSocial to talk to: set GOTOSOCIAL_BASE_URL and GOTOSOCIAL_TOKEN');
		}
		if (self::$pairingFailed !== '') {
			$this->fail('the two accounts could not be paired in an earlier test: ' . self::$pairingFailed);
		}

		$this->gts = $gts;
		$this->here = Here::forUser(self::USER);

		try {
			$this->interopHere = $this->here->resolveAccount($this->gts->handle());
			$this->adminThere = $this->gts->resolveAccount($this->localHandle(self::USER));

			$this->gtsFollowsUs();
			$this->weFollowGts();
		} catch (\Throwable $e) {
			self::$pairingFailed = $e->getMessage();
			throw $e;
		}
	}

	/** Makes GoToSocial's `interop` follow our `admin`, unless it already does. */
	protected function gtsFollowsUs(): void {
		$relationship = $this->gts->relationship($this->adminThere);
		if ((bool)$relationship['following']) {
			return;
		}
		// a request left standing by an earlier run is asked again, not waited on
		if ((bool)$relationship['requested']) {
			$this->gts->unfollow($this->adminThere);
		}

		$this->gts->follow($this->adminThere);
		$followed = $this->gts->await(function (): ?bool {
			// the Accept is ours to send: it waits in the queue
			$this->drainQueue();

			return (bool)$this->gts->relationship($this->adminThere)['following'] ? true : null;
		});

		$this->assertTrue($followed === true, 'GoToSocial asked to follow ' . $this->localHandle(self::USER) . ' and never got to');
	}

	/** Makes our `admin` follow GoToSocial's `interop`, unless it already does. */
	protected function weFollowGts(): void {
		if ((bool)$this->here->relationship($this->interopHere)['following']) {
			return;
		}

		$this->here->follow($this->interopHere);
		$followed = $this->here->await(function (): ?bool {
			$this->drainQueue();

			return (bool)$this->here->relationship($this->interopHere)['following'] ? true : null;
		});

		$this->assertTrue($followed === true, 'this side asked to follow ' . $this->gts->handle() . ' and was never accepted');
	}

	/**
	 * Has GoToSocial write a status and waits for our copy of it, read
	 * through our own client API by its ActivityPub id.
	 *
	 * @param array<string, mixed> $fields anything besides the text
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} theirs, ours
	 */
	protected function gtsPostsAndWeHoldIt(string $text, array $fields = []): array {
		$theirs = $this->gts->publish($text, $fields + ['visibility' => 'public']);
		$ours = $this->here->awaitResolvedStatus((string)$theirs['uri']);
		$this->assertNotNull($ours, 'GoToSocial\'s ' . $theirs['uri'] . ' never reached this side');

		return [$theirs, $ours];
	}

	/**
	 * Waits for a status we wrote to be on GoToSocial, under our account.
	 *
	 * @param array<string, mixed> $ours our status entity
	 * @return array<string, mixed>
	 */
	protected function awaitOnGts(array $ours): array {
		$theirs = $this->gts->await(function () use ($ours): ?array {
			$this->drainQueue();

			return ClientApi::findByUri($this->gts->statusesOf($this->adminThere), (string)$ours['uri']);
		});
		$this->assertNotNull($theirs, 'GoToSocial never showed ' . $ours['uri'] . ' under ' . $this->localHandle(self::USER));

		return $theirs;
	}
}
