# Mastodon compatibility

How close this app is to Mastodon, in the three senses that phrase can have:
whether Mastodon's clients work against it, whether other fediverse servers can
tell the difference, and whether an existing Mastodon instance could move onto
it. Written for whoever has to decide what to build next.

**Verified against:** app version 0.15.1, `master`, 2026-09-12 — after the
federation wave of #2110 landed. Every claim was checked by reading the file it
names.

This document supersedes the parity reviews written against 0.11.51 and 0.11.63,
and the "what can be improved" assessment written against 0.11.49: every item in
those was checked, and what survives is here, in
[Performance.md](Performance.md) or in [Technical-Debt.md](Technical-Debt.md).
The rest is fixed.

Like those two, nothing in `tests/DocumentationTest.php` checks the *claims*
here, so they go stale silently. Re-check rather than trust after the next wave
of work.

---

## 1. The answer in one paragraph

Social 0.15.1 is a capable, standards-correct ActivityPub server with a broad and
largely genuine Mastodon client API. It is **not** a drop-in replacement for
Mastodon, and three things stand between it and that goal. Two are small and
mechanical: the API is not served at the domain root, and an OAuth app row holds
exactly one token. The third is architectural: **an actor's identity is recomputed
from configuration on every read rather than stored**, and every URI the app mints
lives under `/apps/social/`. That single decision is what makes taking over an
existing Mastodon domain impossible rather than merely unimplemented.

The good news is that nothing here is impossible in principle. The storage layer
would accept Mastodon-shaped ids and imported keys today; there is simply no code
path that writes them.

---

## 2. What "drop-in" has to mean

The phrase hides three different tests, with three different difficulties. A
report that does not separate them will either sound alarming or sound smug.

| Test | Question | Verdict |
|---|---|---|
| **The client test** | Do existing Mastodon apps work against it, unmodified? | **No** — two blockers, both fixable in days |
| **The peer test** | Would other fediverse servers notice the difference? | **Almost no** — federation quality is genuinely good |
| **The takeover test** | Can an existing Mastodon instance move onto it, same domain, same users, without the network noticing? | **No** — and this is weeks to months of work |

Most of the value is in the first test. Most of the difficulty is in the third.

---

## 3. The client test

### 3.1 Blocker — the API is not at the domain root

Every route is registered under the app prefix (`appinfo/routes.php`), served at
`https://host/index.php/apps/social/api/v1/...`. The only things the app
registers at the server root are the WebFinger, NodeInfo and host-meta well-known
handlers (`lib/AppInfo/Application.php:51`).

Ivory, Tusky, Mona, Elk, Ice Cubes and Phanpy all build request URLs as
`https://<domain>/api/v1/...` from the domain the user types. The Mastodon client
protocol has **no mechanism for a non-root API base**, so none of them can reach
any endpoint. The app ships no rewrite, no webserver snippet and no setup
guidance, and `README.md:41` nevertheless states that third-party clients can log
in.

Nothing else in this section matters until this is fixed. The fix is either a
documented reverse-proxy rewrite from `/api` and `/oauth` to the app, or root
route registration from the app itself.

### 3.2 Blocker — one access token per registered app

`social_client` holds a single `token`, `auth_user_id`, `auth_account` and
`auth_scopes` per row, and the whole OAuth flow keys on `client_id`
(`lib/Controller/OAuthController.php:232,278,356,432`). `authClient()` blanks the
token on every authorization, with a comment explaining that leaving it would let
the previous user's token act as the new one
(`lib/Db/ClientRequest.php:58-79`), and `updateToken()` writes the row's one token
(`:84-92`).

Mastodon's model is one application, many tokens. Here a second authorization
against the same `client_id` silently revokes the first. Elk and Phanpy register
one app per instance and serve multiple users from it, so user B signing in logs
user A out. A single user adding the same account twice does the same thing.

This is a visible malfunction rather than a missing feature, and it needs a
separate token table keyed to `(client, user)`.

### 3.3 The endpoint surface is now broad and mostly real

I opened the controller method behind each route rather than trusting the route
table. **Only two true stubs remain in the entire Mastodon surface:**

| Endpoint | State | Evidence |
|---|---|---|
| `/api/v1/custom_emojis` | returns `[]` unconditionally | `lib/Controller/ApiController.php:567-569` |
| `/api/saved_searches/list.json` | initialises the viewer, returns `[]` | `lib/Controller/ApiController.php:577-585` |

Everything else that exists as a route does real work, including subsystems the
2026-09-11 review listed as absent: lists, v2 filters (applied server-side),
conversations, markers, edit history, trends for tags and statuses and links,
suggestions, the directory, featured tags, endorsements, per-user domain blocks,
announcements with an admin UI, account notes, scheduled statuses with a cron,
and a real admin API.

### 3.4 Genuinely missing endpoints

- **`/api/v1/accounts/search`** — absent. Only Social's own
  `/api/v1/global/accounts/search` exists (`appinfo/routes.php:234`). Mention
  autocomplete in the composer fails in every client that uses it.
- **`/api/v1/statuses/{id}/favourited_by` and `/reblogged_by`** — absent. Tapping
  a favourite or boost count is a dead end.
- **Web Push (`/api/v1/push/*`)** — absent. `lib/Service/PushService.php` is
  unrelated; it pokes the `notify_push` app so the *web* client refreshes.
  Third-party mobile apps get no push from this server.
- **Streaming** — absent, and deliberately so. `InstanceService` returns an empty
  `urls` object so clients fall back to polling immediately rather than after a
  timeout.
- `/api/v1/preferences`, `familiar_followers`, `instance/peers`,
  `instance/activity`, and the v1 filter routes.

### 3.5 The version string now suppresses working features

`Instance::COMPAT_VERSION = '3.5.0'` (`lib/Model/Instance.php:94`). Clients gate
features on this string, so they will hide edit and history, call the v1 filter
routes that 404 instead of v2, and never call `/api/v2/instance` or
`/notifications/unread_count` — **all of which are implemented**. The only 4.x
surface genuinely missing is push and streaming. The comment justifying 3.5.0 is
now out of date. Raising this string is a one-line change that switches on
several finished features.

### 3.6 Entity shapes

Good overall, with four issues a strict client would notice:

- **`source` is emitted on every Account**, including other people's and to
  anonymous callers, leaking `source.follow_requests_count`
  (`lib/Model/ActivityPub/Actor/Person.php:1076-1086`). This is the known
  master-branch leak and it is still present.
- `GET /accounts/{id}` can return `"avatar": ""` where the credentials routes
  patch it to a placeholder, so a client that declares the field a URL fails.
- `poll` is absent rather than `null` on non-poll statuses, violating the app's
  own "every key always present" rule.
- `POST /api/v1/apps` omits `redirect_uri`, which Mastodon's Application entity
  always carries.

`showing_reblogs` is hardcoded `true` and `notifying` hardcoded `false`, and
neither `reblogs` nor `notify` is accepted by the follow route, so those two
client toggles report state the server never stored.

### 3.7 Profile editing silently does nothing

`update_credentials` now accepts `header` and `source[privacy]`, but ignores
`avatar`, `display_name` and `bot` while returning 200
(`lib/Controller/ApiController.php:271-337`). A client's profile editor sends all
of them in one PATCH and shows the name and avatar unchanged. A 422 would be
better than a silent success.

---

## 4. The peer test

This is where the app is strongest, and it deserves saying plainly. A remote
server talking to a Social instance on its own domain would find very little to
complain about.

**Working and correct:** signed delivery and verification including RFC 9421, a
dedicated instance actor with its own key pair for signed GETs
(`lib/Service/InstanceActorService.php`), paged outbox, followers, following and
featured collections, a real `replies` collection, Mastodon-shaped HTML content
whose `u-url mention` and `hashtag` anchors agree with the `tag` array
(`lib/Service/LinkifyService.php`), `contentMap` and language, correct visibility
addressing that fails closed on unknown, `updated` on edits, inbox forwarding per
ActivityPub 7.1.2 with the LD signature preserved, per-inbox delivery
deduplication, and a retry window of 16 attempts on Sidekiq's `tries⁴+15`
backoff — about 49 hours, deliberately matching Mastodon.

Two findings from the 0.11.63 review are fixed: outbound content is no longer
escaped plain text, and delivery no longer sends one copy per mentioned user on
the same host.

**What a peer would still notice:**

1. **No authorized fetch inbound.** Signature verification runs only on inbox
   POSTs (`lib/Controller/ActivityPubController.php:213,273`). Every GET handler
   is a public page with no signature check, and the viewer is resolved from the
   Nextcloud session only, so a signed remote fetcher is never identified. Social
   cannot serve a followers-only object to an authorized remote reader, and
   cannot run secure mode. It fails safe rather than leaking — those objects 404
   — but a peer expecting secure mode gets nothing.
2. **No `Add`, `Remove` or `Move` outbound.** A pin is only visible by re-polling
   `featured`, and an account can never be migrated away by announcement.
3. **`mediaType` is emitted as an empty string** on every attachment
   (`lib/Model/Client/MediaAttachment.php:230`). Mastodon sniffs the file;
   stricter implementations may not.
4. **The WebFinger profile-page link points at the Nextcloud user profile**
   (`/index.php/u/alice`), not at a Social or Mastodon-shaped profile
   (`lib/WellKnown/WebfingerHandler.php:165-167`).
5. Emoji reactions and custom `Emoji` tags are not handled in either direction.

---

## 5. The takeover test

This is the part that makes "drop-in replacement" a much larger question than
feature parity, and it is where the honest answer is no.

### 5.1 The URLs do not match, and cannot be made to

Every id is built from `ConfigService::getSocialUrl()`, which is the app's route
root.

| Object | Social | Mastodon |
|---|---|---|
| actor | `https://host/apps/social/@alice` | `https://host/users/alice` |
| status | `https://host/apps/social/@alice/17578…` | `https://host/users/alice/statuses/<id>` |
| inbox | `https://host/apps/social/@alice/inbox` | `https://host/users/alice/inbox` |
| shared inbox | `https://host/apps/social/inbox` | `https://host/inbox` |
| instance actor | `https://host/apps/social/actor` | `https://host/actor` |

After a domain swap, every remote server still holds the old Mastodon URIs as
primary keys. Actors 404, so peers mark the accounts gone and the follower
relationships die on their side. Status URIs 404, so boosts, favourites, replies
and quotes across the network dangle. Old inbox URIs 404, so queued deliveries
fail permanently. There is no redirect, no `Tombstone` and no `Move`, so nothing
migrates. And the signing key changes while the old `keyId` becomes unresolvable,
so signature verification fails on the far side.

### 5.2 The root cause is that identity is derived, not stored

`ActorsRequestBuilder::parseActorsSelectSql()` **overwrites** the actor's id on
every read:

```php
$actor->setId($root . '@' . $actor->getPreferredUsername());
```

(`lib/Db/ActorsRequestBuilder.php:110`, with inbox, outbox, followers, following,
featured and sharedInbox all derived from it at `:112-125`.) Writing a
Mastodon-shaped id into the column achieves nothing, because hydration discards
it. Status ids are minted the same way in `StreamService::assignItem()`, and the
serving routes **reconstruct** the id from the URL path rather than looking it up
(`lib/Controller/ActivityPubController.php:744`), so a post stored under a foreign
id has no URL that serves it.

Keys are always freshly generated (`lib/Service/AccountService.php:237`), and
there is no setter reachable from outside. `SocialMigrator` refuses to carry a
private key deliberately and explains why at length
(`lib/UserMigration/SocialMigrator.php:52-64`). The cipher itself
(`lib/Security/PrivateKeyCipher.php:38`) would seal any PEM handed to it, so this
is unimplemented rather than impossible.

### 5.3 What exists today is the lossy Move path

`occ social:account:move` builds a proper `Move` with an `alsoKnownAs`
back-reference check, and the inbound side handles it. `docs/OCC-Commands.md:145`
states the consequence: a move carries the followers, not the archive. And it
requires the old instance to still be running to send the Move, which contradicts
keeping the same domain.

Follower import is the other half. Only *following* can be imported today
(`lib/Service/MigrationService.php:164`, `occ social:account:import-follows`).
Importing *followers* is impossible without identity continuity, because the
relationship's other half lives on the follower's server pointing at the old id.
With identity continuity it becomes easy, because nothing has to be federated at
all — it is a local row insert.

### 5.4 What would have to be written

In order. The first three are the irreducible core; without them the fediverse
notices on the first signature check.

1. **Make actor identity stored rather than derived.** Stop overwriting `id` on
   read and on create; use the column when set, mint only when empty. Same for
   the derived collection URLs.
2. **Serve the Mastodon URL space and look up by stored id.** Add
   `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`,
   `/followers`, `/following`, and change the serving controllers to resolve the
   requested URI instead of rebuilding it. Document the root rewrites.
3. **Add a key-pair import**, root-only and loudly warned, validating that the
   public key matches the private one.
4. **Add a handle and id rename path.** This is the step most likely to be
   underestimated: the `*_prim` md5 columns mean an id change is a fan-out
   rewrite across roughly a dozen tables.
5. **Write the status importer** — original id, published time, `inReplyTo`,
   conversation, addressing, language — rejecting any id whose host is not ours.
6. **Write the media importer.** Nothing exists here at all.
7. **Write the follower-graph importer**, inserting rows directly rather than
   calling the follow service, which would re-send a `Follow` to everyone.
8. **Import the remaining per-actor state** with the same write-the-row,
   federate-nothing discipline `SocialMigrator::importRelations()` already models.
9. **Reconcile counters and threading** so like, boost and reply totals survive.
10. **Add a cutover verification command** that fetches our own actor and a
    sample of statuses over HTTPS as a remote server would, and checks that the
    served id matches the stored one, that `publicKey.id` and the PEM agree, and
    that a signature made with the imported key verifies.
11. **Write the operational runbook**: freeze, drain the delivery queue, dump,
    import, flip, keep the old inbox reachable while DNS settles.

---

## 6. Feature gaps a migrating admin would hit on day one

Genuinely absent, in rough order of how much they would be missed:

1. Web Push and streaming — every client polls.
2. Registration management entirely: sign-up, approval queue, invites, email
   confirmation. Accounts are Nextcloud users, so provisioning lives in the
   server; but an approval queue and invite links have no equivalent anywhere.
3. Warnings and strikes, and "email this user" — the two softest moderation
   tools, so the ladder jumps from silence straight to suspend.
4. An account browser in the admin UI. Only *reported* accounts are actionable
   from the web (`lib/Settings/AdminSettings.php:49`); everything else needs the
   admin API. Post takedown has a route and a controller but no button anywhere.
5. Custom emoji, and emoji import.
6. Admin metrics: trends, measures, dimensions, retention.
7. IP blocks, email-domain blocks, canonical email blocks.
8. Graded domain blocks — it is block-outright or nothing, with no silence or
   limit tier and no `reject_media`. A block does now remove what the instance
   already sent; what is missing is the tiers between block and nothing.
9. A moderator role distinct from Nextcloud admin.
10. Full-text search; see [Performance.md](Performance.md).
11. `tootctl` equivalents for `accounts cull/prune`, `preview_cards remove` and
    media-only sweeps.

One moderation gap is a correctness bug rather than a missing feature:
**suspending a local account purges its posts locally and federates nothing**
(`lib/Service/ModerationService.php`), so every remote instance keeps its copies.
`AccountService::deleteActor()` builds a proper `Delete`; suspension does not use
it.

---

## 7. Deliberate differences that are not gaps

Worth stating so they are not mistaken for work. Authentication, 2FA and sessions
come from Nextcloud. Accounts are Nextcloud users, so `accountEnable` is a
deliberate no-op and there is no per-actor login to disable. Notifications go
through the Nextcloud notification system — bell, mobile app, mail digest —
rather than Web Push, and they are emitted for mention, favourite,
reblog, follow, follow request and update from the single write path
(`lib/Service/NotificationService.php:64-71`). There is no materialised home feed
to rebuild, because timelines are queried live. Rules and retention are app
values and occ commands. Moderation lives in Nextcloud admin settings.

And in the other direction, Mastodon has no equivalent for: ten dashboard
widgets, profile-page integration, posting a picture straight from Nextcloud
Files, Social data in `occ user:export`, and twenty-odd occ commands as an admin
surface.

---

## 8. What landed since the last review

Credit where it is due. Of the nine defects listed at 0.11.63, seven are fully
fixed and two partially: local poll voting, `scheduled_at`, the character limit,
poll expiry, the single-choice duplicate vote, `?remote=true`, and notification
dismiss and clear are all correct now. Of the four moderation findings, three are
fixed outright — takedowns federate a `Delete`, domain blocks purge stored
content through a background job, and reports are forwarded outbound signed by
the instance actor. Notifications, called the largest functional gap in that
review, now reach the Nextcloud bell.

---

## 9. Suggested order of work

| # | Work | Effort | Unblocks |
|---|---|---|---|
| 1 | Root path for `/api` and `/oauth`, documented or served | Days | Every client. Nothing else matters first |
| 2 | Per-user OAuth tokens | Days | Multi-user clients; re-authorization |
| 3 | Raise `COMPAT_VERSION` past 4.0 | Minutes | Edit, history, v2 filters, v2 instance, unread count |
| 4 | `accounts/search`, `favourited_by`, `reblogged_by` | Days | Mention autocomplete; engagement lists |
| 5 | Stop leaking `source` on other people's accounts | Hours | A live privacy bug |
| 6 | Federate a `Delete` when suspending a local account | Hours | A moderation correctness bug |
| 7 | Reject rather than ignore unsupported `update_credentials` fields | Hours | Silent no-ops |
| 8 | Web Push | Weeks | Mobile clients |
| 9 | Authorized fetch inbound | Weeks | Secure-mode peers |
| 10 | Stored identity + Mastodon URL space + key import | Months | The takeover test |

Items 1 through 7 are days of work in total and would make Social usable by
stock Mastodon clients with no visible malfunctions. That is the version of
"drop-in" worth aiming at first. Item 10 is a different project, and should only
be started if in-place instance migration is an actual product goal rather than
an aspiration.

---

## 10. Documentation drift

`tests/DocumentationTest.php` checks that the route set matches the tables, not
that the prose around them is true, so four claims had drifted. All four were
corrected in the change that added this file, and they are listed here because
the *kind* of drift matters: every one of them was a sentence describing a
limitation that had since been lifted, left behind by the change that lifted it.

- `docs/API.md` said there was no `POST /api/v1/accounts/{id}/note` and that
  `Relationship.note` was always empty, two lines above the table row
  documenting that route.
- It said `stats.status_count` and `stats.domain_count` were always zero. Both
  are counted and cached.
- The Polls section said voting on a local poll was a 422 and that creating
  polls was unsupported. Both were wrong.
- `README.md` said third-party clients can log in. They cannot, until §3.1 is
  fixed — that one was a promise rather than a stale limitation, which is worse.
