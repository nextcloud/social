<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\Test;
use OCA\Social\Tools\Model\SimpleDataStore;
use PHPUnit\Framework\TestCase;

class TestTest extends TestCase {
	public function testConstructorStoresNameAndSeverity(): void {
		$test = new Test('webfinger', Test::SEVERITY_MANDATORY);

		$this->assertInstanceOf(SimpleDataStore::class, $test);
		$this->assertSame('webfinger', $test->getName());
		$this->assertSame('mandatory', $test->getSeverity());
		$this->assertFalse($test->isSuccess());
		$this->assertSame([], $test->getMessages());
	}

	public function testSeverityDefaultsToOptional(): void {
		$this->assertSame(Test::SEVERITY_OPTIONAL, (new Test('x'))->getSeverity());
	}

	public function testDataStoreIsInitialized(): void {
		// Without parent::__construct() the store was null and any read fataled.
		$test = new Test('x');

		$this->assertSame('', $test->g('missing'));
		$this->assertSame([], $test->gAll());
	}

	public function testMessagesAccumulate(): void {
		$test = new Test('x');

		$test->addMessage('first')->addMessage('second');

		$this->assertSame(['first', 'second'], $test->getMessages());
	}

	public function testJsonSerializeIncludesDetailsMessagesAndSuccess(): void {
		$test = new Test('webfinger', Test::SEVERITY_MANDATORY);
		$test->s('host', 'cloud.example.org');
		$test->sInt('status', 200);
		$test->addMessage('resolved')
			->setSuccess(true);

		$this->assertSame([
			'name' => 'webfinger',
			'severity' => 'mandatory',
			'details' => ['host' => 'cloud.example.org', 'status' => 200],
			'message' => ['resolved'],
			'success' => true,
		], $test->jsonSerialize());
	}

	public function testJsonSerializeAlwaysReportsSuccessEvenWhenFalse(): void {
		$test = new Test('x');
		$test->s('k', 'v');

		$json = $test->jsonSerialize();

		$this->assertArrayNotHasKey('message', $json, 'empty lists are filtered out');
		$this->assertFalse($json['success']);
	}
}
