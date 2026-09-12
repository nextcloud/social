# Mastodon compatibility

How close this app is to Mastodon, in the three senses that phrase can have:
whether Mastodon's clients work against it, whether other fediverse servers can
tell the difference, and whether an existing Mastodon instance could move onto
it. Written for whoever has to decide what to build next.

**Verified against:** app version 0.16.2, `master`, 2026-09-12 — after the
federation wave of #2110 and the compatibility wave of #2126, and re-checked
line by line for §9. Every claim was checked by reading the file it names.

**What #2126 changed**, since a reader who knew this document before will look
for it: the `source` leak is closed, suspension federates a `Delete`,
`display_name` / `avatar` / `bot` are written instead of dropped, the version
string says `4.2.0`, and `accounts/search`, `favourited_by` and `reblogged_by`
exist. §9 tracks what is left.

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

Social 0.16.0 is a capable, standards-correct ActivityPub server with a broad and
largely genuine Mastodon client API. It is **not** a drop-in replacement for
Mastodon, and three things stand between it and that goal. Two are small and
mechanical, and both are still open: the API is not served at the domain root,
and an OAuth app row holds exactly one token. The third is architectural: **an actor's identity is recomputed
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
handlers (`AppInfo\Application`).

Ivory, Tusky, Mona, Elk, Ice Cubes and Phanpy all build request URLs as
`https://<domain>/api/v1/...` from the domain the user types. The Mastodon client
protocol has **no mechanism for a non-root API base**, so none of them can reach
any endpoint. The app ships no rewrite, no webserver snippet and no setup
guidance. `README.md` now says so plainly rather than promising that
third-party clients can log in, which is what it used to say.

Nothing else in this section matters until this is fixed. The fix is either a
documented reverse-proxy rewrite from `/api` and `/oauth` to the app, or root
route registration from the app itself.

### 3.2 Blocker — one access token per registered app

`social_client` holds a single `token`, `auth_user_id`, `auth_account` and
`auth_scopes` per row, and the whole OAuth flow keys on `client_id`
(`OAuthController`). `authClient()` blanks the
token on every authorization, with a comment explaining that leaving it would let
the previous user's token act as the new one
(`ClientRequest::authClient()`), and `updateToken()` writes the row's one token.

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
| `/api/v1/custom_emojis` | returns `[]` unconditionally | `ApiController::customEmojis()` |
| `/api/saved_searches/list.json` | initialises the viewer, returns `[]` | `ApiController::savedSearches()` |

Everything else that exists as a route does real work, including subsystems the
2026-09-11 review listed as absent: lists, v2 filters (applied server-side),
conversations, markers, edit history, trends for tags and statuses and links,
suggestions, the directory, featured tags, endorsements, per-user domain blocks,
announcements with an admin UI, account notes, scheduled statuses with a cron,
and a real admin API.

### 3.4 Genuinely missing endpoints

- **Web Push (`/api/v1/push/*`)** — absent. `lib/Service/PushService.php` is
  unrelated; it pokes the `notify_push` app so the *web* client refreshes.
  Third-party mobile apps get no push from this server.
- **Streaming** — absent, and deliberately so. `InstanceService` returns an empty
  `urls` object so clients fall back to polling immediately rather than after a
  timeout.
- `/api/v1/preferences`, `familiar_followers`, `instance/peers`,
  `instance/activity`, and the v1 filter routes.

Three that were on this list are here now (#2126): `/api/v1/accounts/search`,
which is what a composer calls to complete a `@handle` and which no client
substitutes `/api/v2/search` for, and `favourited_by` / `reblogged_by`, which
make a tap on a favourite or boost count something other than a dead end. Both
reaction lists resolve the status through the visibility filter first: who
liked a post is as private as the post.

### 3.5 The version string

`Instance::COMPAT_VERSION = '4.2.0'`. It said `3.5.0` until #2126, which was
right when it was written and had stopped being: clients gate features on this
string, so they were hiding edit and history, calling the v1 filter routes that
404 instead of v2, and never asking for `/api/v2/instance` or
`/notifications/unread_count` — all of which are implemented.

The two 4.x features still missing are announced rather than left to fail.
`configuration.translation.enabled` is `false`, `urls` is an empty object, which
is how a client learns there is no streaming endpoint, and a client that tries
Web Push gets a 404 and falls back to polling, which is what it does against any
server with no VAPID key.

### 3.6 Entity shapes

Good overall. Two of the four issues this section used to list are fixed in
#2126:

- **`source` was emitted on every Account**, including other people's and to
  anonymous callers, leaking `source.follow_requests_count` — how many people
  are waiting on an account's approval. It is now built by the two credentials
  routes, which are the two that know they are answering the account itself, and
  the model no longer has it to leak.
- **`poll` was absent rather than `null`** on non-poll statuses, against the
  app's own rule that a client should never have to test for a missing key.

Two remain:

- `GET /accounts/{id}` can return `"avatar": ""` where the credentials routes
  patch it to a placeholder, so a client that declares the field a URL fails.
- `POST /api/v1/apps` omits `redirect_uri`, which Mastodon's Application entity
  always carries.

`showing_reblogs` is hardcoded `true` and `notifying` hardcoded `false`, and
neither `reblogs` nor `notify` is accepted by the follow route, so those two
client toggles report state the server never stored.

### 3.7 Profile editing

Fixed in #2126. `update_credentials` used to accept `header` and
`source[privacy]` and ignore `avatar`, `display_name` and `bot` while returning
200 — and a client's profile editor sends all of them in one PATCH, so somebody
changing their name, picture and bio together got a success and only the bio.

All three are written now. The name and the picture belong to the Nextcloud
account rather than to the actor, so they are written there and the actor cache
is refreshed; `bot` needed a column of its own and sets the actor's *type* with
it, because an account marked automated that went on publishing `Person` would
tell a client and a peer different things. A backend that owns the name or the
picture (LDAP, SAML, anything provisioned elsewhere) makes the request a **422**
rather than a silent success — which is what this section asked for, applied to
the fields that cannot be honoured rather than to the whole request.

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
   POSTs (`ActivityPubController::sharedInbox()` and `inbox()`). Every GET handler
   is a public page with no signature check, and the viewer is resolved from the
   Nextcloud session only, so a signed remote fetcher is never identified. Social
   cannot serve a followers-only object to an authorized remote reader, and
   cannot run secure mode. It fails safe rather than leaking — those objects 404
   — but a peer expecting secure mode gets nothing.
2. **No `Add`, `Remove` or `Move` outbound.** A pin is only visible by re-polling
   `featured`, and an account can never be migrated away by announcement.
3. **`mediaType` is emitted as an empty string** on every attachment
   (`MediaAttachment::exportAsActivityPub()`). Mastodon sniffs the file;
   stricter implementations may not.
4. **The WebFinger profile-page link points at the Nextcloud user profile**
   (`/index.php/u/alice`), not at a Social or Mastodon-shaped profile
   (`WebfingerHandler`).
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

(`ActorsRequestBuilder::parseActorsSelectSql()`, with inbox, outbox, followers,
following, featured and sharedInbox all derived from it on the lines below.) Writing a
Mastodon-shaped id into the column achieves nothing, because hydration discards
it. Status ids are minted the same way in `StreamService::assignItem()`, and the
serving routes **reconstruct** the id from the URL path rather than looking it up
(`ActivityPubController::displayPost()`), so a post stored under a foreign
id has no URL that serves it.

Keys are always freshly generated (`AccountService::createActor()`), and
there is no setter reachable from outside. `SocialMigrator` refuses to carry a
private key deliberately and explains why at length
(`SocialMigrator`). The cipher itself
(`PrivateKeyCipher`) would seal any PEM handed to it, so this
is unimplemented rather than impossible.

### 5.3 What exists today is the lossy Move path

`occ social:account:move` builds a proper `Move` with an `alsoKnownAs`
back-reference check, and the inbound side handles it. `docs/OCC-Commands.md:145`
states the consequence: a move carries the followers, not the archive. And it
requires the old instance to still be running to send the Move, which contradicts
keeping the same domain.

Follower import is the other half. Only *following* can be imported today
(`MigrationService`, `occ social:account:import-follows`).
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
   from the web (`AdminSettings`); everything else needs the
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

The moderation gap that was a correctness bug rather than a missing feature —
**suspending a local account purged its posts here and federated nothing**, so
every remote instance kept its copies and the takedown stopped at this
instance's own edge — is fixed in #2126: a suspension now sends the same
`Delete` the account's own deletion sends. Only for a local account, because a
`Delete` this instance signed for somebody else's actor is not one any peer
would act on.

---

## 7. Deliberate differences that are not gaps

Worth stating so they are not mistaken for work. Authentication, 2FA and sessions
come from Nextcloud. Accounts are Nextcloud users, so `accountEnable` is a
deliberate no-op and there is no per-actor login to disable. Notifications go
through the Nextcloud notification system — bell, mobile app, mail digest —
rather than Web Push, and they are emitted for mention, favourite,
reblog, follow, follow request and update from the single write path
(`NotificationService`). There is no materialised home feed
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
dismiss and clear are all correct now. Of the four moderation findings, all four
are fixed — takedowns federate a `Delete`, domain blocks purge stored content
through a background job, reports are forwarded outbound signed by the instance
actor, and a suspension of a local account federates its `Delete` (#2126).
Notifications, called the largest functional gap in that review, now reach the
Nextcloud bell.

And of this document's own list, five items are done: the `source` leak, the
suspension, the three dropped profile fields, the version string, and the three
missing endpoints — all in #2126, all of them hours or days of work. What is
left is what it was always going to be: the root path, per-user tokens, push,
authorized fetch, and the takeover.

---

## 9. Everything still between here and a full replacement

Re-verified against `master` on 2026-09-12. Every row was checked in the code;
nothing is carried over from a previous reading. "Effort" is a rough size, not
a promise.

### Tier 1 — the two blockers. Nothing else is visible to a user until these land

| # | Work | Effort | Why it is first |
|---|---|---|---|
| 1 | **Serve `/api` and `/oauth` at the domain root**, or document the reverse-proxy rewrite and ship a setup check for it | Days | Every route lives under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base. No stock client can reach *any* of the surface below |
| 2 | **Per-user OAuth tokens** — a token table keyed to (client, user) instead of one `token` column on `social_client` | Days | A second authorization against the same `client_id` revokes the first. Elk and Phanpy register one app per instance, so user B signing in signs user A out |

### Tier 2 — days of work each, and each one a thing a client shows

| # | Work | Effort | What it fixes |
|---|---|---|---|
| 3 | **Web Push** (`/api/v1/push/*`, a VAPID key, `configuration.vapid`) | Weeks | Third-party mobile apps get no notifications at all; every client polls |
| 4 | **`/api/v1/preferences`** | Hours | Clients read the posting defaults from it and fall back to guesses |
| 5 | **`/api/v1/custom_emojis`** — the instance's own emoji, and `Emoji` tags outbound | Days | Returns `[]` unconditionally. Remote emoji render; this instance can publish none |
| 6 | **`/api/v1/accounts/familiar_followers`** | Hours | The "followed by people you know" line on a profile is absent |
| 7 | **`/api/v1/instance/peers` and `/activity`** | Hours | Instance browsers and the about page show nothing |
| 8 | **The v1 filter routes** | Hours | A client that has not moved to v2 filters gets a 404 rather than an empty list |
| 9 | **`/api/saved_searches/list.json`** — either implement or stop routing it | Hours | Initialises a viewer and returns `[]`, which is a stub pretending to be a feature |
| 10 | **Streaming** (`wss://`, `/api/v1/streaming/*`) | Weeks | Deliberately absent and announced as absent, so clients poll. A real timeline needs a process that outlives a PHP request |

### Tier 3 — what a peer would still notice

| # | Work | Effort | What it fixes |
|---|---|---|---|
| 11 | **Authorized fetch inbound** — verify the HTTP signature on GET and resolve the remote reader | Weeks | Signature verification runs on inbox POSTs only. Social cannot serve a followers-only object to an authorized remote reader, and cannot run secure mode. It fails closed, so nothing leaks |
| 12 | **`Add` and `Remove` outbound** for pins | Days | A pin is only visible to a peer that re-polls `featured` |
| 13 | **`mediaType` on attachments** | Hours | Emitted as an empty string; Mastodon sniffs the file, stricter implementations may not |
| 14 | **The WebFinger profile-page link** | Hours | Points at the Nextcloud user profile rather than a Social one |
| 15 | **Emoji reactions** in either direction | Days | Not handled; `Announcement.reactions` is always `[]` |

### Tier 4 — the admin and moderation surface

| # | Work | Effort | What it fixes |
|---|---|---|---|
| 16 | **Registration management** — sign-up, an approval queue, invites, email confirmation | Weeks | Accounts are Nextcloud users, so provisioning lives in the server; an approval queue and invite links have no equivalent anywhere |
| 17 | **Warnings and strikes**, and "email this user" | Weeks | The ladder jumps from silence straight to suspend, with nothing in between and no record |
| 18 | **An account browser in the admin UI**, and a button for post takedown | Days | Only *reported* accounts are actionable from the web; everything else needs the admin API. `Moderation#statusRemove` has a route and a controller and no button |
| 19 | **Graded domain blocks** — a silence and a limit tier, and `reject_media` | Days | It is block-outright or nothing |
| 20 | **IP blocks, email-domain blocks, canonical email blocks** | Days | Absent |
| 21 | **A moderator role distinct from Nextcloud admin** | Days | Every admin route asks `IGroupManager::isAdmin()`, so moderating means full server administration |
| 22 | **Admin metrics** — trends, measures, dimensions, retention | Weeks | Absent |
| 23 | **`tootctl` equivalents** — `accounts cull`/`prune`, `preview_cards remove`, media-only sweeps | Days | The occ surface covers most of the rest |

### Tier 5 — the takeover, which is a different project

| # | Work | Effort | Why it is last |
|---|---|---|---|
| 24 | **Stored actor identity** — stop overwriting `id` and the collection URLs on read; mint only when the column is empty | Weeks | `ActorsRequestBuilder::parseActorsSelectSql()` recomputes identity from configuration on every read, so a Mastodon-shaped id cannot survive a round trip. Everything below depends on this |
| 25 | **Serve the Mastodon URL space** — `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`, `/followers`, `/following` — and resolve the requested URI rather than rebuilding it | Weeks | Peers hold the old URIs as primary keys; after a domain swap every one of them 404s |
| 26 | **Key-pair import**, root-only and loudly warned | Days | Keys are always generated; the old `keyId` becomes unresolvable and every signature fails on the far side |
| 27 | **A handle and id rename path** | Weeks | The `*_prim` md5 columns mean an id change is a fan-out rewrite across roughly a dozen tables |
| 28 | **Status, media and follower-graph importers**, writing rows and federating nothing | Months | Nothing of this exists. The follower half is only possible once identity is continuous |
| 29 | **Counter and threading reconciliation**, and a **cutover verification command** that fetches our own actor over HTTPS as a peer would | Weeks | Without it, nobody can tell whether a cutover worked until the network says so |
| 30 | **The operational runbook** — freeze, drain, dump, import, flip, keep the old inbox reachable | Days | |

### What is already done

Nothing in the list above covers these, and they were the previous list's items
3 to 7: the version string (`4.2.0`), `accounts/search`, `favourited_by`,
`reblogged_by`, the `source` leak, the federated `Delete` on suspension, and
writing `display_name`, `avatar` and `bot` rather than dropping them — all in
**#2126**. The `Application` entity's missing `redirect_uri` and `vapid_key`,
and a local bot account being served as a `Person`, are fixed in the change that
carries this list.

**Tiers 1 and 2 are what "usable as a Mastodon server" means.** Tier 5 is what
"replaces an existing Mastodon instance, on its own domain, without the network
noticing" means, and it should only be started if that is an actual product goal
rather than an aspiration.

---

## 10. Documentation drift

`tests/DocumentationTest.php` checks that the route set matches the tables, not
that the prose around them is true, so four claims had drifted. Three were
corrected when this file was added (#2112, #2123): `docs/API.md` denying the
existence of an account-note route two lines above the row documenting it,
calling two instance counters permanently zero when they are counted and cached,
and calling local poll votes and poll creation unsupported when both work.

The fourth is the opposite kind and is **still true**: `README.md` says
third-party clients can log in. They cannot, until §3.1 is fixed — and that is a
promise the code does not keep rather than a stale limitation, which is the
worse of the two. The README now says what stands in the way, but the sentence
is only honest because it says so.
