<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Exceptions\ItemNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Model\SimpleDataStore;
use PHPUnit\Framework\TestCase;

class SimpleDataStoreTest extends TestCase {
	public function testConstructorAcceptsInitialDataOrNothing(): void {
		$this->assertSame(['a' => 1], (new SimpleDataStore(['a' => 1]))->gAll());
		$this->assertSame([], (new SimpleDataStore())->gAll());
		$this->assertSame([], (new SimpleDataStore(null))->gAll());
	}

	public function testStringValues(): void {
		$store = new SimpleDataStore();

		$store->s('host', 'cloud.example.org')->a('tags', 'a')->a('tags', 'b');

		$this->assertSame('cloud.example.org', $store->g('host'));
		$this->assertSame('', $store->g('missing'));
		$this->assertSame(['a', 'b'], $store->gArray('tags'));
	}

	public function testIntValues(): void {
		$store = new SimpleDataStore();

		$store->sInt('count', 3)->aInt('ids', 1)->aInt('ids', 2);

		$this->assertSame(3, $store->gInt('count'));
		$this->assertSame(0, $store->gInt('missing'));
		$this->assertSame([1, 2], $store->gArray('ids'));
	}

	public function testBoolValues(): void {
		$store = new SimpleDataStore();

		$store->sBool('ok', true)->aBool('flags', true)->aBool('flags', false);

		$this->assertTrue($store->gBool('ok'));
		$this->assertFalse($store->gBool('missing'));
		$this->assertSame([true, false], $store->gArray('flags'));
	}

	public function testArrayValues(): void {
		$store = new SimpleDataStore();

		$store->sArray('list', ['a'])->aArray('list', ['b', 'c']);
		$store->aArray('fresh', ['x']);

		$this->assertSame(['a', 'b', 'c'], $store->gArray('list'));
		$this->assertSame(['x'], $store->gArray('fresh'));
		$this->assertSame([], $store->gArray('missing'));
	}

	public function testObjectValues(): void {
		$store = new SimpleDataStore();
		$first = new InstancePath('https://a.example/inbox');
		$second = new InstancePath('https://b.example/inbox');

		$store->sObj('main', $first)->aObj('all', $first)->aObj('all', $second);

		$this->assertSame($first, $store->gItem('main'));
		$this->assertSame([$first, $second], $store->gItem('all'));
	}

	public function testNestedStores(): void {
		$store = new SimpleDataStore();
		$inner = new SimpleDataStore(['k' => 'v']);

		$store->sData('one', $inner)->aData('many', $inner)->aData('many', new SimpleDataStore(['k' => 'w']));

		$this->assertSame(['k' => 'v'], $store->gArray('one'));
		$this->assertSame('v', $store->gData('one')->g('k'));
		$this->assertSame('v', $store->g('one.k'), 'dotted paths reach into nested data');
		$this->assertSame([['k' => 'v'], ['k' => 'w']], $store->gArray('many'));
		$this->assertSame([], $store->gData('missing')->gAll());
	}

	public function testGItemReturnsTheRawValueOrThrows(): void {
		$store = new SimpleDataStore(['raw' => 1.5]);

		$this->assertSame(1.5, $store->gItem('raw'));

		$this->expectException(ItemNotFoundException::class);
		$store->gItem('missing');
	}

	public function testKeysAndHasKey(): void {
		$store = new SimpleDataStore(['a' => 1, 'b' => null]);

		$this->assertSame(['a', 'b'], $store->keys());
		$this->assertTrue($store->hasKey('a'));
		$this->assertTrue($store->hasKey('b'), 'a null value still counts as a key');
		$this->assertFalse($store->hasKey('c'));
		$this->assertTrue($store->haveKey('a'));
	}

	public function testHasKeysChecksAllAndCanInsist(): void {
		$store = new SimpleDataStore(['a' => 1, 'b' => 2]);

		$this->assertTrue($store->hasKeys(['a', 'b']));
		$this->assertFalse($store->hasKeys(['a', 'c']));
		$this->assertTrue($store->haveKeys(['a']));

		$this->expectException(MalformedArrayException::class);
		$this->expectExceptionMessage('c missing in ["a","b"]');
		$store->hasKeys(['a', 'c'], true);
	}

	public function testDefaultFillsOnlyMissingKeys(): void {
		$store = new SimpleDataStore(['a' => 1]);

		$store->default(['a' => 9, 'b' => 2]);

		$this->assertSame(['a' => 1, 'b' => 2], $store->gAll());
	}

	public function testSAllReplacesEverythingAndJsonSerializeExposesIt(): void {
		$store = new SimpleDataStore(['old' => 1]);

		$store->sAll(['new' => true]);

		$this->assertSame(['new' => true], $store->gAll());
		$this->assertSame(['new' => true], $store->jsonSerialize());
		$this->assertSame('{"new":true}', json_encode($store));
	}
}
