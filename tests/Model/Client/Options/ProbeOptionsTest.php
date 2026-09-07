<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client\Options;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ProbeOptionsTest extends TestCase {
	public function testDefaultsMatchTheMastodonTimelineDefaults(): void {
		$options = new ProbeOptions();

		$this->assertSame('', $options->getProbe());
		$this->assertFalse($options->isLocal());
		$this->assertFalse($options->isRemote());
		$this->assertFalse($options->isOnlyMedia());
		$this->assertSame(0, $options->getMinId());
		$this->assertSame(0, $options->getMaxId());
		$this->assertSame(0, $options->getSince());
		$this->assertSame(20, $options->getLimit());
		$this->assertFalse($options->isInverted());
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $options->getFormat());
	}

	public function testFromArrayReadsTheQueryParametersAsStrings(): void {
		$options = new ProbeOptions();

		$options->fromArray([
			'local' => 'true',
			'remote' => '0',
			'only_media' => '1',
			'min_id' => '10',
			'max_id' => '99',
			'since' => '5',
			'limit' => '40',
			'argument' => 'nextcloud',
		]);

		$this->assertTrue($options->isLocal());
		$this->assertFalse($options->isRemote());
		$this->assertTrue($options->isOnlyMedia());
		$this->assertSame(10, $options->getMinId());
		$this->assertSame(99, $options->getMaxId());
		$this->assertSame(5, $options->getSince());
		$this->assertSame(40, $options->getLimit());
		$this->assertSame('nextcloud', $options->getArgument());
	}

	public function testFromArrayKeepsCurrentValuesForMissingParameters(): void {
		$options = new ProbeOptions();
		$options->setLimit(7)->setLocal(true)->setArgument('cats');

		$options->fromArray(['max_id' => '3']);

		$this->assertSame(7, $options->getLimit());
		$this->assertTrue($options->isLocal());
		$this->assertSame('cats', $options->getArgument());
		$this->assertSame(3, $options->getMaxId());
	}

	public function testConstructorReadsTheRequestParameters(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['limit' => '15', 'local' => 'true']);

		$options = new ProbeOptions($request);

		$this->assertSame(15, $options->getLimit());
		$this->assertTrue($options->isLocal());
	}

	public function testProbeIsLowercased(): void {
		$options = new ProbeOptions();

		$options->setProbe('Home');

		$this->assertSame(ProbeOptions::HOME, $options->getProbe());
	}

	public function testJsonSerializeExposesTheProbeState(): void {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HASHTAG)
			->setAccountId('3')
			->setArgument('nextcloud')
			->setLimit(5)
			->setTypes(['Note'])
			->setExcludeTypes(['Announce'])
			->setInverted(true);

		$this->assertSame([
			'probe' => 'hashtag',
			'accountId' => '3',
			'local' => false,
			'remote' => false,
			'only_media' => false,
			'min_id' => 0,
			'max_id' => 0,
			'since' => 0,
			'limit' => 5,
			'argument' => 'nextcloud',
		], $options->jsonSerialize());
		$this->assertSame(['Note'], $options->getTypes());
		$this->assertSame(['Announce'], $options->getExcludeTypes());
		$this->assertTrue($options->isInverted());
	}
}
