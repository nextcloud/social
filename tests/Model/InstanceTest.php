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

	public function testJsonSerializeProducesTheMastodonInstanceEntity(): void {
		$instance = (new Instance())
			->setUri('cloud.example.org')
			->setTitle('Nextcloud Social')
			->setVersion('0.7.0')
			->setShortDescription('short')
			->setDescription('long')
			->setEmail('admin@cloud.example.org')
			->setUrls(['streaming_api' => 'wss://cloud.example.org'])
			->setStats(['user_count' => 3])
			->setImage('https://cloud.example.org/thumb.png')
			->setLanguages(['en'])
			->setRegistrations(true)
			->setApprovalRequired(false)
			->setInvitesEnabled(true);

		$json = $instance->jsonSerialize();

		$this->assertSame([
			'uri' => 'cloud.example.org',
			'title' => 'Nextcloud Social',
			// Pleroma-style: clients gate features on the advertised version
			'version' => '4.1.0 (compatible; Nextcloud Social 0.7.0)',
			'short_description' => 'short',
			'description' => 'long',
			'email' => 'admin@cloud.example.org',
			'urls' => ['streaming_api' => 'wss://cloud.example.org'],
			'stats' => ['user_count' => 3],
			'thumbnail' => 'https://cloud.example.org/thumb.png',
			'languages' => ['en'],
			'registrations' => true,
			'approval_required' => false,
			'invites_enabled' => true,
		], $json);
		$this->assertArrayNotHasKey('contact_account', $json);
	}

	public function testContactAccountIsExposedWhenSet(): void {
		$admin = new Person();
		$admin->setPreferredUsername('admin');
		$instance = new Instance();

		$instance->setContactAccount($admin);

		$this->assertTrue($instance->hasContactAccount());
		$this->assertSame($admin, $instance->getContactAccount());
		$this->assertSame($admin, $instance->jsonSerialize()['contact_account']);
	}

	public function testAccountPrimIsNullUntilSet(): void {
		$instance = new Instance();

		$this->assertNull($instance->getAccountPrim());
		$this->assertSame('abc', $instance->setAccountPrim('abc')->getAccountPrim());
	}
}
