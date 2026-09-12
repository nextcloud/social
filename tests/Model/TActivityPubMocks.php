<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\AP;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\Actor\ApplicationInterface;
use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\OrganizationInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Interfaces\Actor\ServiceInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\FlagInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Builds an AP dispatcher whose 23 interfaces are mocks, so model code that
 * reaches the AP registry can run without a server. Tests using it must clear
 * that registry with AP::set(null) in tearDown().
 */
trait TActivityPubMocks {
	/**
	 * Methods rather than constants: a trait cannot declare a constant before
	 * PHP 8.2, and the app supports 8.1.
	 */
	protected static function cloudUrl(): string {
		return 'https://cloud.example.org';
	}

	/**
	 * Constructor argument order of AP.
	 *
	 * @return list<class-string>
	 */
	private static function apInterfaceClasses(): array {
		return [
			AcceptInterface::class,
			AddInterface::class,
			AnnounceInterface::class,
			BlockInterface::class,
			CreateInterface::class,
			DeleteInterface::class,
			DocumentInterface::class,
			FlagInterface::class,
			FollowInterface::class,
			ImageInterface::class,
			LikeInterface::class,
			MoveInterface::class,
			NoteInterface::class,
			SocialAppNotificationInterface::class,
			PersonInterface::class,
			ServiceInterface::class,
			GroupInterface::class,
			OrganizationInterface::class,
			ApplicationInterface::class,
			RejectInterface::class,
			RemoveInterface::class,
			UndoInterface::class,
			UpdateInterface::class,
			QuoteRequestInterface::class,
		];
	}

	/** @var array<class-string, MockObject> */
	private array $apInterfaces = [];

	protected function createActivityPub(?string $cloudUrl = null): AP {
		$cloudUrl ??= self::cloudUrl();
		$args = [];
		foreach (self::apInterfaceClasses() as $class) {
			$this->apInterfaces[$class] = $this->createMock($class);
			$args[] = $this->apInterfaces[$class];
		}

		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn($cloudUrl);
		$args[] = $configService;

		// the real one, not a double: reading a PeerTube `Video` is parsing,
		// and a test that stubbed it would be asserting against its own stub.
		// Only the two things it *writes* through are mocks.
		$args[] = new PeerTubeService(
			$this->apInterfaces[DocumentInterface::class],
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);

		return new AP(...$args);
	}

	/** Installs a fresh AP as the global dispatcher the models reach for. */
	protected function installActivityPub(?string $cloudUrl = null): AP {
		AP::set($this->createActivityPub($cloudUrl));

		return AP::instance();
	}

	/**
	 * @param class-string $class one of the 23 interface classes
	 */
	protected function apInterface(string $class): MockObject {
		return $this->apInterfaces[$class];
	}
}
