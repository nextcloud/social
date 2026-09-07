<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamDetails;
use PHPUnit\Framework\TestCase;

class StreamDetailsTest extends TestCase {
	public function testConstructorStoresTheStream(): void {
		$note = new Note();

		$details = new StreamDetails($note);

		$this->assertSame($note, $details->getStream());
		$this->assertSame([], $details->getHomeViewers());
		$this->assertSame([], $details->getDirectViewers());
		$this->assertFalse($details->isPublic());
		$this->assertFalse($details->isFederated());
	}

	public function testViewersCanBeAddedAndReplaced(): void {
		$alice = (new Person())->setPreferredUsername('alice');
		$bob = (new Person())->setPreferredUsername('bob');
		$details = new StreamDetails(new Note());

		$details->addHomeViewer($alice)->addHomeViewer($bob);
		$details->addDirectViewer($bob);

		$this->assertSame([$alice, $bob], $details->getHomeViewers());
		$this->assertSame([$bob], $details->getDirectViewers());

		$details->setHomeViewers([$bob])->setDirectViewers([]);
		$this->assertSame([$bob], $details->getHomeViewers());
		$this->assertSame([], $details->getDirectViewers());
	}

	public function testJsonSerializeDescribesTheDelivery(): void {
		$note = new Note();
		$alice = (new Person())->setPreferredUsername('alice');
		$details = (new StreamDetails(new Note()))
			->setStream($note)
			->addHomeViewer($alice)
			->setPublic(true)
			->setFederated(true);

		$this->assertSame([
			'stream' => $note,
			'homeViewers' => [$alice],
			'directViewers' => [],
			'public' => true,
			'federated' => true,
		], $details->jsonSerialize());
	}
}
