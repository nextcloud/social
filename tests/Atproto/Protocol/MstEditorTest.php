<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\MstEditor;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tree edited in place is the tree built whole: after any sequence
 * of commits — keys added, replaced and removed, a few at a time — the
 * blocks a store holds are, CID for CID and byte for byte, the ones
 * `Mst::build()` makes from the same records, and a commit touched only
 * the nodes on the way to its keys.
 */
class MstEditorTest extends TestCase {
	private const LEAF = 'bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454';

	/** @var array<string, string> the store: CID => node bytes */
	private array $stored = [];

	/**
	 * @return iterable<string, array{0: int}>
	 */
	public static function seeds(): iterable {
		foreach ([1, 2, 3, 20261013, 424242] as $seed) {
			yield 'seed ' . $seed => [$seed];
		}
	}

	#[DataProvider('seeds')]
	public function testAnyRunOfCommitsLeavesTheTreeABuildWouldMake(int $seed): void {
		mt_srand($seed);
		$pool = self::keyPool(400);
		$leaves = [];
		$root = null;
		for ($commit = 0; $commit < 60; $commit++) {
			$editor = $this->open($root);
			$before = $this->stored;
			$n = mt_rand(1, 6);
			for ($i = 0; $i < $n; $i++) {
				$key = $pool[mt_rand(0, count($pool) - 1)];
				if (isset($leaves[$key]) && mt_rand(0, 2) === 0) {
					$editor->delete($key);
					unset($leaves[$key]);
				} else {
					$value = Cid::forDagCbor(DagCbor::encode(['v' => mt_rand()]));
					$editor->put($key, $value);
					$leaves[$key] = $value;
				}
			}
			$root = $this->commit($editor);

			$expected = (new Mst($leaves))->build();
			$this->assertSame($expected->root->toString(), $root->toString(), "commit $commit: the root");
			$this->assertSame(self::sorted($expected->blocks), self::sorted($this->stored), "commit $commit: the stored nodes");
			$this->assertSame([], array_intersect_key($this->added, $before), "commit $commit: a node written again");
		}
		$this->assertNotSame([], $leaves);
	}

	public function testTheInteropCommitsLeaveTheRootTheyName(): void {
		foreach (Fixtures::json('commit-proof-fixtures.json') as $vector) {
			$leaf = Cid::parse($vector['leafValue']);
			$this->stored = [];
			$built = (new Mst(array_fill_keys($vector['keys'], $leaf)))->build();
			$this->stored = $built->blocks;

			$editor = $this->open($built->root);
			foreach ($vector['adds'] as $key) {
				$editor->put($key, $leaf);
			}
			foreach ($vector['dels'] as $key) {
				$editor->delete($key);
			}

			$this->assertSame($vector['rootAfterCommit'], $this->commit($editor)->toString(), $vector['comment']);
		}
	}

	public function testACommitReadsThePathToItsKeysAndNoMore(): void {
		$keys = self::keyPool(5000);
		$built = (new Mst(array_fill_keys($keys, Cid::parse(self::LEAF))))->build();
		$this->stored = $built->blocks;
		$this->assertGreaterThan(1000, count($this->stored));

		$editor = $this->open($built->root);
		$editor->put('com.example.record/3jqfcqzm3fx2j', Cid::parse(self::LEAF));
		$editor->delete($keys[17]);
		$this->commit($editor);

		$this->assertLessThan(40, count($editor->loaded()), 'nodes read for two keys in a tree of thousands');
		$this->assertLessThan(40, count($this->added), 'nodes written');
		$leaves = array_fill_keys($keys, Cid::parse(self::LEAF));
		unset($leaves[$keys[17]]);
		$leaves['com.example.record/3jqfcqzm3fx2j'] = Cid::parse(self::LEAF);
		$this->assertSame(self::sorted((new Mst($leaves))->build()->blocks), self::sorted($this->stored));
	}

	public function testAReplacedValueChangesOnlyItsPath(): void {
		$keys = self::keyPool(300);
		$leaves = array_fill_keys($keys, Cid::parse(self::LEAF));
		$built = (new Mst($leaves))->build();
		$this->stored = $built->blocks;
		$other = Cid::forDagCbor(DagCbor::encode(['other' => true]));

		$editor = $this->open($built->root);
		$this->assertTrue($editor->get($keys[3])?->equals(Cid::parse(self::LEAF)));
		$editor->put($keys[3], $other);
		$this->assertTrue($editor->get($keys[3])?->equals($other));
		$this->commit($editor);

		$leaves[$keys[3]] = $other;
		$this->assertSame(self::sorted((new Mst($leaves))->build()->blocks), self::sorted($this->stored));
	}

	public function testEmptyingTheTreeAndFillingItAgain(): void {
		$keys = ['com.example.record/3jqfcqzm3fo2j', 'com.example.record/3jqfcqzm3fx2j', 'com.example.record/3jqfcqzm4fc2j'];
		$built = (new Mst(array_fill_keys($keys, Cid::parse(self::LEAF))))->build();
		$this->stored = $built->blocks;

		$editor = $this->open($built->root);
		foreach ($keys as $key) {
			$editor->delete($key);
		}
		$this->assertNull($editor->get($keys[0]));
		$editor->put($keys[0], Cid::parse(self::LEAF));
		$root = $this->commit($editor);

		$this->assertSame((new Mst([$keys[0] => Cid::parse(self::LEAF)]))->build()->root->toString(), $root->toString());
		$this->assertSame(self::sorted((new Mst([$keys[0] => Cid::parse(self::LEAF)]))->build()->blocks), self::sorted($this->stored));

		$editor = $this->open($root);
		$editor->delete($keys[0]);
		$this->assertSame('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm', $this->commit($editor)->toString(), 'the empty tree');
	}

	public function testWhatIsNotThereIsSaidSo(): void {
		$built = (new Mst(['com.example.record/3jqfcqzm3fo2j' => Cid::parse(self::LEAF)]))->build();
		$this->stored = $built->blocks;
		$editor = $this->open($built->root);
		try {
			$editor->delete('com.example.record/3jqfcqzm3fp2j');
			$this->fail('deleted');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('No key', $e->getMessage());
		}

		$this->stored = [];
		try {
			$this->open($built->root);
			$this->fail('opened');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('is missing', $e->getMessage());
		}
	}

	/**
	 * A tree built whole and closed as such encodes every node, so a store
	 * can be set to it whatever it held.
	 */
	public function testAWholeTreeEncodesEveryNode(): void {
		$built = (new Mst(array_fill_keys(self::keyPool(200), Cid::parse(self::LEAF))))->build();
		$editor = MstEditor::whole($built);
		$editor->put('com.example.record/3jqfcqzm3fx2j', Cid::parse(self::LEAF));

		$closed = $editor->close();
		$expected = (new Mst(array_fill_keys([...self::keyPool(200), 'com.example.record/3jqfcqzm3fx2j'], Cid::parse(self::LEAF))))->build();
		$this->assertSame(self::sorted($expected->blocks), self::sorted($closed->blocks));
	}

	/** @var array<string, string> what the last commit wrote */
	private array $added = [];

	private function open(?Cid $root): MstEditor {
		return MstEditor::open($root, fn (string $cid): ?string => $this->stored[$cid] ?? null);
	}

	/**
	 * Applies the editor's result to the store the way a commit does.
	 */
	private function commit(MstEditor $editor): Cid {
		$tree = $editor->close();
		$known = $editor->loaded();
		$this->added = array_diff_key($tree->blocks, $known);
		foreach (array_keys(array_diff_key($known, $tree->blocks)) as $cid) {
			unset($this->stored[$cid]);
		}
		foreach ($this->added as $cid => $bytes) {
			$this->assertArrayNotHasKey($cid, $this->stored, 'a node stored twice');
			$this->stored[$cid] = $bytes;
		}

		return $tree->root;
	}

	/**
	 * Record paths in the shape the interop vectors use, deterministic for
	 * the seed in force.
	 *
	 * @return list<string>
	 */
	private static function keyPool(int $count): array {
		$keys = [];
		$state = 12345;
		for ($i = 0; $i < $count; $i++) {
			$state = ($state * 1103515245 + 12345) % 2147483648;
			$keys[] = 'com.example.record/3jqfcqzm' . base_convert((string)$state, 10, 32) . 'j';
		}

		return array_values(array_unique($keys));
	}

	/**
	 * @param array<string, string> $blocks
	 * @return array<string, string>
	 */
	private static function sorted(array $blocks): array {
		ksort($blocks, SORT_STRING);

		return $blocks;
	}
}
