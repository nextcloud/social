<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Fuzz;

use InvalidArgumentException;
use OCA\Social\AP;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\MstReader;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamClient;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HttpMessageSignatureParser;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Atproto\Lexicon\RecordTypesTest;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Every parser that reads bytes or documents somebody else wrote, fed
 * mutations of good input.
 *
 * The property is the same for all of them: a parser either reads its input
 * or refuses it with the exception it documents, and does either in bounded
 * time and memory. A PHP error, a TypeError, a warning or an undocumented
 * exception is a crash an attacker can trigger at will, and an input that
 * takes seconds to refuse is a denial of service. A target may check more
 * as it goes — that what was read writes back to the same bytes, that a
 * frame reader accounts for every byte it consumed — and a broken property
 * is a finding too.
 *
 * In the unit suite each target gets a few hundred inputs from a fixed seed,
 * so a run is fast and repeatable. `FUZZ_ITERATIONS` and `FUZZ_SEED` widen
 * that for the nightly run; a finding names the seed, the input that failed
 * and its shrunk form, so it can be replayed with the same two values.
 */
class FuzzTest extends TestCase {
	use TActivityPubMocks;

	private const DEFAULT_SEED = 20261011;
	private const DEFAULT_ITERATIONS = 300;

	/** twice what Apache and nginx accept for one request header by default */
	private const HEADER_BYTES = 16384;

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
		parent::tearDown();
	}

	/** @return array<string, array{string}> */
	public static function targets(): array {
		$names = [
			'dag_cbor', 'car', 'mst', 'firehose_frames', 'jetstream_frames', 'lexicon',
			'http_message_signature', 'signature_header', 'linked_data_signature', 'activitypub_import',
		];

		return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
	}

	#[DataProvider('targets')]
	public function testTheParserReadsOrRefusesEveryMutationOfGoodInputWithinItsBudget(string $name): void {
		$target = $this->target($name);
		$fuzzer = new Fuzzer(self::seed(), hugeBytes: self::iterations() > self::DEFAULT_ITERATIONS ? 4 * 1024 * 1024 : 262144);

		$findings = $fuzzer->run($target, self::iterations());

		$this->assertSame(
			[], array_map(static fn (Finding $finding): string => $finding->describe(), $findings),
			$name . ' failed on inputs of seed ' . self::seed() . ' (replay with FUZZ_SEED=' . self::seed() . ' FUZZ_ITERATIONS=' . self::iterations() . ')'
		);
	}

	/** A finding is shrunk to the input that still fails the same way. */
	public function testAFindingIsShrunkToTheSmallestInputThatFailsTheSameWay(): void {
		$target = new Target(
			'shrink', [str_repeat('a', 300) . 'X' . str_repeat('b', 300)],
			static function (mixed $input): void {
				if (is_string($input) && str_contains($input, 'X')) {
					throw new RuntimeException('an X');
				}
			},
			[],
		);

		$findings = (new Fuzzer(1))->run($target, 1);

		$this->assertNotSame([], $findings);
		$this->assertSame('X', $findings[0]->input);
		$this->assertStringStartsWith('RuntimeException at tests/Fuzz/FuzzTest.php:', $findings[0]->failure);
	}

	/** A warning is a finding, and so is an exception the target does not document. */
	public function testAWarningAndAnUndocumentedExceptionAreFindingsAndADocumentedOneIsNot(): void {
		$fuzzer = new Fuzzer(1);
		$warns = new Target('warns', [''], static function (mixed $input): void {
			$list = [];
			$list['missing'] .= 'x';
		}, []);
		$refuses = new Target('refuses', [''], static function (mixed $input): void {
			throw new InvalidArgumentException('no');
		}, [InvalidArgumentException::class]);

		$this->assertStringStartsWith('ErrorException', (string)$fuzzer->attempt($warns, ''));
		$this->assertNull($fuzzer->attempt($refuses, ''));
		$this->assertStringStartsWith('InvalidArgumentException', (string)$fuzzer->attempt(new Target('undocumented', [''], $refuses->run, []), ''));
	}

	private static function seed(): int {
		$seed = getenv('FUZZ_SEED');

		return (is_string($seed) && ctype_digit($seed)) ? (int)$seed : self::DEFAULT_SEED;
	}

	private static function iterations(): int {
		$iterations = getenv('FUZZ_ITERATIONS');

		return (is_string($iterations) && ctype_digit($iterations) && (int)$iterations > 0) ? (int)$iterations : self::DEFAULT_ITERATIONS;
	}

	private function target(string $name): Target {
		return match ($name) {
			'dag_cbor' => $this->dagCbor(),
			'car' => $this->car(),
			'mst' => $this->mst(),
			'firehose_frames' => $this->frames('firehose_frames', WebSocketServer::readFrame(...), true),
			'jetstream_frames' => $this->frames('jetstream_frames', JetstreamClient::readFrame(...), false),
			'lexicon' => $this->lexicon(),
			'http_message_signature' => $this->httpMessageSignature(),
			'signature_header' => $this->signatureHeader(),
			'linked_data_signature' => $this->linkedDataSignature(),
			'activitypub_import' => $this->activityPubImport(),
		};
	}

	/** @return list<mixed> values of the DAG-CBOR model */
	private static function dagCborValues(): array {
		return [
			['$type' => 'app.bsky.feed.post', 'text' => 'hello', 'createdAt' => '2026-10-11T12:00:00.000Z', 'langs' => ['en']],
			['e' => [['k' => new Bytes('app.bsky.feed.post/3l2'), 'p' => 0, 't' => null, 'v' => Cid::forRaw('a')]], 'l' => null],
			[1, -1, 23, 24, 255, 256, 65535, 65536, PHP_INT_MAX, PHP_INT_MIN, true, false, null, '', new Bytes("\x00\xff"), new stdClass()],
			['did' => 'did:plc:abc', 'sig' => new Bytes(str_repeat("\x01", 64)), 'rev' => '3l2', 'data' => Cid::forRaw('b'), 'prev' => null, 'version' => 3],
			['nested' => ['a' => ['b' => ['c' => [[], [[]], ['x' => 'y']]]]]],
		];
	}

	/**
	 * Read, and what was read writes back to bytes that read back to the
	 * same value.
	 *
	 * Not to the very same bytes, although DRISL has one encoding per value:
	 * PHP holds a map key that is a decimal number as an integer, so a map
	 * `{"0": …}` is read as a list and written as one. That is a limit of
	 * the model, not of the decoder, which refuses every other second
	 * encoding of a value.
	 */
	private function dagCbor(): Target {
		return new Target(
			'dag_cbor', self::dagCborValues(),
			static function (mixed $bytes): void {
				$value = DagCbor::decode($bytes);
				if (DagCbor::decode(DagCbor::encode($value)) != $value) {
					throw new RuntimeException('a value that does not read back as it was written');
				}
			},
			[InvalidArgumentException::class],
			DagCbor::encode(...),
		);
	}

	private function car(): Target {
		$blocks = [];
		foreach (self::dagCborValues() as $value) {
			$bytes = DagCbor::encode($value);
			$blocks[Cid::forDagCbor($bytes)->toString()] = $bytes;
		}
		$root = Cid::parse(array_key_first($blocks));

		return new Target(
			'car', [[$root, $blocks]],
			static function (mixed $car): void {
				$read = Car::decode($car, 1000);
				foreach ($read['blocks'] as $cid => $bytes) {
					if (!Cid::forDagCbor($bytes)->equals(Cid::parse($cid)) && !Cid::forRaw($bytes)->equals(Cid::parse($cid))) {
						throw new RuntimeException('a block that does not hash to its CID');
					}
				}
			},
			[InvalidArgumentException::class],
			static fn (mixed $seed): string => is_array($seed) && ($seed[0] ?? null) instanceof Cid && is_array($seed[1] ?? null)
				? Car::encode([$seed[0]], $seed[1])
				: throw new InvalidArgumentException('not a CAR seed'),
		);
	}

	/**
	 * A tree's nodes as documents, links between them written as
	 * `['$node' => index]` and turned into CIDs when the input is read, so a
	 * mutated node is still found by the node that links to it.
	 */
	private function mst(): Target {
		$leaves = [];
		for ($i = 0; $i < 60; $i++) {
			$leaves['app.bsky.feed.post/' . Tid::encode(1688137381887007 + $i * 7919, $i % 1024)] = Cid::forRaw((string)$i);
		}
		$tree = (new Mst($leaves))->build();

		$order = [$tree->root->toString()];
		$documents = [];
		for ($i = 0; $i < count($order); $i++) {
			$node = DagCbor::decode($tree->blocks[$order[$i]]);
			$documents[$i] = self::linksToIndexes($node, $tree->blocks, $order);
		}

		return new Target(
			'mst', [$documents],
			static function (mixed $documents): void {
				if (!is_array($documents) || $documents === []) {
					throw new InvalidArgumentException('no nodes');
				}
				$blocks = [];
				$root = self::encodeNode($documents, array_key_first($documents), $blocks);
				$read = MstReader::leaves($root, $blocks);
				$last = null;
				foreach (array_keys($read) as $key) {
					if ($last !== null && strcmp((string)$key, $last) <= 0) {
						throw new RuntimeException('leaves out of order');
					}
					$last = (string)$key;
				}
			},
			[InvalidArgumentException::class],
		);
	}

	/**
	 * @param array<string, string> $blocks
	 * @param list<string> $order CIDs in the order the documents are numbered
	 */
	private static function linksToIndexes(mixed $value, array $blocks, array &$order): mixed {
		if ($value instanceof Cid && isset($blocks[$value->toString()])) {
			$index = array_search($value->toString(), $order, true);
			if ($index === false) {
				$order[] = $value->toString();
				$index = count($order) - 1;
			}

			return ['$node' => $index];
		}
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = self::linksToIndexes($item, $blocks, $order);
			}
		}

		return $value;
	}

	/**
	 * The CID of a node, its links resolved first. A node is encoded once;
	 * a link back to a node still being encoded, like one to a node that is
	 * not there, is a link to a block nobody has.
	 *
	 * @param array<string, string> $blocks
	 * @param array<int|string, Cid|null> $done index => CID, null while it is being encoded
	 */
	private static function encodeNode(array $documents, int|string $index, array &$blocks, array &$done = []): Cid {
		if (!array_key_exists($index, $documents) || (array_key_exists($index, $done) && $done[$index] === null)) {
			return Cid::forRaw('missing ' . $index);
		}
		if (isset($done[$index])) {
			return $done[$index];
		}

		$done[$index] = null;
		$resolve = static function (mixed $value) use (&$resolve, $documents, &$blocks, &$done): mixed {
			if (is_array($value) && count($value) === 1 && array_key_exists('$node', $value) && (is_int($value['$node']) || is_string($value['$node']))) {
				return self::encodeNode($documents, $value['$node'], $blocks, $done);
			}

			return is_array($value) ? array_map($resolve, $value) : $value;
		};
		$bytes = DagCbor::encode($resolve($documents[$index]));
		$cid = Cid::forDagCbor($bytes);
		$blocks[$cid->toString()] = $bytes;
		$done[$index] = $cid;

		return $cid;
	}

	/**
	 * Frames read off a buffer until it runs dry: every frame read takes
	 * exactly its header, its mask and its payload off the front, so the
	 * reader always moves on and never reads one frame as another.
	 *
	 * @param callable(string): ?array $read
	 */
	private function frames(string $name, callable $read, bool $masked): Target {
		$frame = static function (string $payload, int $opcode) use ($masked): string {
			$length = strlen($payload);
			$head = chr(0x80 | $opcode);
			$bit = $masked ? 0x80 : 0;
			$head .= ($length < 126) ? chr($bit | $length) : (($length < 65536) ? chr($bit | 126) . pack('n', $length) : chr($bit | 127) . pack('J', $length));
			if (!$masked) {
				return $head . $payload;
			}
			$mask = "\x12\x34\x56\x78";
			$out = '';
			for ($i = 0; $i < $length; $i++) {
				$out .= $payload[$i] ^ $mask[$i % 4];
			}

			return $head . $mask . $out;
		};
		$seeds = [
			$frame('{"op":1}', 1),
			$frame(str_repeat('a', 200), 2) . $frame('', 9) . $frame('bye', 8),
			$frame(str_repeat('b', 70000), 1),
		];

		return new Target(
			$name, $seeds,
			static function (mixed $buffer) use ($read): void {
				$frames = 0;
				while (true) {
					$before = $buffer;
					$frame = $read($buffer);
					if ($frame === null) {
						return;
					}
					$payload = is_string($frame[1]) ? $frame[1] : $frame[2];
					$second = ord($before[1]);
					$header = 2 + match ($second & 0x7f) {
						126 => 2,
						127 => 8,
						default => 0,
					} + ((($second & 0x80) !== 0) ? 4 : 0);
					if (strlen($before) - strlen($buffer) !== $header + strlen($payload)) {
						throw new RuntimeException(sprintf(
							'a frame took %d bytes off the buffer for a %d-byte header and a %d-byte payload',
							strlen($before) - strlen($buffer), $header, strlen($payload)
						));
					}
					if (++$frames > 100000) {
						throw new RuntimeException('the reader does not move on');
					}
				}
			},
			[AtprotoException::class],
			self::asBytes(...),
			// what one read off the socket hands the reader, beyond a frame it already holds
			maxBytes: 65536 + 14,
		);
	}

	/** A seed that is already the bytes; a mutation into anything else has no encoding. */
	private static function asBytes(mixed $seed): string {
		return is_string($seed) ? $seed : throw new InvalidArgumentException('not bytes');
	}

	private function lexicon(): Target {
		$lexicon = new Lexicon();

		return new Target(
			'lexicon', array_map(static fn (array $case): array => $case[0], array_values(RecordTypesTest::records())),
			static function (mixed $record) use ($lexicon): void {
				$value = DagCbor::fromLexJson($record);
				if (!is_array($value)) {
					throw new LexiconException('not a record');
				}
				$lexicon->validateRecord($value);
			},
			[LexiconException::class, InvalidArgumentException::class],
		);
	}

	/** Both headers parsed, and the signature base built from what was parsed, as the verifier does. */
	private function httpMessageSignature(): Target {
		$parser = new HttpMessageSignatureParser();
		$headers = [
			'date' => 'Tue, 20 Apr 2021 02:07:55 GMT',
			'content-type' => 'application/json',
			'content-digest' => 'sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
			'content-length' => '18',
		];
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[strtolower($name)] ?? '');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getRequestUri')->willReturn('/foo?param=Value&Pet=dog');
		$request->method('getServerProtocol')->willReturn('https');

		return new Target(
			'http_message_signature',
			[
				'sig-b23=("date" "@method" "@path" "@query" "@authority" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-rsa-pss"'
					. "\n" . 'sig-b23=:' . base64_encode(str_repeat("\x5a", 256)) . ':',
				'sig1=("@method" "@target-uri" "content-digest");created=1618884473;keyid="https://remote.example/users/bob#main-key";alg="rsa-v1_5-sha256";nonce="b3k2pp5k7z-50gnwp.yemd";tag="ap", sig2=();created=1;expires=2;keyid="k";alg=ed25519;flag'
					. "\n" . 'sig1=:' . base64_encode('one') . ':, sig2=:' . base64_encode('two') . ':',
			],
			static function (mixed $headers) use ($parser, $request): void {
				[$input, $signature] = array_pad(explode("\n", $headers, 2), 2, '');
				$parser->parseSignature($signature);
				foreach ($parser->parseSignatureInput($input) as $member) {
					$parser->signatureBase($request, $member['components'], $member['serialized'], 'example.com');
				}
			},
			[SignatureException::class],
			self::asBytes(...),
			maxBytes: self::HEADER_BYTES,
		);
	}

	/** The draft-cavage `Signature` header and the `Digest` header, as SignatureService splits them. */
	private function signatureHeader(): Target {
		$service = (new ReflectionClass(SignatureService::class))->newInstanceWithoutConstructor();
		$signature = (new ReflectionClass(SignatureService::class))->getMethod('parseSignatureHeader');
		$digest = (new ReflectionClass(SignatureService::class))->getMethod('parseDigestHeader');

		return new Target(
			'signature_header',
			[
				'keyId="https://remote.example/users/bob#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode(str_repeat('s', 256)) . '"'
					. "\n" . 'SHA-256=X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=, sha-512=:' . base64_encode(str_repeat('d', 64)) . ':',
			],
			static function (mixed $headers) use ($service, $signature, $digest): void {
				[$first, $second] = array_pad(explode("\n", $headers, 2), 2, '');
				$signature->invoke($service, $first);
				$digest->invoke($service, $second);
			},
			[],
			self::asBytes(...),
			maxBytes: self::HEADER_BYTES,
		);
	}

	/**
	 * An activity carrying a Linked Data signature, imported and verified as
	 * an inbound one is: verifying either holds or does not, whatever the
	 * document says.
	 */
	private function linkedDataSignature(): Target {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$this->assertNotFalse($key);
		openssl_pkey_export($key, $private);
		$public = (string)openssl_pkey_get_details($key)['key'];

		$activity = [
			'@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
			'id' => 'https://remote.example/users/bob/statuses/1/activity',
			'type' => 'Create',
			'actor' => 'https://remote.example/users/bob',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => [
				'id' => 'https://remote.example/users/bob/statuses/1',
				'type' => 'Note',
				'content' => '<p>signed</p>',
				'attributedTo' => 'https://remote.example/users/bob',
			],
		];
		$signer = new LinkedDataSignature();
		$signer->setType('RsaSignature2017');
		$signer->setCreator('https://remote.example/users/bob#main-key');
		$signer->setCreated('2026-10-11T12:00:00Z');
		$signer->setObject($activity);
		$signer->setPrivateKey((string)$private);
		$signer->sign();
		$signed = $activity + ['signature' => [
			'type' => $signer->getType(),
			'creator' => $signer->getCreator(),
			'created' => $signer->getCreated(),
			'signatureValue' => $signer->getSignatureValue(),
		]];

		$verifier = new LinkedDataSignature();
		$verifier->import($signed);
		$verifier->setPublicKey($public);
		$this->assertTrue($verifier->verify(), 'the seed is signed');

		return new Target(
			'linked_data_signature', [$signed],
			static function (mixed $document) use ($public): void {
				$signature = new LinkedDataSignature();
				$signature->import(is_array($document) ? $document : []);
				$signature->setPublicKey($public);
				$signature->verify();
			},
			[LinkedDataSignatureMissingException::class],
			share: 0.3,
		);
	}

	/**
	 * The inbox from the body on: the bytes read as an activity, and the
	 * activity taken in by the dispatcher, whose persistence is stubbed.
	 * What may come out is what the inbox answers with a 4xx or a 202.
	 */
	private function activityPubImport(): Target {
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
		$this->installActivityPub();
		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudUrl')->willReturn(self::cloudUrl());
		$import = new ImportService(
			$this->createStub(MiscService::class),
			$this->createStub(ModerationService::class),
			$this->createStub(RelayService::class),
		);

		$seeds = [];
		foreach (glob(__DIR__ . '/../Federation/fixtures/*.json') ?: [] as $file) {
			$json = str_replace(
				['{{LOCAL_POST}}', '{{ALICE}}'],
				['https://cloud.example.org/apps/social/@alice/1700000000123', 'https://cloud.example.org/apps/social/@alice'],
				(string)file_get_contents($file)
			);
			$seeds[] = json_decode($json, true);
		}
		$this->assertGreaterThan(30, count($seeds));

		return new Target(
			'activitypub_import', $seeds,
			static function (mixed $json) use ($import): void {
				$activity = $import->importFromJson((string)$json);
				$origin = (string)parse_url($activity->getActorId() ?: $activity->getId(), PHP_URL_HOST);
				$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());
				$import->parseIncomingRequest($activity);
			},
			[ActivityPubFormatException::class, ItemUnknownException::class, InvalidOriginException::class],
			static fn (mixed $document): string => (string)json_encode($document, JSON_THROW_ON_ERROR),
			maxBytes: InboxLimiter::MAX_BODY,
		);
	}
}
