<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A relayed activity carries its author's Linked Data signature, and that
 * is what moves its origin from the relay to the author. These vectors are
 * signed the way Mastodon signs one (`ActivityPub::LinkedDataSignature#sign!`)
 * by an implementation written here from that algorithm, independent of
 * this app's own signer, so the two can only agree if they normalise alike:
 *
 *   options      = {type, creator, created}
 *   options_hash = sha256_hex(URDNA2015(options − type, id, signatureValue
 *                                       + @context identity/v1))
 *   document_hash = sha256_hex(URDNA2015(document − signature))
 *   signatureValue = base64(RSA-SHA256(options_hash . document_hash))
 *
 * The test key is generated per run; nothing here was signed by a live
 * Mastodon. The documents use Mastodon's own inline context terms.
 */
class LinkedDataSignatureInteropTest extends TestCase {
	use TBuildsServices;

	private const ACTOR = 'https://mastodon.example/users/carol';

	private static string $privateKey;
	private static string $publicKey;

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);
		self::$privateKey = $private;
		self::$publicKey = openssl_pkey_get_details($key)['key'];
	}

	/** A Create the way Mastodon serialises one, with its inline context. */
	private function mastodonCreate(array|string $context = []): array {
		return [
			'@context' => ($context !== []) ? $context : [
				'https://www.w3.org/ns/activitystreams',
				[
					'ostatus' => 'http://ostatus.org#',
					'atomUri' => 'ostatus:atomUri',
					'inReplyToAtomUri' => 'ostatus:inReplyToAtomUri',
					'conversation' => 'ostatus:conversation',
					'sensitive' => 'as:sensitive',
					'toot' => 'http://joinmastodon.org/ns#',
					'votersCount' => 'toot:votersCount',
				],
			],
			'id' => self::ACTOR . '/statuses/117000000000000001/activity',
			'type' => 'Create',
			'actor' => self::ACTOR,
			'published' => '2026-06-01T10:00:00Z',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::ACTOR . '/followers'],
			'object' => [
				'id' => self::ACTOR . '/statuses/117000000000000001',
				'type' => 'Note',
				'summary' => null,
				'inReplyTo' => null,
				'published' => '2026-06-01T10:00:00Z',
				'url' => 'https://mastodon.example/@carol/117000000000000001',
				'attributedTo' => self::ACTOR,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [self::ACTOR . '/followers'],
				'sensitive' => false,
				'atomUri' => self::ACTOR . '/statuses/117000000000000001',
				'conversation' => 'tag:mastodon.example,2026-06-01:objectId=1:objectType=Conversation',
				'content' => '<p>relayed, and still mine</p>',
				'contentMap' => ['en' => '<p>relayed, and still mine</p>'],
				'attachment' => [],
				'tag' => [],
			],
		];
	}

	/** Mastodon's algorithm, written out independently of LinkedDataSignature::sign(). */
	private static function signLikeMastodon(array $document, string $created): array {
		$options = ['type' => 'RsaSignature2017', 'creator' => self::ACTOR . '#main-key', 'created' => $created];
		$optionsForHash = ['@context' => 'https://w3id.org/identity/v1'] + array_diff_key(
			$options, array_flip(['type', 'id', 'signatureValue'])
		);
		$unsigned = $document;
		unset($unsigned['signature']);

		$toBeSigned = self::hash($optionsForHash) . self::hash($unsigned);
		openssl_sign($toBeSigned, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

		return $document + ['signature' => $options + ['signatureValue' => base64_encode($signature)]];
	}

	private static function hash(array $document): string {
		$nquads = jsonld_normalize(
			json_decode((string)json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
			['algorithm' => 'URDNA2015', 'format' => 'application/nquads', 'documentLoader' => [SignatureService::class, 'documentLoader']]
		);

		return hash('sha256', $nquads);
	}

	private function signatureService(): SignatureService {
		$carol = new Person();
		$carol->setId(self::ACTOR);
		$carol->setPublicKey(self::$publicKey);
		$actors = $this->createStub(CacheActorsRequest::class);
		$actors->method('getFromId')->willReturn($carol);
		// the refetch a failed verification makes, answered with the same key
		$fetched = $this->createStub(CacheActorService::class);
		$fetched->method('getFromId')->willReturn($carol);

		$config = $this->createStub(ConfigService::class);
		$config->method('withRequestTimeout')->willReturnCallback(
			static fn (int $timeout, callable $call): mixed => $call()
		);

		return $this->realService(SignatureService::class, [
			CacheActorsRequest::class => $actors,
			CacheActorService::class => $fetched,
			ConfigService::class => $config,
			FediverseService::class => $this->createStub(FediverseService::class),
			DurableCache::class => $this->createStub(DurableCache::class),
			'logger' => new NullLogger(),
		]);
	}

	private function received(array $signed): Note {
		$note = new Note();
		$note->setSource((string)json_encode($signed, JSON_UNESCAPED_SLASHES));
		$note->setActorId(self::ACTOR);

		return $note;
	}

	public function testAMastodonSignedCreateVerifies(): void {
		$signed = self::signLikeMastodon($this->mastodonCreate(), gmdate('Y-m-d\TH:i:s\Z'));

		$signature = new LinkedDataSignature();
		$signature->import($signed);
		$signature->setPublicKey(self::$publicKey);
		$this->assertTrue($signature->verify());

		$received = $this->received($signed);
		$this->assertTrue($this->signatureService()->checkObject($received));
		$this->assertSame('mastodon.example', $received->getOrigin(), 'the origin moves to the author');
		$this->assertSame(SignatureService::ORIGIN_SIGNATURE, $received->getOriginSource());
	}

	/** And what this app signs, Mastodon's algorithm verifies: the two hashes agree. */
	public function testWhatThisAppSignsMastodonsAlgorithmWouldAccept(): void {
		$created = '2026-06-01T10:00:01Z';
		$document = $this->mastodonCreate();

		$ours = new LinkedDataSignature();
		$ours->setType('RsaSignature2017')
			->setCreator(self::ACTOR . '#main-key')
			->setCreated($created)
			->setPrivateKey(self::$privateKey)
			->setObject($document);
		$ours->sign();

		$this->assertSame(
			self::signLikeMastodon($document, $created)['signature']['signatureValue'],
			$ours->getSignatureValue(),
			'RSA PKCS#1 v1.5 is deterministic: the same bytes signed give the same signature'
		);
	}

	public function testATamperedRelayedCreateIsRefused(): void {
		$signed = self::signLikeMastodon($this->mastodonCreate(), gmdate('Y-m-d\TH:i:s\Z'));
		$signed['object']['content'] = '<p>words put in my mouth</p>';

		$received = $this->received($signed);
		$this->assertFalse($this->signatureService()->checkObject($received));
		$this->assertSame('', $received->getOrigin(), 'the request signature still decides');
	}

	/**
	 * A context this app does not ship cannot be resolved (nothing is fetched
	 * during normalisation), so such a document cannot be verified: that is
	 * a false, not an error, and the delivery falls back to its HTTP
	 * signature. Pleroma and Akkoma name their LitePub context this way.
	 */
	public function testADocumentWithAnUnshippedContextFallsBackRatherThanFailing(): void {
		$document = $this->mastodonCreate(['https://www.w3.org/ns/activitystreams', 'https://akkoma.example/schemas/litepub-0.1.jsonld']);
		$signed = $document + ['signature' => [
			'type' => 'RsaSignature2017',
			'creator' => self::ACTOR . '#main-key',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'signatureValue' => base64_encode('not checked: the document cannot be normalised'),
		]];

		$signature = new LinkedDataSignature();
		$signature->import($signed);
		$signature->setPublicKey(self::$publicKey);
		$this->assertFalse($signature->verify());

		$received = $this->received($signed);
		$this->assertFalse($this->signatureService()->checkObject($received));
		$this->assertSame('', $received->getOrigin());
	}
}
