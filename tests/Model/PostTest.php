<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Post;
use PHPUnit\Framework\TestCase;

class PostTest extends TestCase {
	private function alice(): Person {
		$alice = new Person();
		$alice->setId('https://cloud.example.org/apps/social/@alice')
			->setPreferredUsername('alice');

		return $alice;
	}

	public function testConstructorStoresTheAuthor(): void {
		$alice = $this->alice();

		$post = new Post($alice);

		$this->assertSame($alice, $post->getActor());
		$this->assertSame([], $post->getTo());
		$this->assertSame('', $post->getContent());
	}

	public function testAddToTrimsSkipsEmptyAndDeduplicates(): void {
		$post = new Post($this->alice());

		$post->addTo(' @bob@mastodon.social ')
			->addTo('@bob@mastodon.social')
			->addTo('')
			->addTo('   ')
			->addTo('@carol@remote.example');

		$this->assertSame(['@bob@mastodon.social', '@carol@remote.example'], $post->getTo());

		$post->setTo(['@dave@remote.example']);
		$this->assertSame(['@dave@remote.example'], $post->getTo());
	}

	public function testAddHashtagTrimsSkipsEmptyAndDeduplicates(): void {
		$post = new Post($this->alice());

		$post->addHashtag(' nextcloud ')
			->addHashtag('nextcloud')
			->addHashtag('')
			->addHashtag('fediverse');

		$this->assertSame(['nextcloud', 'fediverse'], $post->getHashtags());

		$post->setHashtags(['cats']);
		$this->assertSame(['cats'], $post->getHashtags());
	}

	public function testContentReplyTypeAndAttachmentsAreStored(): void {
		$post = new Post($this->alice());
		$media = (new MediaAttachment())->setId('4');
		$document = new Document();

		$post->setContent('Hello world');
		$post->setReplyTo('https://mastodon.social/users/bob/statuses/1')
			->setType(Stream::TYPE_UNLISTED)
			->setAttachments(['4'])
			->setMedias([$media])
			->setDocuments([$document]);

		$this->assertSame('Hello world', $post->getContent());
		$this->assertSame('https://mastodon.social/users/bob/statuses/1', $post->getReplyTo());
		$this->assertSame('unlisted', $post->getType());
		$this->assertSame(['4'], $post->getAttachments());
		$this->assertSame([$media], $post->getMedias());
		$this->assertSame([$document], $post->getDocuments());
	}

	public function testJsonSerializeDescribesThePost(): void {
		$alice = $this->alice();
		$post = new Post($alice);
		$post->setContent('Hello #nextcloud');
		$post->addTo('@bob@mastodon.social')
			->addHashtag('nextcloud')
			->setReplyTo('https://mastodon.social/users/bob/statuses/1')
			->setType(Stream::TYPE_PUBLIC)
			->setAttachments(['4']);

		$this->assertSame([
			'actor' => $alice,
			'to' => ['@bob@mastodon.social'],
			'replyTo' => 'https://mastodon.social/users/bob/statuses/1',
			'content' => 'Hello #nextcloud',
			'attachments' => ['4'],
			'hashtags' => ['nextcloud'],
			'type' => 'public',
		], $post->jsonSerialize());
	}
}
