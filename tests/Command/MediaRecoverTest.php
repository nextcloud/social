<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\AP;
use OCA\Social\Command\MediaRecover;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

class MediaRecoverTest extends TestCase {
	use TActivityPubMocks;

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	private function row(): array {
		return [
			'nid' => '1789918808550952384',
			'id' => 'https://mastodon.xyz/users/nextcloud/statuses/117304119017398739',
			'subtype' => '',
			'source' => json_encode([
				'type' => 'Note',
				'attachment' => [[
					'type' => 'Document',
					'mediaType' => 'image/jpeg',
					'url' => 'https://6-28.mastodon.xyz/media_attachments/files/117/304/original/image.jpeg',
				]],
			], JSON_UNESCAPED_SLASHES),
		];
	}

	public function testDryRunOnlyReportsStoredRemoteMedia(): void {
		$request = $this->createMock(StreamRequest::class);
		$request->expects($this->once())->method('getMissingRemoteAttachments')->willReturn([$this->row()]);
		$request->expects($this->never())->method('setRecoveredRemoteAttachments');
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute(['--dry-run' => true, '--limit' => 1]));
		$this->assertStringContainsString('1 affected post(s), 0 recovered', $tester->getDisplay());
	}

	public function testRestoresMediaWithoutRewritingThePost(): void {
		$this->installActivityPub();
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/social/media/test.jpeg');
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
		$this->apInterface(DocumentInterface::class)->expects($this->once())->method('save');

		$request = $this->createMock(StreamRequest::class);
		$request->method('getMissingRemoteAttachments')->willReturnOnConsecutiveCalls([$this->row()], []);
		$request->expects($this->once())->method('setRecoveredRemoteAttachments')
			->with(
				$this->row()['id'],
				$this->callback(function (string $json): bool {
					$media = json_decode($json, true);
					return count($media) === 1
						&& $media[0]['type'] === 'image'
						&& str_contains($media[0]['remote_url'], '6-28.mastodon.xyz');
				}),
				'',
			)->willReturn(true);
		$tester = new CommandTester(new MediaRecover($request));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('1 affected post(s), 1 recovered', $tester->getDisplay());
	}
}
