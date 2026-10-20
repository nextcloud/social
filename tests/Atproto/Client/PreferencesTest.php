<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\MutedWords;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Service\AccountService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PreferencesTest extends TestCase {
	public function testVerificationBadgeChoicePreservesOtherBlueskyPreferences(): void {
		$stored = json_encode([
			['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => [['value' => 'at://feed']]],
		]);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('alice', 'social', 'atproto_preferences', '[]')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});
		$config->expects($this->once())->method('setUserValue')->with(
			'alice', 'social', 'atproto_preferences', $this->callback(static function (string $json) use (&$stored): bool {
				$stored = $json;
				return true;
			}),
		);
		$preferences = new Preferences($config, $this->createStub(MutedWords::class), $this->createStub(AccountService::class));

		self::assertFalse($preferences->hidesVerificationBadges('alice'));
		$preferences->setHideVerificationBadges('alice', true);

		self::assertTrue($preferences->hidesVerificationBadges('alice'));
		self::assertSame([
			['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => [['value' => 'at://feed']]],
			['$type' => Preferences::VERIFICATION, 'hideBadges' => true],
		], json_decode($stored, true));
	}

	public function testBadStoredPreferencesAreTreatedAsEmpty(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('{');
		$preferences = new Preferences($config, $this->createStub(MutedWords::class), $this->createStub(AccountService::class));

		self::assertFalse($preferences->hidesVerificationBadges('alice'));
	}
}
