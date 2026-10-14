<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\RecoveryToolPage;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The offline recovery tool as one file: the built script inline with the
 * licences of what it bundles, nothing in it able to end the script
 * element early, the CSP hash of exactly that script, and the strings and
 * word list the script reads.
 */
class RecoveryToolPageTest extends TestCase {
	private string $script = '';

	protected function setUp(): void {
		$this->script = (string)tempnam(sys_get_temp_dir(), 'recovery-script');
	}

	protected function tearDown(): void {
		foreach ([$this->script, $this->script . '.LICENSE.txt'] as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	private function page(): RecoveryToolPage {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$l10n->method('getLanguageCode')->willReturn('de');
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('plcDirectory')->willReturn('https://plc.directory');

		return new RecoveryToolPage($l10n, $config, $this->script);
	}

	public function testTheScriptIsInlineWithItsLicencesAndCannotCloseItsElement(): void {
		file_put_contents($this->script, 'var s = "</script><script>alert(1)";');
		file_put_contents($this->script . '.LICENSE.txt', '/*! noble-secp256k1 - MIT License (c) 2019 Paul Miller (paulmillr.com) */');

		$html = $this->page()->render();

		$this->assertSame(2, substr_count(strtolower($html), '</script'), 'the data element and the script close, nothing else');
		preg_match('#<script>(.*)</script>#s', $html, $inline);
		$this->assertStringStartsWith('/*! noble-secp256k1 - MIT License', $inline[1]);
		$this->assertStringContainsString('var s = "<\/script><script>alert(1)";', $inline[1]);
		$this->assertSame("'sha256-" . base64_encode(hash('sha256', $inline[1], true)) . "'", $this->page()->scriptSource());
	}

	public function testThePageCarriesTheWordListTheStringsAndTheWarning(): void {
		file_put_contents($this->script, '');

		$html = $this->page()->render();

		$this->assertStringContainsString('<html lang="de">', $html);
		preg_match('#<script type="application/json" id="recovery-data">(.*?)</script>#s', $html, $data);
		$decoded = json_decode($data[1], true);
		$this->assertCount(2048, $decoded['words']);
		$this->assertSame('abandon', $decoded['words'][0]);
		$this->assertSame('Your key is not one of the rotation keys of this identifier, so it cannot change it.', $decoded['strings']['key-not-listed']);
		$this->assertStringContainsString('for 72 hours after a change made with the phrase, that server\'s key can undo it', html_entity_decode($html, ENT_QUOTES));
		$this->assertStringContainsString('value="https://plc.directory"', $html);
	}

	public function testWithoutABuildThereIsNoTool(): void {
		unlink($this->script);

		$this->assertFalse($this->page()->isBuilt());
	}
}
