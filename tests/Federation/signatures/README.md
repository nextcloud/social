<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Signed-request vectors

One signed inbox delivery per fediverse implementation, each run through the
real `SignatureService::checkRequest()` by `../SignatureVectorsTest.php`.

**These are shaped after each project's source, not captured.** `generate.php`
signs each request the way that project's code signs one, as read in its
source at the version named in the vector's `shapedAfter`: which headers it
covers and in what order, the algorithm label it writes (`rsa-sha256` or
`hs2019`), the shape of its `keyId`, how it writes `Digest` (or RFC 9530
`Content-Digest`), its `Date` format, and the order of the `Signature`
header's parameters. The keys are made fresh by every run and exist nowhere
else.

| Vector | Shape that matters |
|---|---|
| `mastodon-cavage` | `host date content-type digest (request-target)` — `(request-target)` last |
| `mastodon-rfc9421` | `sig1=("@method" "@target-uri" "content-digest");created;keyid` |
| `gotosocial` | `hs2019`, `keyId` at `…/users/name/main-key` rather than a fragment |
| `misskey` | `(request-target) date host digest`, the user's id in the actor URL |
| `akkoma` | every covered header sorted, `content-length` among them |
| `lemmy` | `hs2019`, `(request-target) content-type date digest host` |
| `friendica` | `(request-target) date content-length digest host` |
| `peertube` | `(request-target) host date digest` through Misskey's signer library |
| `pixelfed` | `content-type` and `user-agent` covered, `headers` before `algorithm` |

Each file holds what was sent (`method`, `path`, `headers`, `body` as the exact
bytes), who sent it (`actor`, `keyId`), the public key to check it with
(`publicKeyPem`) and when it was signed (`signedAt`, which the test sets the
clock to). Regenerate them all with:

    php tests/Federation/signatures/generate.php

Every run changes every file — new keys, new dates — and the test does not
care which run it reads.

## Captured vectors, later

A vector shaped after source proves this instance takes what the source says
is sent; only a capture proves what is actually sent. The interop jobs already
stand up Mastodon, GoToSocial, Pixelfed, PeerTube, Loops, Misskey and Akkoma
beside this app, so they can capture without any change to the app: start a
second PHP built-in server on the runner with a router script that writes the
method, path, every header and the raw body of each request it receives to a
JSON file and answers 202, put it behind the job's Caddy under a name of its
own, make a peer account follow an actor served from there (or simply have
the peer resolve and message a handle there), and save the peer actor's
`publicKey` alongside. A file so made should be named `captured-<peer>.json`,
carry the image digest and the date it was captured in `capturedFrom`, and be
committed only together with its public key. `SignatureVectorsTest` reads
every JSON file in this directory, captured or shaped, the same way; its
`signedAt` is the capture's `Date`.
