# What a full Mastodon replacement still needs

The complete list of work between this app and being a drop-in replacement for
Mastodon: thirty items in five tiers, each checked in the code rather than
remembered, each with a rough size and what it actually fixes.

**Verified against:** app version 0.17.3, `master` plus PR #2136, 2026-09-12.
Every status below was re-checked against the code — the route table, the
service that answers it, and the entity it returns — rather than carried over
from the last edit.

Why this app is not one yet, and in which of the three senses that can be
meant, is [Mastodon-Compatibility.md](Mastodon-Compatibility.md); this file is
the backlog that follows from it. Query and scalability work has its own list in
[Performance.md](Performance.md), and the structural debt in
[Technical-Debt.md](Technical-Debt.md) — nothing here repeats those.

Like them, nothing in `tests/DocumentationTest.php` checks the *claims* below,
so they go stale silently. A test does check that this file exists, still says
which version it was verified against, and is still linked from the README —
because the ways a document like this dies are deletion and orphaning, and
those a test can catch.

## Tier 1 — the two blockers. Nothing else is visible to a user until these land

| # | Work | Effort | Why it is first | Status |
|---|---|---|---|---|
| 1 | **Serve `/api` and `/oauth` at the domain root**, or document the reverse-proxy rewrite and ship a setup check for it | Days | Every route lives under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base. No stock client can reach *any* of the surface below | open |
| 2 | **Per-user OAuth tokens** — a token table keyed to (client, user) instead of one `token` column on `social_client` | Days | A second authorization against the same `client_id` revokes the first. Elk and Phanpy register one app per instance, so user B signing in signs user A out | **done — PR #2136** |

## Tier 2 — days of work each, and each one a thing a client shows

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 3 | **Web Push** (`/api/v1/push/*`, a VAPID key, `configuration.vapid`) | Weeks | Third-party mobile apps get no notifications at all; every client polls | open |
| 4 | **`/api/v1/preferences`** | Hours | Clients read the posting defaults from it and fall back to guesses | done, merged |
| 5 | **`/api/v1/custom_emojis`** — the instance's own emoji, and `Emoji` tags outbound | Days | Returns `[]` unconditionally. Remote emoji render; this instance can publish none | **done — PR #2136** |
| 6 | **`/api/v1/accounts/familiar_followers`** | Hours | The "followed by people you know" line on a profile is absent | done, merged |
| 7 | **`/api/v1/instance/peers` and `/activity`** | Hours | Instance browsers and the about page show nothing | done, merged |
| 8 | **The v1 filter routes** | Hours | A client that has not moved to v2 filters gets a 404 rather than an empty list | done, merged |
| 9 | **`/api/saved_searches/list.json`** — either implement or stop routing it | Hours | Initialises a viewer and returns `[]`, which is a stub pretending to be a feature | open — see below |
| 10 | **Streaming** (`wss://`, `/api/v1/streaming/*`) | Weeks | Deliberately absent and announced as absent, so clients poll. A real timeline needs a process that outlives a PHP request | open |

## Tier 3 — what a peer would still notice

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 11 | **Authorized fetch inbound** — verify the HTTP signature on GET and resolve the remote reader | Weeks | Signature verification runs on inbox POSTs only. Social cannot serve a followers-only object to an authorized remote reader, and cannot run secure mode. It fails closed, so nothing leaks | **done — PR #2136** |
| 12 | **`Add` and `Remove` outbound** for pins | Days | A pin is only visible to a peer that re-polls `featured` | done, merged |
| 13 | **`mediaType` on attachments** | Hours | Emitted as an empty string; Mastodon sniffs the file, stricter implementations may not | done, merged |
| 14 | **The WebFinger profile-page link** | Hours | Points at the Nextcloud user profile rather than a Social one | done, merged |
| 15 | **Emoji reactions** in either direction | Days | Not handled; `Announcement.reactions` is always `[]` | **done — PR #2136** (announcements) |

## Tier 4 — the admin and moderation surface

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 16 | **Registration management** — sign-up, an approval queue, invites, email confirmation | Weeks | Accounts are Nextcloud users, so provisioning lives in the server; an approval queue and invite links have no equivalent anywhere | answered, not built — PR #2136 |
| 17 | **Warnings and strikes**, and "email this user" | Weeks | The ladder jumps from silence straight to suspend, with nothing in between and no record | **done — PR #2136** |
| 18 | **An account browser in the admin UI**, and a button for post takedown | Days | Only *reported* accounts are actionable from the web; everything else needs the admin API. `Moderation#statusRemove` has a route and a controller and no button | **done — PR #2136** |
| 19 | **Graded domain blocks** — a silence and a limit tier, and `reject_media` | Days | It is block-outright or nothing | **done — PR #2136** (silence) |
| 20 | **IP blocks, email-domain blocks, canonical email blocks** | Days | Absent | **done — PR #2136** (two of three) |
| 21 | **A moderator role distinct from Nextcloud admin** | Days | Every admin route asks `IGroupManager::isAdmin()`, so moderating means full server administration | **done — PR #2136** |
| 22 | **Admin metrics** — trends, measures, dimensions, retention | Weeks | Absent | **done — PR #2136** |
| 23 | **`tootctl` equivalents** — `accounts cull`/`prune`, `preview_cards remove`, media-only sweeps | Days | The occ surface covers most of the rest | open |

## Tier 2b — what this re-verification turned up

Items 31 to 38 were not on the first list. They came out of walking Mastodon's
documented client API against `appinfo/routes.php` and the entities the
handlers return, route by route, rather than from remembering what was missing.
None of them is large; together they are most of what a client still finds
absent once the tiers above are cleared.

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 31 | **Conversation mute** — `/api/v1/statuses/{id}/mute` and `/unmute` | Days | `ActionService::action()` refuses both by name. A thread cannot be muted, and the refusal is at least honest: a silent no-op would have the client show a state nothing stored | **done — PR #2136** |
| 32 | **The `poll` notification** — tell the people who voted that a poll has closed | Days | `Stream::NOTIFICATION_TYPES` maps six activity types; a closing poll is not one of them, so a voter never learns the result arrived | **done — PR #2136** |
| 33 | **The `status` notification and `notify` on follow** | Days | Mastodon's bell on a profile. `POST /accounts/{id}/follow` ignores `notify`, and there is no per-account subscription for it to write | **done — PR #2136** |
| 34 | **`moderation_warning` as a client notification** | Hours | A warning reaches a local account through Nextcloud's notifications (`Notifier`), which a Mastodon client cannot see. The same event has a documented type in the client API | **done — PR #2136** |
| 35 | **`severed_relationships`** | Days | When a domain block cuts follows, Mastodon tells the accounts that lost them. `DomainPurge` deletes the rows and nobody is told | **done — PR #2136** |
| 36 | **The three `instance` sub-routes** — `/rules`, `/domain_blocks`, `/extended_description` | Hours | The rules already exist in the `rules` app value and are served **inside** the instance entity; the standalone routes a client reads them from are 404. `domain_blocks` would publish what `social:fediverse` holds, which is a deliberate disclosure decision rather than a lookup | **done — PR #2136** |
| 37 | **`/api/v1/timelines/link`** | Days | The posts behind a trending link. The links themselves are served at `/api/v1/trends/links`, so the data is there and the timeline that reads it is not | **done — PR #2136** |
| 38 | **`/api/v1/statuses/{id}/card`** | Hours | The card is already inlined in the status entity, which is what most clients read; the standalone route is a 405 because the path matches the POST-only action route | **done — PR #2136** |

Not on this list, and deliberately: `/api/v1/emails/confirmations`,
`/api/v1/notifications/requests`, `/api/v2/notifications/policy`,
`/api/v1/annual_reports` and `/api/v1/terms_of_service`. The first belongs to a
sign-up this app does not own (see 16). The rest arrived in Mastodon 4.3, and
this app announces `4.2.0` in `Instance::COMPAT_VERSION` — a client that reads
the version before it asks will not ask, and raising that number is a decision
to make once the 4.3 surface is there rather than before.

## Tier 5 — the takeover, which is a different project

| # | Work | Effort | Why it is last | Status |
|---|---|---|---|---|
| 24 | **Stored actor identity** — stop overwriting `id` and the collection URLs on read; mint only when the column is empty | Weeks | `ActorsRequestBuilder::parseActorsSelectSql()` recomputes identity from configuration on every read, so a Mastodon-shaped id cannot survive a round trip. Everything below depends on this |
| 25 | **Serve the Mastodon URL space** — `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`, `/followers`, `/following` — and resolve the requested URI rather than rebuilding it | Weeks | Peers hold the old URIs as primary keys; after a domain swap every one of them 404s |
| 26 | **Key-pair import**, root-only and loudly warned | Days | Keys are always generated; the old `keyId` becomes unresolvable and every signature fails on the far side |
| 27 | **A handle and id rename path** | Weeks | The `*_prim` md5 columns mean an id change is a fan-out rewrite across roughly a dozen tables |
| 28 | **Status, media and follower-graph importers**, writing rows and federating nothing | Months | Nothing of this exists. The follower half is only possible once identity is continuous |
| 29 | **Counter and threading reconciliation**, and a **cutover verification command** that fetches our own actor over HTTPS as a peer would | Weeks | Without it, nobody can tell whether a cutover worked until the network says so |
| 30 | **The operational runbook** — freeze, drain, dump, import, flip, keep the old inbox reachable | Days | |

## Two answers rather than a tick

**9 — `/api/saved_searches/list.json`.** Not Mastodon's. It is a Twitter route
that arrived with an early client and has no place in a Mastodon-compatible
surface, so "implement it" would be implementing somebody else's API. Removing
the route is the other half of the choice and is a decision about breaking
whatever still calls it — which is why it is still here rather than quietly
done either way.

**16 — registration management.** An account on this server is a Nextcloud
account: the server creates it, through whatever provisioning it is configured
with, and this app is handed one that already exists. A sign-up form, an
approval queue, invite links and email confirmation all belong to the server,
and building a second one inside an app that does not own the account is how
two systems come to disagree about who exists. What this app owes a client is
an honest answer, and it now gives one: `registrations: false` in the instance
entity, and `POST /api/v1/accounts` answering **403** in Mastodon's error shape
with the address of the server's own registration page. A 404 was the wrong
answer — a client reads it as "this server is broken" and shows a person
nothing they can act on.

## What is already done

The **whole of tier 4 except registration** landed together, along with items 5
and 11 and the announcement half of 15: instance silencing, a moderator role
that is Nextcloud's own settings delegation rather than a second list, an
account browser and a post-takedown button in the panel, warnings and strikes
with a history a lift does not empty, IP and email-domain blocks, admin
metrics, the instance's own custom emoji, and authorized fetch with secure mode
behind a switch. Tier 3 items 12 to 14 (PR #2135) and tier 2 items 4 and 6 to 8
(PR #2134) are on `master`.

Nothing in the list above covers these, and they were the previous list's items
3 to 7: the version string (`4.2.0`), `accounts/search`, `favourited_by`,
`reblogged_by`, the `source` leak, the federated `Delete` on suspension, and
writing `display_name`, `avatar` and `bot` rather than dropping them — all in
**#2126**. The `Application` entity's missing `redirect_uri` and `vapid_key`,
and a local bot account being served as a `Person`, are fixed in the change that
carries this list.

## Where to start

**One thing blocks everything else, and it is item 1.** Until the API answers
at the domain root, no stock client reaches *any* of the surface below it: the
Mastodon client protocol has no way to be told about a non-root API base.
Everything else is polish on a server nobody can connect to. It is days of
work — a documented reverse-proxy rewrite and a setup check, or root route
registration from the app — and it has not been started.

Item 2 was the other one and is now done: an app registration no longer holds
a single authorization, so two people can use the same client.

**After item 1, in the order a user would notice:** Web Push (3) and streaming
(10), which are the two weeks-long items left. Tiers 2b, 3 and 4 are done,
tier 4 apart from registration, which is the server's.

**Tier 5 has not been touched** and should not be until somebody decides that
"replace an existing Mastodon instance on its own domain" is a product goal.
Item 24 gates the other six: while `ActorsRequestBuilder` recomputes an actor's
identity from configuration on every read, no imported id survives a round trip
and nothing downstream of it is possible.

**Tiers 1 and 2 are what "usable as a Mastodon server" means.** Tier 5 is what
"replaces an existing Mastodon instance, on its own domain, without the network
noticing" means, and it should only be started if that is an actual product goal
rather than an aspiration.
