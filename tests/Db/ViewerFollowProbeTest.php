<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\ICompositeExpression;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How `limitToViewer()` consults the viewer's follows.
 *
 * Where the follow is one branch of the visibility rule among others, the
 * follows are probed per recipient row: joined, they repeat each candidate row
 * once per account followed before the public branch admits it. Where it is
 * the only branch — home and list timelines — the join is what drives the
 * read, and those callers constrain on the follow's `type`, so it stays.
 * `tests/Integration/Api/VisibilityMatrixTest` pins what either shape lets
 * through; this pins which shape each caller gets.
 */
#[AllowMockObjectsWithoutExpectations]
class ViewerFollowProbeTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/viewer';

	/** @return array<string, array{bool, bool, bool}> allowPublic, allowDirect, joined */
	public static function branches(): array {
		return [
			'public, direct and followed' => [true, true, false],
			'public and followed (hashtag)' => [true, false, false],
			'direct and followed' => [false, true, false],
			'followed only (home, lists)' => [false, false, true],
		];
	}

	/** A composite expression that reads as `$sql` where it is put in a string. */
	private function composite(string $sql): ICompositeExpression {
		return new class($sql) implements ICompositeExpression {
			public function __construct(
				private string $sql,
			) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return 1;
			}

			public function getType(): string {
				return 'AND';
			}

			public function __toString(): string {
				return $this->sql;
			}
		};
	}

	#[DataProvider('branches')]
	public function testTheFollowsAreJoinedOnlyWhereTheyAreTheOnlyBranch(
		bool $allowPublic, bool $allowDirect, bool $joined,
	): void {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);

		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'hasViewer', 'getViewer', 'selectDestFollowing', 'leftJoinFollowing',
				'exprStreamDestFollowed', 'exprInnerJoinStreamDestFollowing', 'exprLimitToDest',
				'expr', 'andWhere', 'filterHiddenActors',
			])
			->getMock();
		$qb->method('hasViewer')->willReturn(true);
		$qb->method('getViewer')->willReturn($viewer);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('exprLimitToDest')->willReturnCallback(
			fn (string $actorId, string $type): ICompositeExpression => $this->composite('addressed: ' . $type)
		);

		$qb->expects($joined ? $this->once() : $this->never())->method('leftJoinFollowing')->with('f');
		$qb->expects($joined ? $this->once() : $this->never())
			->method('exprInnerJoinStreamDestFollowing')
			->willReturn($this->composite('joined follow'));
		$qb->expects($joined ? $this->never() : $this->once())
			->method('exprStreamDestFollowed')
			->with(self::VIEWER, 'sd', 'f')
			->willReturn($this->composite('probed follow'));
		$where = [];
		$qb->expects($this->once())->method('andWhere')->willReturnCallback(
			static function ($predicate) use (&$where, $qb) {
				$where[] = (string)$predicate;

				return $qb;
			}
		);

		$qb->limitToViewer('sd', 'f', $allowPublic, $allowDirect);

		$branches = [$joined ? 'joined follow' : 'probed follow'];
		if ($allowPublic) {
			$branches[] = 'addressed: recipient';
		}
		if ($allowDirect) {
			$branches[] = 'addressed: dm';
		}
		$this->assertSame([(string)(new FakeExpressions())->orX(...$branches)], $where);
	}

	/**
	 * The probe is correlated on the recipient row, fixed to the viewer and
	 * to an accepted follow, and adds nothing to the request's FROM list.
	 */
	public function testTheProbeIsCorrelatedOnTheRecipientRow(): void {
		$parts = [];
		$expressions = new class($parts, $this->createStub(ICompositeExpression::class)) {
			public function __construct(
				private array &$parts,
				private ICompositeExpression $composite,
			) {
			}

			public function eq($x, $y, $type = null): string {
				return $x . ' = ' . $y;
			}

			public function andX(...$parts): ICompositeExpression {
				$this->parts = $parts;

				return $this->composite;
			}
		};

		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'expr', 'getDefaultSelectAlias', 'getTableName', 'createNamedParameter', 'prim',
				'from', 'join', 'innerJoin', 'leftJoin',
			])
			->getMock();
		$qb->method('expr')->willReturn($expressions);
		$qb->method('getDefaultSelectAlias')->willReturn('s');
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '`oc_' . $table . '`');
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$bound = [];
		$qb->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$bound): string {
			$bound[] = $value;

			return ':p' . count($bound);
		});
		foreach (['from', 'join', 'innerJoin', 'leftJoin'] as $method) {
			$qb->expects($this->never())->method($method);
		}

		$qb->exprStreamDestFollowed(self::VIEWER, 'sd', 'f');

		$this->assertSame([md5(self::VIEWER), 'recipient'], $bound);
		$this->assertSame([
			'sd.stream_id = s.id_prim',
			'sd.type = :p2',
			'EXISTS (SELECT 1 FROM `oc_social_follow` f WHERE f.follow_id_prim = sd.actor_id'
			. " AND f.actor_id_prim = :p1 AND f.accepted = '1')",
		], $parts);
	}
}
