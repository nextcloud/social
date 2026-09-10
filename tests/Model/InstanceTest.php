<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Instance;
use PHPUnit\Framework\TestCase;

class InstanceTest extends TestCase {
	public function testImportFromDatabaseReadsTheRowIncludingJsonColumns(): void {
		$instance = new Instance();

		$result = $instance->importFromDatabase([
			'local' => '1',
			'uri' => 'cloud.example.org',
			'title' => 'Nextcloud Social',
			'version' => '0.7.0',
			'short_description' => 'short',
			'description' => 'long',
			'email' => 'admin@cloud.example.org',
			'urls' => '{"streaming_api":"wss://cloud.example.org"}',
			'stats' => '{"user_count":3,"status_count":10,"domain_count":2}',
			'usage' => '{"users":{"active_month":2}}',
			'image' => 'https://cloud.example.org/thumb.png',
			'languages' => '["en","de"]',
			'account_prim' => 'abc',
		]);

		$this->assertSame($instance, $result);
		$this->assertTrue($instance->isLocal());
		$this->assertSame('cloud.example.org', $instance->getUri());
		$this->assertSame('Nextcloud Social', $instance->getTitle());
		$this->assertSame('0.7.0', $instance->getVersion());
		$this->assertSame('short', $instance->getShortDescription());
		$this->assertSame('long', $instance->getDescription());
		$this->assertSame('admin@cloud.example.org', $instance->getEmail());
		$this->assertSame(['streaming_api' => 'wss://cloud.example.org'], $instance->getUrls());
		$this->assertSame(['user_count' => 3, 'status_count' => 10, 'domain_count' => 2], $instance->getStats());
		$this->assertSame(['users' => ['active_month' => 2]], $instance->getUsage());
		$this->assertSame('https://cloud.example.org/thumb.png', $instance->getImage());
		$this->assertSame(['en', 'de'], $instance->getLanguages());
		$this->assertSame('abc', $instance->getAccountPrim());
		$this->assertFalse($instance->hasContactAccount());
	}

	private function populated(): Instance {
		return (new Instance())
			->setUri('cloud.example.org')
			->setTitle('Nextcloud Social')
			->setVersion('0.7.0')
			->setShortDescription('short')
			->setDescription('long')
			->setEmail('admin@cloud.example.org')
			->setUrls(['streaming_api' => 'wss://cloud.example.org'])
			->setStats(['user_count' => 3, 'status_count' => 0, 'domain_count' => 0])
			->setImage('https://cloud.example.org/thumb.png')
			->setLanguages(['en'])
			->setRegistrations(true)
			->setApprovalRequired(false)
			->setInvitesEnabled(true)
			->setConfiguration(['statuses' => ['max_characters' => 5000]])
			->setRules([['id' => '1', 'text' => 'Be kind.']]);
	}

	public function testJsonSerializeProducesTheMastodonInstanceEntity(): void {
		$json = $this->populated()->jsonSerialize();

		// assertEquals, not assertSame: the object-typed members are compared
		// by shape, not by identity
		$this->assertEquals([
			'uri' => 'cloud.example.org',
			'title' => 'Nextcloud Social',
			// Pleroma-style: clients gate features on the advertised version,
			// so the claim has to be one this app can honour
			'version' => '3.5.0 (compatible; Nextcloud Social 0.7.0)',
			'short_description' => 'short',
			'description' => 'long',
			'email' => 'admin@cloud.example.org',
			'urls' => (object)['streaming_api' => 'wss://cloud.example.org'],
			'stats' => (object)['user_count' => 3, 'status_count' => 0, 'domain_count' => 0],
			'thumbnail' => 'https://cloud.example.org/thumb.png',
			'languages' => ['en'],
			'registrations' => true,
			'approval_required' => false,
			'invites_enabled' => true,
			'configuration' => (object)['statuses' => ['max_characters' => 5000]],
			'rules' => [['id' => '1', 'text' => 'Be kind.']],
			// always present, so a client that declares it non-optional can
			// still decode the entity
			'contact_account' => null,
		], $json);
	}

	/**
	 * `urls`, `stats` and `configuration` are dictionaries in Mastodon's schema.
	 * An empty PHP array json-encodes as `[]`, and a client decoding
	 * `stats.user_count` out of a list fails and reports the whole instance as
	 * unreachable — which is what an unconfigured instance used to send.
	 */
	public function testTheDictionaryFieldsNeverSerialiseAsLists(): void {
		$json = json_decode((string)json_encode((new Instance())->jsonSerialize()), false);

		$this->assertIsObject($json->urls);
		$this->assertIsObject($json->stats);
		$this->assertIsObject($json->configuration);
	}

	public function testTheV2EntityCarriesTheSameFactsInMastodonsNewerShape(): void {
		$v2 = json_decode((string)json_encode($this->populated()->asV2()), false);

		$this->assertSame('cloud.example.org', $v2->domain);
		$this->assertSame('Nextcloud Social', $v2->title);
		$this->assertSame('3.5.0 (compatible; Nextcloud Social 0.7.0)', $v2->version);
		$this->assertSame('short', $v2->description);
		$this->assertSame('https://cloud.example.org/thumb.png', $v2->thumbnail->url);
		$this->assertSame(3, $v2->usage->users->active_month);
		$this->assertTrue($v2->registrations->enabled);
		$this->assertSame('admin@cloud.example.org', $v2->contact->email);
		$this->assertIsObject($v2->configuration->urls);
		$this->assertSame([['id' => '1', 'text' => 'Be kind.']], json_decode(json_encode($v2->rules), true));
	}

	public function testTheAdvertisedVersionIsOneTheAppCanHonour(): void {
		// 4.1.0 switched clients onto features that do not exist here (editing
		// with /source and /history, v2 filters, push), turning each of them
		// into a broken button rather than an absent one
		$this->assertSame('3.5.0', Instance::COMPAT_VERSION);
	}

	public function testContactAccountIsExposedWhenSet(): void {
		$admin = new Person();
		$admin->setPreferredUsername('admin');
		$instance = new Instance();

		$instance->setContactAccount($admin);

		$this->assertTrue($instance->hasContactAccount());
		$this->assertSame($admin, $instance->getContactAccount());
		$this->assertSame($admin, $instance->jsonSerialize()['contact_account']);
		$this->assertSame($admin, $instance->asV2()['contact']->account);
	}

	public function testAccountPrimIsNullUntilSet(): void {
		$instance = new Instance();

		$this->assertNull($instance->getAccountPrim());
		$this->assertSame('abc', $instance->setAccountPrim('abc')->getAccountPrim());
	}
}
