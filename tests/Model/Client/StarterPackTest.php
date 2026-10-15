<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\StarterPack;
use PHPUnit\Framework\TestCase;

/**
 * A starter pack as a client decodes it.
 *
 * What the entity is for is a page that draws a name, a handle and a picture
 * per account, so the shape of `accounts` is the contract and not a detail: an
 * ActivityPub actor and a Mastodon `Account` share no field the page reads.
 */
class StarterPackTest extends TestCase {
	private function pack(): StarterPack {
		$bob = new Person();
		$bob->setId('https://remote.example/users/bob')
			->setNid(7)
			->setPreferredUsername('bob')
			->setName('Bob')
			->setAccount('bob@remote.example');

		return (new StarterPack(
			'friends',
			'Friends',
			'People worth following',
			['bob@remote.example', 'carol@gone.example']
		))->setAccounts([$bob])->setUnresolved(['carol@gone.example']);
	}

	public function testTheIndexIsNamesAndCountsAndResolvesNobody(): void {
		$entity = (new StarterPack('friends', 'Friends', '', ['bob@remote.example']))->jsonSerialize();

		$this->assertSame(
			['id', 'name', 'description', 'source', 'curator', 'url', 'topic', 'language', 'size', 'accounts', 'unresolved'],
			array_keys($entity)
		);
		$this->assertSame('friends', $entity['id']);
		// a pack this instance wrote has no curator elsewhere
		$this->assertNull($entity['curator']);
		$this->assertNull($entity['url']);
		// the size is the handles, which is what the index can say without
		// asking anybody else's server
		$this->assertSame(1, $entity['size']);
		$this->assertSame([], $entity['accounts']);
	}

	public function testAPackReadInSaysWhoCuratedItAndWhereItLives(): void {
		$entity = (new StarterPack('news-1a2b3c4d', 'News', 'Papers', ['https://news.example/users/paper'], StarterPack::SOURCE_FEDIVERSE))
			->setOrigin('Gargron@mastodon.social', 'https://mastodon.social/collections/1', 'news', 'en')
			->jsonSerialize();

		$this->assertSame('fediverse', $entity['source']);
		$this->assertSame('Gargron@mastodon.social', $entity['curator']);
		$this->assertSame('https://mastodon.social/collections/1', $entity['url']);
		$this->assertSame('news', $entity['topic']);
		$this->assertSame('en', $entity['language']);
		$this->assertSame(1, $entity['size']);
	}

	public function testAPackListedWithoutItsHandlesSaysTheCountItWasListedWith(): void {
		$entity = (new StarterPack('news-1a2b3c4d', 'News', 'Papers', [], StarterPack::SOURCE_FEDIVERSE))
			->setSize(25)
			->jsonSerialize();

		$this->assertSame(25, $entity['size']);
	}

	public function testTheAccountsAreSerialisedForAClient(): void {
		// and not as ActivityPub, which is what a Person exports by default:
		// an opened pack drew a row per account with no name, no handle and no
		// picture in it, because every key the page reads was absent
		$pack = $this->pack();
		$pack->jsonSerialize();

		$this->assertSame(ACore::FORMAT_LOCAL, $pack->getAccounts()[0]->getExportFormat());
	}

	public function testAnAccountCarriesTheFieldsThePageDraws(): void {
		$account = json_decode((string)json_encode($this->pack()), true)['accounts'][0];

		$this->assertSame('7', $account['id']);
		$this->assertSame('bob@remote.example', $account['acct']);
		$this->assertSame('Bob', $account['display_name']);
		$this->assertArrayHasKey('avatar', $account);
		$this->assertArrayNotHasKey('@context', $account);
	}

	public function testWhatCouldNotBeReachedIsReportedRatherThanDropped(): void {
		// a pack that quietly shrinks looks like one somebody wrote badly
		$entity = $this->pack()->jsonSerialize();

		$this->assertSame(['carol@gone.example'], $entity['unresolved']);
		$this->assertSame(2, $entity['size']);
	}

	public function testAShippedPackAndAConfiguredOneSayWhichTheyAre(): void {
		$shipped = new StarterPack('a', 'A', '', ['bob@remote.example']);
		$local = new StarterPack('b', 'B', '', ['bob@remote.example'], StarterPack::SOURCE_LOCAL);

		$this->assertSame('builtin', $shipped->jsonSerialize()['source']);
		$this->assertSame('local', $local->jsonSerialize()['source']);
	}
}
