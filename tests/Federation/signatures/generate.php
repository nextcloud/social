<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Writes one signed inbox delivery per fediverse implementation into this
 * directory, each signed the way that project's own source signs one: the
 * headers it sends and the order it covers them in, its algorithm label, the
 * shape of its keyId, its Digest, its Date format, and the order of the
 * Signature header's parameters. The source each one follows is named in
 * `shapedAfter`. None of them was captured from a running server.
 *
 * Every run makes new keys and new dates, so every file changes; that is
 * expected. Run from anywhere:
 *
 *     php tests/Federation/signatures/generate.php
 */

const HOST = 'cloud.example.com';
const INBOX = '/index.php/apps/social/inbox';
const LOCAL_ACTOR = 'https://cloud.example.com/index.php/apps/social/@alice';

/** @return array{0: string, 1: string} a fresh RSA key pair, private and public PEM */
function keyPair(): array {
	$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
	openssl_pkey_export($key, $private);

	return [$private, openssl_pkey_get_details($key)['key']];
}

function rsaSha256(string $data, string $privateKey): string {
	openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

	return base64_encode($signature);
}

/**
 * A draft-cavage `Signature` header over `$covered`, in the order given.
 *
 * @param array<string, string> $covered lower-cased name => value, `(request-target)` included
 * @param list<string> $order the parameters in the order the implementation writes them
 */
function cavage(array $covered, string $privateKey, string $keyId, string $algorithm, array $order = ['keyId', 'algorithm', 'headers', 'signature']): string {
	$lines = [];
	foreach ($covered as $name => $value) {
		$lines[] = $name . ': ' . $value;
	}
	$params = [
		'keyId' => $keyId,
		'algorithm' => $algorithm,
		'headers' => implode(' ', array_keys($covered)),
		'signature' => rsaSha256(implode("\n", $lines), $privateKey),
	];

	return implode(',', array_map(static fn (string $p): string => $p . '="' . $params[$p] . '"', $order));
}

function sha256Digest(string $body): string {
	return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
}

function follow(string $actor, string $id): string {
	return (string)json_encode([
		'@context' => 'https://www.w3.org/ns/activitystreams',
		'id' => $id,
		'type' => 'Follow',
		'actor' => $actor,
		'object' => LOCAL_ACTOR,
	], JSON_UNESCAPED_SLASHES);
}

/** The vector as a fixture: what was sent, and the key to check it with. */
function vector(string $implementation, string $shapedAfter, string $actor, string $keyId, string $publicKey, string $body, array $headers, int $signedAt): array {
	return [
		'implementation' => $implementation,
		'shapedAfter' => $shapedAfter,
		'signedAt' => $signedAt,
		'method' => 'POST',
		'host' => HOST,
		'path' => INBOX,
		'actor' => $actor,
		'keyId' => $keyId,
		'publicKeyPem' => $publicKey,
		'headers' => $headers,
		'body' => $body,
	];
}

$now = time();
$httpDate = gmdate('D, d M Y H:i:s \G\M\T', $now);
$target = 'post ' . INBOX;
$vectors = [];

// Mastodon: Request#perform_cavage_signed_request covers every header it set
// but User-Agent, Accept-Encoding and Accept, in the order they were set, and
// HttpSignatureDraft#sign appends (request-target) last
[$private, $public] = keyPair();
$actor = 'https://mastodon.example/users/bob';
$body = follow($actor, 'https://mastodon.example/2f4e4d2a-6a25-4f1e-9d6e-0d1a9b3c5e7f');
$headers = [
	'User-Agent' => 'http.rb/5.2.0 (Mastodon/4.7.3; +https://mastodon.example/)',
	'Host' => HOST,
	'Date' => $httpDate,
	'Accept-Encoding' => 'gzip',
	'Content-Type' => 'application/activity+json',
	'Digest' => sha256Digest($body),
];
$headers['Signature'] = cavage(
	['host' => HOST, 'date' => $httpDate, 'content-type' => 'application/activity+json', 'digest' => $headers['Digest'], '(request-target)' => $target],
	$private, $actor . '#main-key', 'rsa-sha256'
);
$vectors['mastodon-cavage'] = vector('Mastodon 4.7 (draft-cavage)', 'mastodon/mastodon v4.7.3: app/lib/request.rb (set_common_headers!, perform_cavage_signed_request), app/lib/http_signature_draft.rb, app/workers/activitypub/delivery_worker.rb', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// Mastodon's RFC 9421 retry: linzer 0.8 over @method @target-uri
// content-digest, label sig1, `created` and `keyid` as its only parameters
$body = follow($actor, 'https://mastodon.example/8c1d3b52-0e4f-4a7b-b1d6-5f2e9a0c7d34');
$contentDigest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
$params = '("@method" "@target-uri" "content-digest");created=' . $now . ';keyid="' . $actor . '#main-key"';
$base = '"@method": POST' . "\n"
	. '"@target-uri": https://' . HOST . INBOX . "\n"
	. '"content-digest": ' . $contentDigest . "\n"
	. '"@signature-params": ' . $params;
$vectors['mastodon-rfc9421'] = vector('Mastodon 4.7 (RFC 9421)', 'mastodon/mastodon v4.7.3: app/lib/request.rb (perform_rfc9421_signed_request), app/models/keypair.rb (linzer_private_key); nomadium/linzer v0.8.0: lib/linzer/signer.rb', $actor, $actor . '#main-key', $public, $body, [
	'User-Agent' => 'http.rb/5.2.0 (Mastodon/4.7.3; +https://mastodon.example/)',
	'Host' => HOST,
	'Date' => $httpDate,
	'Accept-Encoding' => 'gzip',
	'Content-Type' => 'application/activity+json',
	'Content-Digest' => $contentDigest,
	'Signature-Input' => 'sig1=' . $params,
	'Signature' => 'sig1=:' . rsaSha256($base, $private) . ':',
], $now);

// GoToSocial: (request-target) host date digest, the algorithm hidden as
// hs2019, and the key at a URL of its own rather than a fragment
[$private, $public] = keyPair();
$actor = 'https://gts.example/users/erin';
$body = follow($actor, 'https://gts.example/users/erin/follow/01J0GTS0000000000000000002');
$headers = [
	'Host' => HOST,
	'Date' => $httpDate,
	'Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
	'Digest' => sha256Digest($body),
	'User-Agent' => 'gotosocial/0.22.2 (+https://gts.example)',
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'host' => HOST, 'date' => $httpDate, 'digest' => $headers['Digest']],
	$private, $actor . '/main-key', 'hs2019'
);
$vectors['gotosocial'] = vector('GoToSocial 0.22', 'superseriousbusiness/gotosocial v0.22.2: internal/transport/signing.go (postHeaders), vendor/code.superseriousbusiness.org/httpsig/signing.go (setSignatureHeader), docs/federation/http_signatures.md', $actor, $actor . '/main-key', $public, $body, $headers, $now);

// Misskey: ApRequestCreator#createSignedPost, (request-target) date host
// digest, rsa-sha256, the user's id (not its name) in the actor URL
[$private, $public] = keyPair();
$actor = 'https://misskey.example/users/9x2a1b3c4d';
$body = follow($actor, 'https://misskey.example/follows/9x2a1b3c4d/9x0z8y7w6v');
$headers = [
	'Date' => $httpDate,
	'Host' => HOST,
	'Content-Type' => 'application/activity+json',
	'Digest' => sha256Digest($body),
	'User-Agent' => 'Misskey/2026.10.0 (https://misskey.example/)',
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'date' => $httpDate, 'host' => HOST, 'digest' => $headers['Digest']],
	$private, $actor . '#main-key', 'rsa-sha256'
);
$vectors['misskey'] = vector('Misskey 2026.10', 'misskey-dev/misskey 2026.10.0: packages/backend/src/core/activitypub/ApRequestService.ts (createSignedPost, #signToRequest, #genSigningString)', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// Akkoma (and Pleroma, whose signer it keeps): every signed header sorted by
// name, content-length among them, rsa-sha256
[$private, $public] = keyPair();
$actor = 'https://akkoma.example/users/frank';
$body = follow($actor, 'https://akkoma.example/activities/7f3e2d1c-0b9a-4876-a5d4-c3b2a1908f7e');
$headers = [
	'Content-Type' => 'application/activity+json',
	'User-Agent' => 'Akkoma 3.20.1; https://akkoma.example <admin@akkoma.example>',
	'Date' => $httpDate,
	'Digest' => sha256Digest($body),
	'Content-Length' => (string)strlen($body),
	'Host' => HOST,
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'content-length' => (string)strlen($body), 'date' => $httpDate, 'digest' => $headers['Digest'], 'host' => HOST],
	$private, $actor . '#main-key', 'rsa-sha256'
);
$vectors['akkoma'] = vector('Akkoma 3.20 / Pleroma', 'AkkomaGang/akkoma v3.20.1: lib/pleroma/http/middleware/httpsignature.ex, lib/pleroma/user/signing_key.ex (local_key_id); AkkomaGang/http_signatures a4791e3: lib/http_signatures/http_signatures.ex (do_sign)', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// Lemmy: activitypub-federation-rust in Mastodon-compatible mode, which Lemmy
// switches on: content-type date digest host besides (request-target),
// sorted, hs2019
[$private, $public] = keyPair();
$actor = 'https://lemmy.example/u/gina';
$body = follow($actor, 'https://lemmy.example/activities/follow/3d0b5c1e-7a2f-4e98-b6c4-1f2e3d4c5b6a');
$headers = [
	'Content-Type' => 'application/activity+json',
	'Host' => HOST,
	'Date' => $httpDate,
	'Digest' => sha256Digest($body),
	'User-Agent' => 'Lemmy/0.19.20; +https://lemmy.example',
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'content-type' => 'application/activity+json', 'date' => $httpDate, 'digest' => $headers['Digest'], 'host' => HOST],
	$private, $actor . '#main-key', 'hs2019'
);
$vectors['lemmy'] = vector('Lemmy 0.19', 'LemmyNet/lemmy 0.19.20: src/lib.rs (http_signature_compat(true)); LemmyNet/activitypub-federation-rust 0.7.0-beta.12: src/http_signatures.rs (sign_request, test_sign), src/activity_sending.rs (generate_request_headers), src/protocol/public_key.rs (main_key_id)', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// Friendica: HTTPSignature::post, written out by hand: (request-target) date
// content-length digest host, rsa-sha256, the profile URL as the actor
[$private, $public] = keyPair();
$actor = 'https://friendica.example/profile/hank';
$body = follow($actor, 'https://friendica.example/objects/5b6c7d8e-1001-0000-0000-000000000001');
$headers = [
	'Date' => $httpDate,
	'Content-Length' => (string)strlen($body),
	'Digest' => sha256Digest($body),
	'Host' => HOST,
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'date' => $httpDate, 'content-length' => (string)strlen($body), 'digest' => $headers['Digest'], 'host' => HOST],
	$private, $actor . '#main-key', 'rsa-sha256'
);
$headers['Content-Type'] = 'application/activity+json';
$vectors['friendica'] = vector('Friendica 2026.05', 'friendica/friendica 2026.05-1: src/Util/HTTPSignature.php (post)', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// PeerTube: HTTP_SIGNATURE.HEADERS_TO_SIGN_WITH_PAYLOAD through
// @misskey-dev/node-http-message-signatures' draft signer, rsa-sha256
[$private, $public] = keyPair();
$actor = 'https://peertube.example/accounts/ivy';
$body = follow($actor, 'https://peertube.example/accounts/ivy/follows/12');
$headers = [
	'Digest' => sha256Digest($body),
	'Content-Type' => 'application/activity+json',
	'Accept' => 'application/activity+json, application/ld+json',
	'Date' => $httpDate,
	'Host' => HOST,
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'host' => HOST, 'date' => $httpDate, 'digest' => $headers['Digest']],
	$private, $actor . '#main-key', 'rsa-sha256'
);
$vectors['peertube'] = vector('PeerTube 8.3', 'Chocobozzz/PeerTube v8.3.1: server/core/initializers/constants.ts (HTTP_SIGNATURE), server/core/lib/activitypub/send/http.ts (buildSignedRequestOptions), server/core/helpers/requests.ts, server/core/helpers/peertube-crypto.ts (buildDigest); misskey-dev/node-http-message-signatures: src/draft/sign.ts', $actor, $actor . '#main-key', $public, $body, $headers, $now);

// Pixelfed: HttpSignature::signRequest covers its own three headers, the
// digest, and the content type and user agent the delivery service adds;
// headers before algorithm in the Signature header
[$private, $public] = keyPair();
$actor = 'https://pixelfed.example/users/jade';
$body = follow($actor, 'https://pixelfed.example/users/jade#follow/880011223344556677');
$contentType = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
$userAgent = '(Pixelfed/0.14.4; +https://pixelfed.example)';
$headers = [
	'Host' => HOST,
	'Date' => $httpDate,
	'Digest' => sha256Digest($body),
	'Content-Type' => $contentType,
	'User-Agent' => $userAgent,
];
$headers['Signature'] = cavage(
	['(request-target)' => $target, 'host' => HOST, 'date' => $httpDate, 'digest' => $headers['Digest'], 'content-type' => $contentType, 'user-agent' => $userAgent],
	$private, $actor . '#main-key', 'rsa-sha256', ['keyId', 'headers', 'algorithm', 'signature']
);
$vectors['pixelfed'] = vector('Pixelfed 0.14', 'pixelfed/pixelfed v0.14.4: app/Util/ActivityPub/HttpSignature.php (signRequest, _headersToSign), app/Services/ActivityPubDeliveryService.php (signedHeaders), app/Models/Profile.php (keyId)', $actor, $actor . '#main-key', $public, $body, $headers, $now);

foreach ($vectors as $name => $vector) {
	file_put_contents(
		__DIR__ . '/' . $name . '.json',
		json_encode($vector, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
	);
}

echo count($vectors) . " vectors written\n";
