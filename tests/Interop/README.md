<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Interop tests (this app talking to a real Mastodon)

The unit suite proves this app emits the document it meant to. The integration
suite proves the database and the migrations hold what it thinks they hold.
Neither can prove the thing that actually matters on a federated network: that
**Mastodon accepts what we send**. That gap is where a silent drop lives — a
delivery that returns 202, is queued, is processed, and produces nothing on the
other side, with no error anywhere on ours.

The Pixelfed work found three of those by running our payloads through
Pixelfed's own validators. Nothing equivalent had ever been done for Mastodon,
which is what almost everybody on the other end is running.

## What it does

Nothing is stubbed. For each test the suite:

1. asks Mastodon to **resolve our account** (`/api/v2/search?resolve=true`),
   which already proves the actor document we serve is one Mastodon will fetch
   and accept;
2. makes Mastodon **follow** it, and waits for the `Follow` to arrive here and
   be accepted — a post reaches an instance because somebody there follows its
   author, so this is both the setup and an assertion;
3. writes a post through `PostService`, the way the API writes one;
4. **drains the delivery queue synchronously** — the real queue, signed by the
   real signer, because skipping it would not be testing the delivery path at
   all and waiting for cron would be a test that waits five minutes;
5. reads Mastodon back through **its own client API**, not its database: a row
   its serialiser refuses to render has not arrived either, and the API is the
   same answer a Mastodon user would get.

Covered so far: `Create` (a public post, and a post with a content warning —
`summary` is the field most likely to be quietly dropped), `Update` (an edit
has to carry `updated`, or the other side takes the edit in and goes on showing
the words that were replaced), `Delete`, and `Announce`.

## What it cannot prove

It runs against **one** Mastodon version, over **plain HTTP**, on **one host**.
So it says nothing about behaviour behind TLS, about instances running
`AUTHORIZED_FETCH`, or about Mastodon versions other than the one the workflow
pins. Each of those is a separate decision and none of them is free: a public
name and a certificate in CI is the part of this that is a decision rather than
a task.

## Running it

Every test **skips with a reason** when `MASTODON_BASE_URL` and
`MASTODON_TOKEN` are unset, so running the suite without a Mastodon is a pass
that says so rather than a failure.

```
MASTODON_BASE_URL=http://localhost:3000 \
MASTODON_TOKEN=... \
composer run test:interop
```

It needs the same real Nextcloud the integration suite does — the app inside a
server checkout, installed, with `cloud_url` and `social_url` set — and that
Nextcloud has to be **reachable from the Mastodon**, because Mastodon fetches
our actor and our posts over HTTP. `allow_local_remote_servers` on our side and
`ALLOWED_PRIVATE_ADDRESSES` on Mastodon's are what make two instances on one
host able to see each other.

## In CI

`.github/workflows/interop-mastodon.yml` stands the whole thing up: Postgres,
Redis, Mastodon's web and Sidekiq containers, a Nextcloud, an account and a
token.

It is deliberately **not** on `pull_request`. It depends on a third-party image
whose startup this repository does not control, so a bad day for that image
would block every pull request on a failure that says nothing about the change
under review. It runs weekly and on demand from the Actions tab; make it
required once it has been green for a while.
