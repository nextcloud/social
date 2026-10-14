<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/**
 * One tree node as MstEditor holds it: its layer, the sub-tree to the
 * left of every entry, and the entries with their keys whole. A sub-tree
 * is a loaded node, or the CID of one that was not read.
 */
final class MstNode {
	/**
	 * @param list<array{key: string, value: Cid, tree: MstNode|Cid|null}> $entries in key order
	 */
	public function __construct(
		public int $layer,
		public MstNode|Cid|null $left,
		public array $entries,
	) {
	}

	/** no entries and nothing to the left: a node the tree does not keep */
	public function isEmpty(): bool {
		return $this->entries === [] && $this->left === null;
	}

	/** the index of the first entry whose key is not below $key */
	public function indexOf(string $key): int {
		foreach ($this->entries as $i => $entry) {
			if (strcmp($entry['key'], $key) >= 0) {
				return $i;
			}
		}

		return count($this->entries);
	}

	/** the sub-tree just before the entry at $index: the left one for 0 */
	public function treeBefore(int $index): MstNode|Cid|null {
		return $index === 0 ? $this->left : $this->entries[$index - 1]['tree'];
	}

	public function setTreeBefore(int $index, MstNode|Cid|null $tree): void {
		if ($index === 0) {
			$this->left = $tree;
		} else {
			$this->entries[$index - 1]['tree'] = $tree;
		}
	}
}
