<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use PHPUnit\Framework\TestCase;

/**
 * The `Filter` entity as a client receives it, and the two decisions that can
 * be made about a filter without a database: what its contexts are, and
 * whether it still applies.
 */
class FilterTest extends TestCase {
	private function filter(): Filter {
		return (new Filter())
			->setId(7)
			->setTitle('spoilers')
			->setContexts([Filter::CONTEXT_HOME, Filter::CONTEXT_PUBLIC])
			->setAction(Filter::ACTION_HIDE)
			->addKeyword((new FilterKeyword())->setId(3)->setKeyword('banana')->setWholeWord(true));
	}

	public function testTheEntityIsTheOneMastodonDocuments(): void {
		$entity = $this->filter()->jsonSerialize();

		$this->assertSame(
			['id', 'title', 'context', 'expires_at', 'filter_action', 'keywords', 'statuses'],
			array_keys($entity)
		);
		$this->assertSame('7', $entity['id'], 'ids are strings in the client API');
		$this->assertSame(['home', 'public'], $entity['context']);
		$this->assertSame('hide', $entity['filter_action']);
		$this->assertSame(
			[['id' => '3', 'keyword' => 'banana', 'whole_word' => true]], $entity['keywords']
		);
		$this->assertSame([], $entity['statuses'], 'this app has no per-status filters');
	}

	public function testAFilterThatNeverExpiresSaysNullRatherThanADate(): void {
		$this->assertNull($this->filter()->jsonSerialize()['expires_at']);
	}

	public function testAnExpiryIsSentAsTheTimestampItIs(): void {
		$entity = $this->filter()->setExpiresAt(1789234567)->jsonSerialize();

		$this->assertSame(gmdate('Y-m-d\TH:i:s', 1789234567) . '.000Z', $entity['expires_at']);
	}

	public function testTheFilterInsideAFilteredStatusCarriesNoKeywords(): void {
		// Mastodon leaves them out there, and a client reads the title and the
		// action off it to decide what to blur and what to say
		$this->assertSame(
			['id', 'title', 'context', 'expires_at', 'filter_action'],
			array_keys($this->filter()->exportAsResultFilter())
		);
	}

	public function testAContextNobodyReadsIsNotStored(): void {
		// it would be a filter the account believes applies somewhere it does not
		$this->assertSame(
			['home'], Filter::normaliseContexts(['home', 'elsewhere', 'direct'])
		);
	}

	public function testContextsAreOrderedAndDeduplicated(): void {
		$this->assertSame(
			['home', 'notifications', 'public'],
			Filter::normaliseContexts([' PUBLIC ', 'home', 'home', 'notifications'])
		);
	}

	public function testTheStoredFormOfTheContextsRoundTrips(): void {
		$stored = $this->filter()->exportContexts();

		$this->assertSame('home,public', $stored);
		$this->assertSame(['home', 'public'], (new Filter())->importContexts($stored)->getContexts());
	}

	public function testAnUnknownActionIsNotAnAction(): void {
		// the caller answers 422 rather than storing an action nothing applies
		$this->assertSame('', Filter::normaliseAction('blur'));
		$this->assertSame('warn', Filter::normaliseAction('WARN'));
		$this->assertSame('hide', Filter::normaliseAction(' hide '));
	}

	public function testATitleIsCutToTheColumnInCharactersRatherThanBytes(): void {
		$title = Filter::normaliseTitle(str_repeat('é', 400));

		$this->assertSame(Filter::MAX_TITLE, mb_strlen($title));
	}

	public function testAFilterWithNoExpiryIsActiveForEver(): void {
		$this->assertTrue($this->filter()->isActive());
		$this->assertTrue($this->filter()->isActive(PHP_INT_MAX));
	}

	public function testAnExpiryInThePastIsNotActive(): void {
		$filter = $this->filter()->setExpiresAt(1000);

		$this->assertFalse($filter->isActive(2000));
		$this->assertTrue($filter->isActive(999));
	}

	public function testAKeywordIsCutToTheColumnInCharactersRatherThanBytes(): void {
		$keyword = FilterKeyword::normalise('  ' . str_repeat('é', 400) . '  ');

		$this->assertSame(FilterKeyword::MAX_KEYWORD, mb_strlen($keyword));
	}

	public function testSomethingThatIsNotAKeywordNormalisesToNothing(): void {
		// an empty keyword would match every status there is
		$this->assertSame('', FilterKeyword::normalise('   '));
	}
}
