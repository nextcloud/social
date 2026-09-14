# Mastodon compatibility

How close this app is to Mastodon, in the three senses that phrase can have:
whether Mastodon's clients work against it, whether other fediverse servers can
tell the difference, and whether an existing Mastodon instance could move onto
it. Written for whoever has to decide what to build next.

**Verified against:** app version 0.19.12, `master`, 2026-09-13 — every status
re-checked against the code, and the federation claims re-checked against a
running instance rather than against the unit tests, which is how §4's
attachment finding turned up. What was written against 0.17.3 "plus PR #2136"
is now simply master: that wave, and #2134, #2135, #2126 and #2110 before it,
are merged.

**What changed in this revision**, for a reader who knew the document before:
`docs/Mastodon-Roadmap.md` has been folded into §9 rather than kept beside it;
the whole of tier 2b is done; conversation mute, the three `instance`
sub-routes, the link timeline and the standalone card have moved out of
"missing"; `redirect_uri` is on the Application entity; and one claim has gone
the other way — attachments are **not** served as ActivityPub documents, which
§4 now records as the one thing a peer currently gets wrong.

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

Social 0.19.12 is a capable, standards-correct ActivityPub server with a broad
and largely genuine Mastodon client API. The last walk of Mastodon's 109
documented client routes against `appinfo/routes.php` — done at 0.17.3 — found
ten unanswered. Six of those have been implemented since, each re-checked here
against the route table and the handler behind it, and **three** are left:
`push/subscription` and `streaming`, the two known weeks-long items, both
announced as absent so a client stops asking rather than hanging, and
`emails/confirmations`, which belongs to a sign-up this app does not own.
Everything else exists, and §3.3 says which of them is a stub.

It is **not** a drop-in replacement for Mastodon, and two things stand between
it and that goal. One is small, mechanical and still open: the API is not
served at the domain root. (The other of that pair — an OAuth app row holding
exactly one token — is fixed: authorizations are their own table, so two people
can use the same client.) The second is architectural: **an actor's identity is
recomputed from configuration on every read rather than stored**, and every URI
the app mints lives under `/apps/social/`. That single decision is what makes
taking over an existing Mastodon domain impossible rather than merely
unimplemented.

The good news is that nothing here is impossible in principle. The storage layer
would accept Mastodon-shaped ids and imported keys today; there is simply no code
path that writes them.

---

## 2. What "drop-in" has to mean

The phrase hides three different tests, with three different difficulties. A
report that does not separate them will either sound alarming or sound smug.

| Test | Question | Verdict |
|---|---|---|
| **The client test** | Do existing Mastodon apps work against it, unmodified? | **No** — one blocker left, days of work |
| **The peer test** | Would other fediverse servers notice the difference? | **Almost no** — federation quality is genuinely good, with one live defect (§4) |
| **The takeover test** | Can an existing Mastodon instance move onto it, same domain, same users, without the network noticing? | **No** — and this is weeks to months of work |

Most of the value is in the first test. Most of the difficulty is in the third.

---

## 3. The client test

### 3.1 Blocker — the API is not at the domain root

Every route is registered under the app prefix (a `#[FrontpageRoute]` on the
controller method), served at
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

### 3.2 Fixed — one access token per registered app

`social_client` used to hold a single `token`, `auth_user_id`, `auth_account`
and `auth_scopes` per row, and the whole OAuth flow keyed on `client_id`.
`authClient()` blanked the token on every authorization — with a comment
explaining that leaving it would let the previous user's token act as the new
one — so a second authorization against the same `client_id` silently revoked
the first. Elk and Phanpy register one app per instance and serve several
users from it, so user B signing in logged user A out, and a single user adding
the same account twice did the same thing.

Mastodon's model is one application, many tokens, and that is now the model
here: `social_client_auth` holds one authorization per (app, account) — the
code, the token, the scopes granted and the account they were granted to. The
app registration stays where it was, and every read joins it, so the rest of
the app still sees one `SocialClient` carrying both halves.

Three things went with it. Revoking a token took the app row's only token, so
revoking on one device signed out everybody who had authorized that client; it
now takes one authorization. The expiry sweep deleted the whole `social_client`
row, so an idle token took the app's registration with it and the client had to
register again; it now deletes authorizations. And a code is spent in the same
statement that writes the token, so two requests arriving together cannot both
exchange it.

### 3.3 The endpoint surface is now broad and mostly real

I opened the controller method behind each route rather than trusting the route
table. **One true stub remains in the entire Mastodon surface:**

| Endpoint | State | Evidence |
|---|---|---|
| `/api/saved_searches/list.json` | initialises the viewer, returns `[]` | `ApiController::savedSearches()` |

It is also not Mastodon's — it is a Twitter route an early client brought with
it, which is why §9 files it as a decision rather than a task.

Everything else that exists as a route does real work, including subsystems the
2026-09-11 review listed as absent: lists, v2 filters (applied server-side),
conversations, markers, edit history, trends for tags and statuses and links,
suggestions, the directory, featured tags, endorsements, per-user domain blocks,
announcements with an admin UI, account notes, scheduled statuses with a cron,
and a real admin API. Since that was written, so do conversation mute, the
three standalone `instance` sub-routes, `/api/v1/timelines/link` and
`/api/v1/statuses/{id}/card`, and the four notification types §6 used to list.

### 3.4 Genuinely missing endpoints

- **Web Push (`/api/v1/push/*`)** — absent. `lib/Service/PushService.php` is
  unrelated; it pokes the `notify_push` app so the *web* client refreshes.
  Third-party mobile apps get no push from this server.
- **Streaming** — absent, and deliberately so. `InstanceService` returns an empty
  `urls` object so clients fall back to polling immediately rather than after a
  timeout.
- **`/api/v1/emails/confirmations`** — part of a sign-up this app does not own;
  see §9 item 16. `/api/v1/admin/canonical_email_blocks` is absent for the same
  reason, though it belongs to the admin API rather than to this count.

Everything else that was on this list is answered now. Conversation mute is
handled (`ActionService::muteConversation()`); `/api/v1/timelines/link`,
`/api/v1/statuses/{id}/card` and the three standalone `instance` sub-routes are
registered; `/api/v1/preferences`, `familiar_followers`, `instance/peers`,
`instance/activity` and the v1 filter routes arrived with #2134;
`/api/v1/custom_emojis` answers with the instance's own emoji rather than `[]`;
and `/api/v1/accounts/search`, `favourited_by` and `reblogged_by` came with
#2126 — the first is what a composer calls to complete a `@handle` and no
client substitutes `/api/v2/search` for it, and the other two make a tap on a
favourite or boost count something other than a dead end. Both reaction lists
resolve the status through the visibility filter first: who liked a post is as
private as the post.

### 3.5 The version string

`Instance::COMPAT_VERSION = '4.2.0'`. It said `3.5.0` until #2126, which was
right when it was written and had stopped being: clients gate features on this
string, so they were hiding edit and history, calling the v1 filter routes that
404 instead of v2, and never asking for `/api/v2/instance` or
`/notifications/unread_count` — all of which are implemented.

The 4.x features still missing are announced rather than left to fail.
`configuration.translation.enabled` is `false`; `urls` is an empty object, which
is how a client learns there is no streaming endpoint; and `vapid_key` is an
empty string, so a client decides against offering Web Push before it asks for
it — which is what it does against any server with no VAPID key.

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

`POST /api/v1/apps` was the third: it omitted `redirect_uri`, which Mastodon's
Application entity always carries. It now answers with the first registered URI
and an empty `vapid_key`, because there is no Web Push here to have a key for.

Two remain:

- An Account can still carry `"avatar": ""` — verified against a running
  instance, where one account out of a search page came back with an empty
  string — because `Person::exportAsLocal()` falls through to `getAvatar()`
  when the actor has no cached icon, and that is the stored value, which may be
  empty. Mastodon declares the field a URL, and a client that decodes it as one
  fails on the whole account.
- `showing_reblogs` is hardcoded `true` and `reblogs` is not accepted by the
  follow route, so that toggle reports state the server never stored.
  `notifying` is no longer among them: `POST /accounts/{id}/follow` takes
  `notify` and writes a per-account subscription.

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
the same host. Three more have gone since: `Add` and `Remove` federate a pin,
`Move` is built by `occ social:account:move` with an `alsoKnownAs`
back-reference check, and the WebFinger profile-page link points at a Social
profile rather than at `/index.php/u/alice`.

**What a peer would still notice — one thing, and it is live today:**

**Attachments are served in Mastodon's client shape rather than as ActivityPub
documents.** Fetch any status of this instance with
`Accept: application/activity+json` and `attachment` comes back as

```json
[{"id": "707", "type": "video", "url": "…/media/02f8b930….mp4",
  "preview_url": "…", "remote_url": null, "meta": {…}, "description": "…"}]
```

where the wire calls for `{"type": "Document", "mediaType": "video/mp4",
"url": …, "name": "…"}`. There is no `mediaType` at all, `type` is the client
entity's half-word, and `description` is not the property a peer reads alt text
from.

The pieces are all present and correctly written — `MediaAttachment::asDocument()`
emits exactly the right object, and `ApiController::statusNew()` sets
`ACore::FORMAT_ACTIVITYPUB` on the attachments of a post as it is created, so
the original `Create` that goes out over the wire is right. What is wrong is
everything *after* that: `StreamRequest::save()` deliberately stores
`$item->asLocal()`, hydration leaves the rebuilt objects in the local format,
and `Stream::jsonSerialize()` then hands those objects straight to the
serialiser under `attachment`. So a single status, the outbox, `featured`,
`replies`, an `Update` after an edit, and any re-fetch by a peer resolving a
boost all carry the client shape.

This is the same defect the Pixelfed compatibility review found in September and
was believed fixed. It was not caught because the test that pins it —
`WireCompatibilityTest::testWhatGoesBackToPixelfedStatesItsMediaType()` — calls
`asDocument()` directly rather than serialising a stored status the way the
controller does, so it passes against a wire format nothing produces. It is §9
item 39, it is hours of work, and it is the highest-value item on that list
after the tier-1 blocker.

Authorized fetch inbound is **no longer** among them: a signed GET is verified
and resolved to the account behind it (`AuthorizedFetchService`), so a
followers-only object is served to a remote reader who follows it, and secure
mode — refusing an unsigned ActivityPub GET outright — is available behind the
`secure_mode` app value. Custom `Emoji` tags are emitted and reactions to an
announcement are stored and served; emoji reactions to a *status* are a
Misskey and Pleroma extension that Mastodon itself does not handle, and are
not implemented here either.

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

The archive half has moved since this was written, in one direction. An account
can now take its data out and put it back: `SocialMigrator` — driven by
`occ user:export`, by Nextcloud's account migration, and by the Migration page's
two buttons — writes the profile, the follows and followers as CSV, the
bookmarks and favourites as URLs, the account's **own posts as an ActivityPub
`OrderedCollection`**, and **the files of those posts**, under
`media_attachments/` in the layout Mastodon's own archive uses, with each
attachment's `url` rewritten to point into the archive. What comes back on import
is the profile, the follows, the relations, the marks, the banner and the files
of the posts this server still has. The posts themselves are counted and not
written, and the key pair is deliberately never carried.

Follower import is still the other half, and it is still impossible without
identity continuity: the relationship's other half lives on the follower's
server pointing at the old id. With identity continuity it becomes easy,
because nothing has to be federated at all — it is a local row insert.

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
   The export side of this exists (`SocialMigrator` writes the outbox); nothing
   reads it back.
6. **Write the media importer.** Half of it exists: the export carries every
   file, and the import stores one back through the upload path
   (`DocumentService::storeLocalAttachment()`) and hangs it on the post it
   belongs to — but only where this server already has that post, because
   nothing mints the posts. It becomes whole with 5.
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

1. Web Push and streaming — every client polls, and both are announced as
   absent rather than left to time out.
2. Registration management: sign-up, approval queue, invites, email
   confirmation. Accounts are Nextcloud users, so provisioning lives in the
   server, and an approval queue and invite links have no equivalent anywhere.
   This one is not going to be built here; what changed is that
   `POST /api/v1/accounts` answers **403** with the server's registration
   address instead of a 404.
3. **Graded domain blocks, through a Mastodon admin client.** The app has a
   silence tier — `FediverseService::silenceAddress()`, reachable from the
   admin settings and `occ social:fediverse` — but the admin API does not
   expose it: `AdminApiService::assertSeverity()` refuses any severity but
   `suspend`, `AdminDomainBlock` reports every entry as `suspend`, and silenced
   domains are not in that list at all. An admin moving from Mastodon finds the
   feature present in the web UI and absent from their tooling.
4. Search is a substring match, not an index. `StreamRequest::searchContent()`
   is an unanchored `ILIKE` over `content`, which is honest at the instance
   sizes this app targets and will not survive a large one; see
   [Performance.md](Performance.md).
5. `tootctl` equivalents for `preview_cards remove` and media-only sweeps.
   `accounts cull` and `accounts prune` no longer belong on this list: the cache
   cron gives up on an unreachable remote actor after ten failed refreshes
   (`CacheActorService`, the cull) and evicts the cached remote accounts nobody
   here refers to after `cache_actor_days` (`CacheActorSweepService`, the
   prune), both of them continuous rather than a command an administrator has
   to remember. `occ social:media:usage` reports what the media costs but
   deletes nothing.
6. Canonical email blocks, which belong with registration.

**No longer on this list**, each verified against the code rather than assumed:
conversation mute; all four of the notification types that were missing —
`poll`, `status`, `moderation_warning` and `severed_relationships`; the three
standalone `instance` sub-routes; warnings and strikes; an account browser and a
post-takedown button; custom emoji; admin metrics; IP and email-domain blocks;
and a moderator role distinct from Nextcloud admin.

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

Since that paragraph was written, four more waves landed. **#2134** filled the
small client gaps — the v1 filter routes, `instance/peers` and `instance/activity`,
`preferences`, `familiar_followers`. **#2135** went after what a peer would
notice — `Add` and `Remove` federate a pin, the WebFinger profile link points at
a Social profile, and `mediaType`, which §4 has now reopened because the fix
never reached the object a peer is served. **#2136** is the admin and
moderation tier almost entire: instance silencing, a moderator role that is
Nextcloud's own settings delegation, an account browser and a takedown button,
warnings and strikes, IP and email-domain blocks, admin metrics, the instance's
own custom emoji, announcement reactions, and authorized fetch with secure mode.
And the whole of tier 2b followed it: conversation mute, the `poll`, `status`,
`moderation_warning` and `severed_relationships` notifications, the three
`instance` sub-routes, the link timeline and the standalone card.

Three fields this server has long accepted have also stopped being reachable
only from somebody else's client: the web composer sends `language` with every
post, schedules one with `scheduled_at` and lists what is waiting through
`/api/v1/scheduled_statuses`, and writes a `focus` onto an attachment. Nothing
changed on the API for any of them; what changed is who can set them.

Of the tier-1 pair, one has moved: per-user OAuth tokens are done. The root
path has not.

And of this document's own list, five items were done in #2126 — the `source`
leak, the suspension, the three dropped profile fields, the version string and
the three missing endpoints — with authorized fetch joining them in #2136 and
`redirect_uri` on the Application entity since. What is left of that list is
what it was always going to be: the root path, push, the attachment shape, and
the takeover.

---

## 9. What is still to do

This was a second file until this revision (`docs/Mastodon-Roadmap.md`), which meant
the same items were described twice and drifted apart — the table below said
both tier-1 blockers were open while §3.2, four screens up, said one of them
was fixed. It is one list now.

Thirty-nine items in six tiers, each with a rough size, what it actually fixes,
and whether it is done. Query and scalability work has its own list in
[Performance.md](Performance.md) and structural debt in
[Technical-Debt.md](Technical-Debt.md); nothing here repeats those.

### Tier 1 — the blocker. Nothing else is visible to a user until it lands

| # | Work | Effort | Why it is first | Status |
|---|---|---|---|---|
| 1 | **Serve `/api` and `/oauth` at the domain root**, or document the reverse-proxy rewrite and ship a setup check for it | Days | Every route is a `#[FrontpageRoute]` under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base. No stock client can reach *any* of the surface below | **open** |
| 2 | **Per-user OAuth tokens** — an authorization table keyed to (app, account) instead of one `token` column on `social_client` | Days | A second authorization against the same `client_id` revoked the first, so user B signing into Elk signed user A out | done (`social_client_auth`) |

### Tier 2 — days of work each, and each one a thing a client shows

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 3 | **Web Push** (`/api/v1/push/*`, a VAPID key, `configuration.vapid`) | Weeks | Third-party mobile apps get no notifications at all; every client polls. `vapid_key` is answered as `''`, which is how a client learns to stop asking | **open** |
| 4 | **`/api/v1/preferences`** | Hours | Clients read the posting defaults from it and fall back to guesses | done |
| 5 | **`/api/v1/custom_emojis`** — the instance's own emoji, and `Emoji` tags outbound | Days | Returned `[]` unconditionally: remote emoji rendered, this instance could publish none | done |
| 6 | **`/api/v1/accounts/familiar_followers`** | Hours | The "followed by people you know" line on a profile | done |
| 7 | **`/api/v1/instance/peers` and `/activity`** | Hours | Instance browsers and the about page showed nothing | done |
| 8 | **The v1 filter routes** | Hours | A client that has not moved to v2 filters got a 404 rather than an empty list | done |
| 9 | **`/api/saved_searches/list.json`** — implement or stop routing it | Hours | Initialises a viewer and returns `[]`. See "Two answers rather than a tick" below | **open, and a decision rather than a task** |
| 10 | **Streaming** (`wss://`, `/api/v1/streaming/*`) | Weeks | Deliberately absent and announced as absent (`urls` is an empty object), so clients poll immediately rather than after a timeout. A real timeline needs a process that outlives a PHP request | **open** |

### Tier 2b — what walking the route list turned up

These came out of reading Mastodon's documented client API against
`appinfo/routes.php` route by route, rather than from remembering what was
missing. All of them have since landed.

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 31 | **Conversation mute** — `/api/v1/statuses/{id}/mute` and `/unmute` | Days | `ActionService` refused both by name; a thread could not be muted | done |
| 32 | **The `poll` notification** | Days | A voter never learned that the poll closed | done |
| 33 | **The `status` notification and `notify` on follow** | Days | Mastodon's bell on a profile. `POST /accounts/{id}/follow` now takes `notify` and writes a per-account subscription | done |
| 34 | **`moderation_warning` as a client notification** | Hours | A warning reached a local account through Nextcloud's bell, which a Mastodon client cannot see | done |
| 35 | **`severed_relationships`** | Days | When a domain block cuts follows, the accounts that lost them are told | done |
| 36 | **The three `instance` sub-routes** — `/rules`, `/domain_blocks`, `/extended_description` | Hours | The rules were served only *inside* the instance entity; the standalone routes 404ed | done |
| 37 | **`/api/v1/timelines/link`** | Days | The posts behind a trending link, whose links were already at `/api/v1/trends/links` | done |
| 38 | **`/api/v1/statuses/{id}/card`** | Hours | A 405, because the path matched the POST-only action route | done |

### Tier 3 — what a peer would still notice

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 11 | **Authorized fetch inbound** — verify the HTTP signature on GET and resolve the remote reader | Weeks | Signature verification ran on inbox POSTs only, so a followers-only object could not be served to an authorized remote reader and secure mode was impossible. It failed closed, so nothing leaked | done (`AuthorizedFetchService`) |
| 12 | **`Add` and `Remove` outbound** for pins | Days | A pin was only visible to a peer that re-polled `featured` | done |
| 13 | **`mediaType` on attachments** | Hours | The stored row (`MediaAttachment::asLocal()`) now carries `media_type`, `import()` reads it back — with a guess from the extension for rows written before it existed — and the Document a post is served as states it | done |
| 14 | **The WebFinger profile-page link** | Hours | Pointed at the Nextcloud user profile rather than a Social one | done |
| 15 | **Emoji reactions** | Days | Announcement reactions are stored and served. Reactions to a *status* are a Misskey and Pleroma extension Mastodon does not handle either, and are deliberately not implemented | done (announcements) |
| 39 | **Serve attachments as ActivityPub `Document`s** | Hours | New, and verified on the wire rather than in a unit test: everything served on request — a single status, the outbox, `featured`, `replies`, and any re-fetch by a peer — carries Mastodon's *client* shape under `attachment` (`"type": "video"`, `preview_url`, `remote_url`, `meta`) instead of `{"type": "Document", "mediaType": "video/mp4", "name": …}`. `MediaAttachment::asDocument()` is correct and `ACore::FORMAT_ACTIVITYPUB` is set on the attachments of a freshly created post, so the original `Create` goes out right; but `StreamRequest::save()` stores `asLocal()` and hydration leaves the objects in the local format, so every later read of the same post is wrong. `WireCompatibilityTest` calls `asDocument()` directly and therefore passes | done — `Stream::jsonSerialize()` maps every attachment through `asDocument()` whatever format it was hydrated in, so a re-read post goes out the same as the original `Create`; `StreamTest::testAHydratedPostServesItsAttachmentsAsDocuments` pins it |

### Tier 4 — the admin and moderation surface

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 16 | **Registration management** — sign-up, an approval queue, invites, email confirmation | Weeks | Accounts are Nextcloud users, so provisioning lives in the server. See "Two answers rather than a tick" | answered, not built |
| 17 | **Warnings and strikes**, and "email this user" | Weeks | The ladder jumped from silence straight to suspend, with nothing in between and no record | done (`StrikeService`) |
| 18 | **An account browser in the admin UI**, and a button for post takedown | Days | Only *reported* accounts were actionable from the web | done |
| 19 | **Graded domain blocks** — a silence and a limit tier, and `reject_media` | Days | The app has a silence tier (`FediverseService::silenceAddress()`), but the *admin API* does not expose it: `AdminApiService::assertSeverity()` refuses any severity but `suspend`, `AdminDomainBlock` reports every entry as `suspend`, and silenced domains are not in that list at all. A Mastodon admin client still sees block-outright or nothing | **partly done** |
| 20 | **IP blocks, email-domain blocks, canonical email blocks** | Days | The first two are there (`/api/v1/admin/ip_blocks`, `/admin/email_domain_blocks`); canonical email blocks are not, and belong to a sign-up this app does not own | partly done |
| 21 | **A moderator role distinct from Nextcloud admin** | Days | Every admin route asked `IGroupManager::isAdmin()`, so moderating meant full server administration. It is now Nextcloud's own settings delegation rather than a second list of names | done |
| 22 | **Admin metrics** — trends, measures, dimensions, retention | Weeks | Absent | done |
| 23 | **`tootctl` equivalents** — `accounts cull`/`prune`, `preview_cards remove`, media-only sweeps | Days | `social:cache:refresh`, `social:stream:prune` and `social:domain:purge` cover neighbouring ground; the culls and the media-only sweep had no equivalent | **partly done** — both culls are now continuous rather than commands: `CacheActorService` stops refreshing a remote actor after ten consecutive failures (`accounts cull`), and `CacheActorSweepService` evicts the cached accounts nobody here follows, that follow nobody here and that wrote no stored post, with their avatars, after `cache_actor_days` (`accounts prune`). `occ social:media:usage` answers what the media is costing, split into local uploads and cached remote files; `preview_cards remove` and a media-only sweep are still open |

### Tier 5 — the takeover, which is a different project

Untouched, and item 24 gates the other six.

| # | Work | Effort | Why it is last |
|---|---|---|---|
| 24 | **Stored actor identity** — stop overwriting `id` and the collection URLs on read; mint only when the column is empty | Weeks | `ActorsRequestBuilder::parseActorsSelectSql()` recomputes identity from configuration on every read, so a Mastodon-shaped id cannot survive a round trip. Everything below depends on this |
| 25 | **Serve the Mastodon URL space** — `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`, `/followers`, `/following` — and resolve the requested URI rather than rebuilding it | Weeks | Peers hold the old URIs as primary keys; after a domain swap every one of them 404s |
| 26 | **Key-pair import**, root-only and loudly warned | Days | Keys are always generated; the old `keyId` becomes unresolvable and every signature fails on the far side |
| 27 | **A handle and id rename path** | Weeks | The `*_prim` md5 columns mean an id change is a fan-out rewrite across roughly a dozen tables |
| 28 | **Status and follower-graph importers**, writing rows and federating nothing | Months | The *export* half is whole — `SocialMigrator` writes the account's own posts as an ActivityPub `OrderedCollection`, **their pictures and videos** under `media_attachments/`, and its followers and following as CSV — and the import side restores the profile, follows, relations, bookmarks, the banner and the files of the posts this server already has. Nothing writes **statuses** back, so the media of a post that is not here stays in the archive; followers cannot be imported at all until identity is continuous |
| 29 | **Counter and threading reconciliation**, and a **cutover verification command** that fetches our own actor over HTTPS as a peer would | Weeks | Without it, nobody can tell whether a cutover worked until the network says so |
| 30 | **The operational runbook** — freeze, drain, dump, import, flip, keep the old inbox reachable | Days | |

### Two answers rather than a tick

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
an honest answer, and it gives one: `registrations: false` in the instance
entity, and `POST /api/v1/accounts` answering **403** in Mastodon's error shape
with the address of the server's own registration page. A 404 was the wrong
answer — a client reads it as "this server is broken".

### Deliberately not on the list

`/api/v1/emails/confirmations`, `/api/v1/notifications/requests`,
`/api/v2/notifications/policy`, `/api/v1/annual_reports` and
`/api/v1/terms_of_service`. The first belongs to a sign-up this app does not
own (see 16). The rest arrived in Mastodon 4.3, and this app announces `4.2.0`
in `Instance::COMPAT_VERSION` — a client that reads the version before it asks
will not ask, and raising that number is a decision to make once the 4.3
surface is there rather than before.

### Where to start

**One thing blocks everything else, and it is item 1.** Until the API answers
at the domain root, no stock client reaches *any* of the surface above it.
Everything else is polish on a server nobody can connect to. It is days of
work — a documented reverse-proxy rewrite and a setup check, or root route
registration from the app — and it has not been started.

**Item 39 next, ahead of anything larger.** It is hours of work, it is the only
thing on this list a *peer* currently gets wrong, and every post with a picture
or a video is affected by it. Then Web Push (3) and streaming (10), the two
weeks-long items left, in the order a user would notice them.

**Tier 5 should not be started** until somebody decides that "replace an
existing Mastodon instance on its own domain" is a product goal. While
`ActorsRequestBuilder` recomputes an actor's identity from configuration on
every read, no imported id survives a round trip and nothing downstream of it
is possible.

Tiers 1 and 2 are what "usable as a Mastodon server" means. Tier 5 is what
"replaces an existing Mastodon instance, on its own domain, without the network
noticing" means.

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

Two more were found on this pass, and they are worth naming because they are
different failure modes.

The first is duplication. This document and `docs/Mastodon-Roadmap.md`
described the same items in two places, and they disagreed: the roadmap had
per-user OAuth tokens done, §3.2 had them done, and §9's summary table still
called both tier-1 blockers open. Two documents that must agree will not, so
there is one now.

The second is worse, because a test was supposed to catch it. §4's attachment
finding — the wire carrying Mastodon's client entity where an ActivityPub
`Document` belongs — had been recorded as fixed, and
`WireCompatibilityTest::testWhatGoesBackToPixelfedStatesItsMediaType()` passes.
It passes because it calls `MediaAttachment::asDocument()` itself rather than
serialising a stored status the way a controller does, so it pins a method and
not a behaviour. **A federation claim is only worth what the assertion behind
it exercises.** The way this one was actually found was fetching a status from
a running instance with `Accept: application/activity+json` and reading what
came back, which is what the next pass over §4 should do again.
