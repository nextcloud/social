<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Activity;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Object\Follow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ten activity models share the ACore import/export; this checks each one
 * is wired to it and carries its own type.
 */
class ActivitiesTest extends TestCase {
	public static function activityProvider(): array {
		return [
			'Accept' => [Accept::class, 'Accept'],
			'Add' => [Add::class, 'Add'],
			'Block' => [Block::class, 'Block'],
			'Create' => [Create::class, 'Create'],
			'Delete' => [Delete::class, 'Delete'],
			'Move' => [Move::class, 'Move'],
			'Reject' => [Reject::class, 'Reject'],
			'Remove' => [Remove::class, 'Remove'],
			'Undo' => [Undo::class, 'Undo'],
			'Update' => [Update::class, 'Update'],
		];
	}

	#[DataProvider('activityProvider')]
	public function testConstructorSetsTheTypeAndAttachesAParent(string $class, string $type): void {
		$parent = new Create();
		/** @var ACore $activity */
		$activity = new $class($parent);

		$this->assertSame($type, $activity->getType());
		$this->assertSame($type, $class::TYPE);
		$this->assertSame($parent, $activity->getParent());
		$this->assertTrue((new $class())->isRoot());
	}

	#[DataProvider('activityProvider')]
	public function testImportReadsActorAndObjectAndExportsThemBack(string $class, string $type): void {
		/** @var ACore $activity */
		$activity = new $class();

		$activity->import([
			'id' => 'https://mastodon.social/users/alice#' . strtolower($type) . '/1',
			'type' => $type,
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/apps/social/@bob/1',
			'to' => [ACore::CONTEXT_PUBLIC],
		]);

		$this->assertSame('https://mastodon.social/users/alice', $activity->getActorId());
		$this->assertSame('https://cloud.example.org/apps/social/@bob/1', $activity->getObjectId());
		$this->assertTrue($activity->isPublic());

		$export = $activity->jsonSerialize();
		$this->assertSame(
			[ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_EXTENSIONS], $export['@context']
		);
		$this->assertSame($type, $export['type']);
		$this->assertSame('https://mastodon.social/users/alice', $export['actor']);
		$this->assertSame('https://cloud.example.org/apps/social/@bob/1', $export['object']);
	}

	public function testMoveImportsTheTargetAccount(): void {
		$move = new Move();

		$move->import([
			'id' => 'https://mastodon.social/users/alice#moves/1',
			'type' => 'Move',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://mastodon.social/users/alice',
			'target' => 'https://other.example/users/alice',
		]);

		$this->assertSame('https://mastodon.social/users/alice', $move->getActorId());
		$this->assertSame('https://mastodon.social/users/alice', $move->getObjectId());
		$this->assertSame('https://other.example/users/alice', $move->getTarget());
	}

	public function testMoveSaysWhereTheAccountWent(): void {
		// a Move whose export drops `target` tells the other server that an
		// account moved and not where to, so its followers have nothing to follow
		$move = new Move();
		$move->setId('https://cloud.example/users/alice#moves/1');
		$move->setActorId('https://cloud.example/users/alice');
		$move->setObjectId('https://cloud.example/users/alice');
		$move->setTarget('https://mastodon.social/users/alice');

		$this->assertSame('https://mastodon.social/users/alice', $move->exportAsActivityPub()['target']);
	}

	public function testAMoveWithNoTargetExportsNone(): void {
		$move = new Move();
		$move->setActorId('https://cloud.example/users/alice');

		$this->assertArrayNotHasKey('target', $move->exportAsActivityPub());
	}

	public function testUndoWithAnEmbeddedFollowExportsTheObjectInline(): void {
		$undo = new Undo();
		$undo->setId('https://mastodon.social/users/alice#follows/1/undo')
			->setActorId('https://mastodon.social/users/alice');
		$follow = new Follow();
		$follow->setId('https://mastodon.social/users/alice#follows/1')
			->setActorId('https://mastodon.social/users/alice')
			->setObjectId('https://cloud.example.org/apps/social/@bob');
		$undo->setObject($follow);

		$export = $undo->jsonSerialize();

		$this->assertSame($follow, $export['object']);
		$this->assertSame('https://mastodon.social/users/alice#follows/1', $undo->getObjectId());
		$this->assertArrayNotHasKey('@context', $follow->jsonSerialize(), 'the embedded object is not a root');
	}
}
