# Nextcloud Social — Architecture Overview

## Introduction

Nextcloud Social is a federated social networking app built on the W3C ActivityPub standard. It integrates into Nextcloud as an app, providing each user with an ActivityPub identity (Person actor) that can interact with Mastodon, Friendica, and other Fediverse platforms.

**App ID:** `social`  
**Namespace:** `OCA\Social`  
**License:** AGPL-3.0-or-later  
**App version:** 0.17.1  
**Supported Nextcloud versions:** 35 – 36  
**Supported PHP versions:** 8.3 – 8.5  

All of the above come from `appinfo/info.xml`.

---

## Directory Structure

```
social/
├── appinfo/
│   ├── info.xml                # App metadata, dependencies, cron jobs, occ commands
│   └── routes.php              # All HTTP routes
├── lib/
│   ├── AP.php                  # ActivityPub type registry (factory + interface lookup)
│   ├── AppInfo/
│   │   └── Application.php     # Bootstrap, integration registration
│   ├── Command/                # occ CLI commands (+ ExtendedBase, a shared base that registers no command of its own)
│   ├── Controller/             # HTTP entry points (ActivityPub, Mastodon-ish API, local API, OAuth, OStatus, navigation, queue, config, moderation, public pages)
│   ├── Cron/                   # Background jobs (Cache, Queue, ScheduledPosts; DomainPurge and ActorCleanup are queued with an argument)
│   ├── Dashboard/              # Nextcloud Dashboard widgets
│   ├── Db/                     # Query-builder based repositories (`*Request` + `*RequestBuilder` pairs), on the public `IQueryBuilder`
│   ├── Exceptions/             # Custom exceptions
│   ├── Interfaces/             # Per-ActivityPub-type handlers (Activity/, Actor/, Object/, Internal/)
│   ├── Listeners/              # Event listeners (ProfileSectionListener, UserAccountListener, UserDeletedListener)
│   ├── Migration/              # Database schema migrations + repair steps
│   ├── Model/                  # ActivityPub model objects + support models
│   ├── Notification/           # Nextcloud notification integration (Notifier)
│   ├── Providers/              # Contacts menu integration
│   ├── Search/                 # Unified search integration
│   ├── Service/                # Business logic services
│   ├── Security/               # Key cipher, secret hasher, HTML sanitizer, outbound-address guard
│   ├── Settings/               # Admin settings (moderation panel: reports + Fediverse access list)
│   ├── Tools/                  # Vendored helper layer (query builder helpers, traits, exceptions) — see docs/Technical-Debt.md
│   ├── UserMigration/          # Account export/import (`SocialMigrator`, the Nextcloud user-migration framework)
│   ├── Traits/                 # TDetails
│   └── WellKnown/              # WebFinger / NodeInfo / host-meta handler and responses
├── src/                        # Vue 3 frontend
│   ├── main.js                 # Main SPA entry
│   ├── dashboard.js            # Dashboard widget entry
│   ├── oauth.js                # OAuth authorization page entry
│   ├── ostatus.js              # OStatus entry (built, but no server route loads it — see below)
│   ├── profile.js              # Profile-page custom element entry
│   ├── App.vue                 # Root component of the main SPA
│   ├── router.js               # Vue Router configuration
│   ├── store/                  # Pinia stores (index.js + timeline, account, settings, errors, notifications)
│   ├── views/                  # Route- and entry-level components
│   ├── components/             # UI components (`.vue`, plus MessageContent.js)
│   ├── services/               # eventBus, logger, notifications, clock, draft, shortcuts
│   ├── composables/            # useAccount, useCurrentUser, useServerData
│   ├── directives/             # focusOnCreate
│   ├── utils/                  # sanitizeHtml (+ its unit test), dominantColour, emojiCodePoint, instanceIdentity, relativeTime, viewTransition
│   └── types/                  # JSDoc type definitions (ActivityPub, Mastodon)
├── templates/                  # PHP templates: main.php (SPA), oauth2.php
├── l10n/                       # Translations
└── docs/                       # Documentation
```

There is no `lib/bootstrap.php`; Composer's autoloader is pulled in by `lib/AppInfo/Application.php`.

---

## Database Schema

Every query in the app is built by one class chain. `ExtendedQueryBuilder`
(`lib/Tools/Db/`) holds a query builder the server handed it through
`IDBConnection::getQueryBuilder()` and delegates the whole `IQueryBuilder`
interface to it, adding the `limitTo*` / `searchIn*` helpers the `lib/Db/`
repositories are written against. `SocialCoreQueryBuilder` adds the viewer,
and `SocialCross`, `SocialLimits`, `SocialFilters` and `SocialQueryBuilder`
add the joins, visibility filters and pagination on top of it.

It is composition rather than inheritance on purpose: the chain used to extend
`OC\DB\QueryBuilder\QueryBuilder` from the server's `lib/private/`, which put
all 68 repository classes on a constructor signature that carries no stability
promise and broke outright when Nextcloud 35 added a method to the public
interface. Nothing in `lib/` now names a class outside `OCP\`.

The tables are created by `lib/Migration/Version1000Date20221118000001.php`, all prefixed with `social_`:

| Table | Purpose |
|-------|---------|
| `social_action` | Like/Announce actions as ActivityPub objects (actor → object, with type) |
| `social_actor` | Local user actors (tied to NC accounts, holds the RSA key pair) |
| `social_cache_actor` | Cached remote federated actors (inbox/outbox URLs, public keys, counts) |
| `social_cache_doc` | Cached remote and local media attachments |
| `social_client` | OAuth 2.0 client registrations |
| `social_follow` | Follow relationships (actor → object, with accepted flag) |
| `social_hashtag` | Hashtag trend data: a JSON `trend` blob per hashtag, plus one sortable integer column per window (`trend_1h` … `trend_10d`) |
| `social_instance` | Known federated instances (version, metadata) |
| `social_req_queue` | Outbound ActivityPub delivery queue |
| `social_stream` | Core content table: posts, notes, activities. Nine JSON-in-TEXT columns (`to_array`, `cc`, `bcc`, `hashtags`, `tags`, `details`, `instances`, `attachments`, `cache`) beside the scalar ones; `source` holds the ActivityPub wire object verbatim |
| `social_stream_act` | Per-viewer stream flags (`liked`, `boosted`, `replied`, `bookmarked`, `values`) |
| `social_stream_dest` | Stream visibility targets (who sees what) |
| `social_stream_queue` | Inbound stream processing queue |
| `social_stream_tag` | Stream-to-hashtag mapping |
| `social_actor_relation` | Blocks and mutes: one row per (local actor, target actor, `block`/`mute`/`blocked_by`) |
| `social_report` | Moderation reports, local and federated `Flag` activities, with a `resolved` flag |
| `social_moderation` | The decision taken about an account: one row per silenced or suspended actor (`level`) |
| `social_strike` | Every decision ever taken about an account, including the warnings that took none: `action`, `text`, who took it and which report it came from |
| `social_access_block` | The blocks that are about an address rather than an account: an IP range this instance answers nothing from, and an email domain it gives no fediverse account to |
| `social_announce_react` | The emoji accounts have put on an announcement, unique on (announcement, account, emoji) |
| `social_emoji` | The custom emoji this instance publishes: `shortcode` (unique), the appdata file behind it, its media type, its picker category and whether a picker offers it |
| `social_stream_card` | The link-preview card of a status (url, title, description, image, provider), one row per stream |
| `social_followed_tag` | The hashtags an account follows: one row per (actor, lowercased tag), unique on the pair |
| `social_list` | Mastodon lists: one row per (owner, list), with its title, `replies_policy` and `exclusive` flag |
| `social_list_member` | Who is in a list: one row per (list, account), unique on the pair |
| `social_filter` | Keyword filters: one row per (account, filter) with its contexts, action and expiry |
| `social_filter_kw` | The keywords of a filter: one row per keyword, with its `whole_word` flag |
| `social_convo_state` | What one account has done with one conversation: how far it has read and dismissed the thread, both as a message nid, one row per (account, thread root) |
| `social_domain_block` | Instances one account has blocked for itself: one row per (account, domain), unique on the pair |
| `social_account_note` | The private note one account keeps about another: one row per pair, never federated |
| `social_mute_expiry` | When a mute runs out: one row per (muter, muted), and only for a mute that was given a duration |
| `social_stream_rev` | The versions a status has been through: one row per version including the original, oldest first |
| `social_featured_tag` | The hashtags an account pins to its profile: one row per (actor, lowercased tag), unique on the pair |
| `social_announcement` | The instance's announcements: one row per notice, with the text as typed and the window it is served in (both bounds nullable) |
| `social_announce_read` | Who has dismissed which announcement: one row per (account, announcement), unique on the pair |
| `social_scheduled` | Posts asked to be published later: one row per waiting post, with the client's request as JSON in `params` and the resolved visibility inside it |

`Version1000Date20260611000001` only drops the abandoned `social_3_*` tables from an earlier prototype. `Version1000Date20260907000001` adds the timeline indexes and the missing primary keys, `Version1000Date20260907000002` adds `social_actor_relation`, `Version1000Date20260907000003` adds the `bookmarked` flag to `social_stream_act`, `Version1000Date20260908000001` widens `social_client.app_client_secret` for its hashed value, `Version1000Date20260908000002` adds the `locked` flag to `social_actor`, `Version1000Date20260908000003` adds `social_report` (moderation reports), `Version1000Date20260908000004` adds the `fields` column to `social_actor` (the profile metadata fields), `Version1000Date20260908000005` adds `social_stream_card` (link previews), `Version1000Date20260909000001` adds `social_moderation` (the silence/suspend decisions, indexed on `level`), `Version1000Date20260910000001` adds the indexes the hot paths were querying as if they existed (`social_cache_doc.id_prim` and `parent_id_prim`, `social_stream_act` by (actor, flag), `social_stream_tag` by tag, `social_action` by (object, type), both queues by `status`/`id`, `social_client.token`, `social_stream.creation`, `social_cache_actor` by (local, details_update) and `social_follow` by (object, actor)) and drops the redundant five-column `ipoha` unique index on `social_stream`, `Version1000Date20260910000002` adds the sortable `trend_*` counter columns to `social_hashtag` zeroed (the JSON `trend` column stays and remains what the API hands back), `Version1000Date20260910000003` fills those columns in from the JSON, `Version1000Date20260911000001` adds the `sensitive` flag to `social_stream`, and `Version1000Date20260911000004` adds `social_followed_tag` (the hashtags an account follows, unique on (actor, tag) — which is also the index the home timeline reads). `Version1000Date20260911000005` adds `social_list` and `social_list_member` — Mastodon's lists and their membership, the membership table unique on (list, account), which is both what makes adding an account twice a no-op and the index the list timeline joins `social_stream.attributed_to_prim` on. `Version1000Date20260911000006` adds `social_filter` and `social_filter_kw` — the keyword filters an account mutes posts with, indexed by owner and by filter, which are the two reads there are. `Version1000Date20260911000007` adds `social_convo_state`, unique on (account, thread root) — the read and dismissed markers behind `/api/v1/conversations`. The conversations themselves get no table: a conversation is a thread of `social_stream` rows derived from `in_reply_to` at read time, and its id is the nid of the thread root. `Version1000Date20260911000008` adds `social_domain_block`, `social_account_note` and `social_mute_expiry` — the per-account instance blocks, the private notes and the expiry of a timed mute, each unique on the pair it is keyed by, which is both what makes writing one twice a no-op and the index its read path probes. An endorsement is not among them: it is a row in `social_actor_relation` with type `endorse`, which is what that table already holds. `Version1000Date20260911000009` adds `social_stream_rev` (the revisions of an edited status, indexed on (status, id), which is the only read there is) and `Version1000Date20260911000010` adds `social_featured_tag` (the hashtags an account pins to its profile, unique on (actor, tag)). `Version1000Date20260911000011` adds `social_announcement` and `social_announce_read` — the announcements and their dismissals, the dismissal table unique on (account, announcement), which is both what makes dismissing twice a no-op and the index the client read probes. The announcements table gets no index beyond its key: every read of it is its whole active set, and it holds a handful of rows. `Version1000Date20260911000014` adds `social_scheduled` — the posts a client asked to have published later — with two indexes, one per read there is: `(actor_id_prim, scheduled_at)` for one account's list and the daily cap, and `(scheduled_at)` for the cron's "what is due across every account", which the first index cannot answer because its leading column is the account. `Version1000Date20260911000020` adds `forwarded` to `social_report`: whether a report was passed on to the instance that hosts the reported account, which the admin API used to answer as a hardcoded `false`. `Version1000Date20260912000001` adds the two indexes `Version1000Date20260910000001` left out: `social_actor.user_id`, which resolves the logged-in user's actor on every authenticated request and had no index at all, and the four trend windows of `social_hashtag` other than `trend_1d` (`trend_1h`, `trend_12h`, `trend_3d`, `trend_10d`), each of which `getTrending()` filters and orders on. `Version1000Date20260912000002` adds `social_actor.bot` — whether a local account is automated, which is what Mastodon's `bot` reports and what decides whether the actor document says `Service` or `Person`; it was accepted from clients and dropped. `Version1000Date20260912000003` adds `social_strike` — the history of moderation decisions, indexed on the account, which is the only read there is. `Version1000Date20260912000006` adds `social_access_block`, unique on (type, value) — one table for two lists, because what differs between Mastodon's two is a severity column and a count, and neither is worth a second table on an instance that holds tens of these rows. `Version1000Date20260912000005` adds `social_announce_react`, unique on (announcement, account, emoji) — both what makes reacting twice with the same emoji a no-op and the index its two reads use. `Version1000Date20260912000004` adds `social_emoji`, unique on the shortcode — which is both what makes re-adding one a replacement rather than a second row nothing can tell from the first, and the index every read of it uses. `Version1000Date20260912000007` gives `social_stream` the five post fields that lived only inside the stored wire object: `tags` (the `tag` array as JSON), `language` (`VARCHAR(15)`, a BCP 47 tag, indexed as `social_s_lang`), `updated` (a nullable `DATETIME`) and `quote`/`quote_authorization` (both `TEXT`, ActivityPub ids). Neither id gets a `_prim` companion: nothing in the app looks a post up by what it quotes, and an md5 column plus its index on the largest table is a write cost on every insert for a query nobody makes. The existing rows are filled in afterwards by the `BackfillStreamPostFields` repair step, and `Stream::importFromDatabase()` falls back to the wire object for a row the backfill has not reached.

Two of those deserve a warning.

`Version1000Date20260907000001` adds an autoincrement `BIGINT` primary key to `social_stream_dest` and `social_stream_tag`, the two highest-cardinality tables. On MySQL/MariaDB that is a full table rebuild, so on a large instance `occ upgrade` will sit there for a while with the instance in maintenance mode. The change is correct and needed; the cost is not obvious from the migration.

`Version1000Date20260910000001` costs what it looks like — fifteen index statements, no table touched — on MySQL/MariaDB and PostgreSQL only. SQLite cannot change an index in place: Doctrine implements every one of them as a full table rebuild, so the same migration copies ten tables (`social_stream`, `social_cache_actor`, `social_cache_doc`, `social_follow`, `social_action`, `social_client`, both queues and both stream side tables) out, drops them, recreates them and copies the rows back. Plan the window for a rewrite of nearly the whole schema and for peak disk of about twice what those tables occupy. Nothing is at risk — SQLite DDL is transactional — but an operator sizing the outage from the MySQL figure will be wrong by an order of magnitude.

`Version1000Date20260910000003` exists because the step before it was wrong about the cron. `manageHashtags()` skips a hashtag whose freshly counted trend equals the JSON already stored, which on an instance that has just upgraded is the normal case — so the zeroed counter columns would never have been written, and `getTrending()` reads nothing else. The backfill pages through `social_hashtag` on its primary key, leaves rows already in agreement alone, and is a no-op on a fresh install.

There is no downgrade path, and none is possible: `Version1000Date20260611000001` drops tables outright.

There is no notifications table: in-app notifications are stored in `social_stream` as `SocialAppNotification` items. (A `TABLE_NOTIFICATION` constant naming a `social_notif` table that no migration ever created used to be declared here; it has been removed.)

---

## Key Services

The business logic lives in `lib/Service/`.

### Account & Identity

- **AccountService** — Creates local actors (generating an RSA key pair via `SignatureService`) and marks them deleted, refreshes the local actor cache (avatar, display name, follower/following/post counts), performs "blind key rotation", and reaps actors past their deletion retention
- **ActorService** — Saves/updates cached `Person` rows and resolves an actor's cached header image
- **CacheActorService** — Central actor cache/resolver. Looks up actors by ActivityPub id or `user@host`, fetches unknown remote actors over WebFinger + HTTP on a cache miss, and probes their followers/following/outbox counts
- **RelationshipService** — blocking and muting: stores the relation, severs follows in both directions on a block, and federates `Block`/`Undo{Block}` unless the `federate_blocks` app setting is `0`. Mutes are purely local and never federated.

### Content & Timelines

- **StreamService** — Core stream/timeline engine. Assigns ActivityPub ids, expands recipients (public/unlisted/followers/direct), resolves reply chains, detects stream types, deletes local items, and reads the timelines (home, local, global/federated, tag, account, liked, direct, notifications). Deleting a post takes what belongs to it with it: `StreamRequest::deleteRelatedTo()` removes the `social_stream_dest`, `social_stream_tag`, `social_stream_act` and `social_stream_card` rows, the `social_action` rows pointing at the post, and its cached attachment rows together with the files on disk. The cascade sits in `StreamRequest`, so every caller gets it rather than retention alone. `syncRemoteTimeline()` — a profile timeline asking the account's own server for its outbox — walks at most `SYNC_ITEM_LIMIT` (20) entries of the page it gets back, and puts every field of each through the same validation helpers the inbox path uses. It used to walk the whole page unbounded and store `url`, `content`, `summary` and the tags unchecked, from a public route, against a host the caller names
- **PostService** — Creates posts (text, attachments, reply-to, mentions, hashtags, the content warning and the `sensitive` flag) and edits existing local posts, delegating federation to `ActivityService`. A visibility a client sent is translated once, in `Post::setType()`: Mastodon calls a followers-only post `private` and this app calls it `followers`, and a visibility the app does not recognise becomes `direct` rather than being addressed to `as:Public`. The content warning is stored as plain text on both paths (`strip_tags()`), because it is read as plain text everywhere it is shown — as the object's `summary`, as `spoiler_text`, and interpolated rather than rendered by this app's own frontend; entity-encoding it federated `Bob&#039;s finale` and baked the entities into the next edit. The body takes the opposite path: it is rendered by `LinkifyService` after the recipients and hashtags are resolved, so the links in the published HTML and the `tag` array come out of one parse
- **ScheduledStatusService** — `POST /api/v1/statuses` with a `scheduled_at`, and the publishing of what it stores. Keeps the client's request rather than a rendered post, because publishing has to go through `PostService::createPost()` over a `Post` assembled exactly as `ApiController::statusNew()` assembles one — a note built by one path and federated by another would drift on the quote approval, the language fallback and the source snapshot. `MIN_LEAD_TIME` (300 s) is Mastodon's five-minute minimum and is also what makes the cron interval workable; `MAX_PENDING` (300) and `MAX_PENDING_PER_DAY` (25) are Mastodon's caps, and are needed because a scheduled post is the only thing a client can store unpublished and unbounded where no moderator can find it. The visibility is resolved at scheduling time so a later change of `source[privacy]` cannot move a waiting post's audience, and everything `createPost()` would refuse — length, an unusable poll — is refused while there is still a client to tell. `publishDue()` claims a row by deleting it before publishing: two workers that read the same due row both try, the database lets one affect it, and only that one posts
- **PollService** — Federated polls: serves the Mastodon Poll entity of a stored `Question` and votes on remote polls (one vote note per choice to the poll's author; the viewer's choices are remembered in the per-viewer stream action, authoritative counts arrive as `Update{Question}`)
- **FollowService** — Follow/unfollow flows, follower/following collections, and relationship lookups
- **LikeService** — Creates and undoes Like activities
- **StreamPruneService** — Retention: deletes remote statuses older than `retention_days` (default 0 = disabled) that no local user interacted with, whose author nobody follows, that no local status replies to or boosts, and that are not DMs — together with their dest/action/tag rows and cached attachments. Runs bounded in the Cache cron and unbounded via `occ social:stream:prune`
- **BoostService** — Creates and undoes Announce (boost/reblog) activities
- **ActionService** — Dispatcher for the Mastodon-style status actions. favourite/unfavourite create and delete a Like, reblog/unreblog an Announce, and bookmark/unbookmark toggle the viewer's local `bookmarked` flag (never federated, served by `/api/v1/bookmarks`), and pin/unpin hand off to `PinService`. `translate` returns the status unchanged, and the unimplemented `mute` and `unmute` are refused with `InvalidActionException` instead of silently doing nothing
- **HashtagService** — Recomputes hashtag trends over 1h/12h/1d/3d/10d windows and searches hashtags
- **StreamActionService** — Writes the per-viewer flags in `social_stream_act`
- **PinService** — Pinned posts: the own posts an account keeps at the top of its profile. A pin is a row in `social_action` (type `Pin`), so there is no schema change, and it is never federated as an activity of its own — remote servers read pins from the actor's `featured` collection. Only a public or unlisted post can be pinned (`pin()` refuses the rest with `InvalidActionException`), and pins are read back through the visibility filter like any other status, so a post that stopped being readable stops being served: both readers of this collection — the `featured` route and `?pinned=true` on the account statuses route — are public pages, and reading these unfiltered served a pinned followers-only post in full to the anonymous internet
- **LinkPreviewService** — Builds the link-preview card of a post from what the linked page says about itself (OpenGraph, falling back to the HTML title/description), fetched once per post from the inbound queue through `CurlService`. Cards are never federated, so every instance reads the page itself
- **MarkerService** — How far through a timeline someone has read (Mastodon's markers), stored as a per-user config value rather than a table; the unread badge is the only thing the app itself needs one for
- **SearchService** — Backing searches for accounts, hashtags, URIs and status content; `searchStreamContent()` backs the client API search, the local API and the unified search provider. Each search takes an optional row limit, for a caller that pages; without one it returns whatever the query returns
- Every timeline query filters actors the viewer has blocked or muted (and actors who blocked the viewer) through one anti-join, `SocialLimitsQueryBuilder::filterHiddenActors()` — blocks apply everywhere, mutes to aggregated timelines and threads but not to a muted account's own profile or a directly opened post, and muted-with-notifications to the notification stream.

### Federation

- **ActivityService** — Wraps items in Create/Update/Delete activities, LD-signs them, resolves target inboxes, and drives the delivery queue. `request()` sends the single highest-priority entry synchronously and kicks off an async request for the rest
- **ImportService** — Parses incoming ActivityPub JSON into typed model objects (`AP::getItemFromData()`) and dispatches to the matching handler in `lib/Interfaces/`
- **SignatureService** — RSA-2048 key generation, HTTP Signature verification (checking `date` freshness, then the body against `Content-Length` if the sender sent one and against `Digest`/`Content-Digest`, before the signature itself) and RsaSignature2017 Linked Data signatures. The signing itself is delegated to `HttpSignatureService`. Fetching a signing key this instance does not already hold is bounded — `UNKNOWN_KEY_CONNECT_TIMEOUT` (5 s) for DNS, TCP and TLS within `UNKNOWN_KEY_TIMEOUT` (10 s) overall, what Mastodon allows a peer of its own — and a fetch that failed is remembered so a burst naming the same unknown key costs one fetch and not one each: 300 s (`KEY_FAILURE_TTL`) when the peer answered and the answer was not a usable actor, 30 s (`KEY_UNREACHABLE_TTL`) when it never answered, because that says the peer was having a bad minute rather than anything about the key
- **HttpSignatureService** — The one place an outbound HTTP signature is produced. A delivery is signed by its own author over `(request-target) content-length date host digest`; an ActivityPub GET is signed over `(request-target) host date` by `signFetch()`, as the instance's own `Application` actor. Never as a person: the owner of a signing key is dereferenced by every peer that checks it, so a borrowed account would appear in every peer's logs as this instance's reader and a block or suspension of it anywhere would stop every signed fetch from here — and an instance whose users have no Social accounts yet would have nobody to borrow. A key that cannot sign raises rather than sending an empty signature, and an instance that cannot produce one at all fetches unsigned, which is what the request was until now
- **InstanceActorService** — The instance's own actor and its key pair, served at `/actor`, discoverable as `acct:<host>@<host>`. The key pair lives in two app config values rather than in `oc_social_actor`: a row there is a local account — listed by the directory, resolved by webfinger, offered to the client API, counted in the statistics, handed a followers collection and an outbox — and the instance actor is none of those, so every one of those places would have needed a clause excluding it. The private half is sealed with the instance secret the way an actor's is, so a config dump alone is not enough to sign as this server. Generated on first use; when two requests race, both adopt whichever pair was written last, because that is the one the actor document publishes
- **LinkifyService** — The plain text somebody types, turned into the HTML every other implementation publishes: `<p>` paragraphs, `<br />`, and links for URLs, mentions (`u-url mention`) and hashtags (`mention hashtag`, `rel="tag"`). Content used to leave as `nl2br(htmlentities(…))`, and peers render `content` without looking for anything to linkify, so every link, mention and hashtag written here arrived everywhere as dead text. The text is escaped first and markup is only ever built around the escaped pieces — nothing is un-escaped and no markup is assembled by interpolating input. The entities are found once, and that same list is what `PostService` addresses the post from and what `StreamService` builds the `tag` array out of, so the markup can never link somebody the `tag` array does not name — which is the list a receiving instance checks a mention against before it notifies anybody
- **ForwardService** — Inbox forwarding (ActivityPub §7.1.2); see below
- **PinService** — Pinned posts, and the `Add`/`Remove` that tell the fediverse about one. A pin is not an activity of its own on the wire: what travels names the actor's `featured` collection as its `target`. Without that pair a pin was visible only to a peer that re-read the collection, which nothing prompts it to do — so a pin appeared elsewhere late or never, and an unpin never at all. The row is stored first and a failure to federate is logged rather than raised: the profile here is right either way, and a pin is not worth failing a request over
- **ReportForwardService** — Passes a local report on to the instance that hosts the reported account, as a `Flag`. Anonymised: the activity names this instance's `Application` actor and is signed with its key, so the receiving moderators see the server and not the person who filed it — which is the point, since that person is reporting an account on the instance being told. Delivered inline rather than through `social_req_queue`, because a queued delivery is signed by `HttpSignatureService::signDelivery()` from `oc_social_actor` by the queue row's author, and the instance actor is deliberately not a row there. One report is one POST with a 3-second timeout; a failure costs the forward and nothing else, and `social_report.forwarded` is set only when the remote inbox accepted it
- **DomainPurgeService** — Removes what a blocked instance already sent: its cached accounts, their posts, the follows in both directions, the notifications they caused and the deliveries still queued towards them. A block on its own only ever stopped the *next* request. Bounded (50 accounts per step, every underlying delete already batched, no transaction held open), idempotent (each step asks what of the domain is still stored rather than counting off an offset, so an interrupted purge resumes and a repeat is free) and terminating (a step that deletes nothing stops rather than spins). Reuses `ModerationService::purgeActor()`, so a domain purge detaches exactly what a suspension detaches. What it deletes is gone — unblocking the domain lets the instance reach us again but restores nothing. Matches the exact host, not subdomains, even though `isListed()` widens a block to cover them: refusing traffic from one instance too many is undone by editing the list, and deleting one is not
- **InboxLimiter** — Per-minute rate limits on the inbox routes, one spent before the signature is checked and one after; see [Security](#security)
- **RequestQueueService** — Manages `social_req_queue`: creates entries, hands out the priority entry, and re-offers standby entries once they are due. The `floor(tries^4 / 3)` second backoff and the `MAX_TRIES` (15) give-up are applied by the query (`CoreRequestBuilder::limitToQueueDue()`), and exhausted rows are deleted before the 200-row window is read — filtered in PHP afterwards, the rows of one dead instance permanently occupied that window and starved every other delivery. A delivered row is removed rather than kept as a success row. A row whose delivery fails in a way `ActivityService` does not handle itself — a corrupt signing key, the database going away — is logged and handed back to standby by the caller (`Cron\Queue` and `QueueController`), because it was marked `running` before the attempt: left that way it was never retried, never counted against `MAX_TRIES`, and took the rest of the 200-row batch with it
- **StreamQueueService** — Manages `social_stream_queue`, the inbound side. Two queue types are implemented. `Cache`: for each Note a received stream references (a reply parent, a boosted post), it fetches that Note, caches its author, stores it, and embeds it in the referencing stream's cache — anything that is not a Note, or whose id does not match the URL it was fetched from, is rejected. `LinkPreview`: reads the page a post links to, once, and stores the card. Any other type is dropped. This side has the same 200-row batch cap and the same in-query backoff, give-up (`MAX_TRIES`, 10 here) and delete-on-success as the outbound queue; it used to keep one permanent row per activity ever cached and was never pruned
- **CurlService** — Outbound HTTP for ActivityPub fetches, WebFinger and host-meta lookups, and the async self-call that drains a delivery token. The transport is the server's own client (`OCP\Http\Client\IClientService`), so the CA bundle, the proxy configuration and the local-address checks come from the server; what stays here is federation-specific: the protocol fallback (an instance reachable over `http` only), the download size ceiling, and the mapping onto the app's request exceptions
- **FediverseService** — Instance-level access control; see [Security](#security)
- **InstanceService** — Builds and returns the local instance's NodeInfo-style metadata

### Media

- **DocumentService** — Owns the cached document lifecycle: caching a remote document by id, serving originals and resized copies out of app storage, and caching the local actor's avatar and header. Serving applies a viewer bound: a cached attachment is handed to a logged-in user only if it hangs off a post they may read or is their own upload, and the unauthenticated `/media/{uuid}` route only for a row marked public
- **CacheDocumentService** — Writes uploads, remote downloads and temp files into app storage, filters MIME types against an allow-list, and reads content back out. An upload is created non-public; it takes the visibility of the post it is attached to when that post is created (`ApiController::scopeMediaToVisibility()`, public for public and unlisted, non-public otherwise), because which post an upload belongs to is only known then
- **BlurService** — Generates a blurhash string from a GD image
- **AnnouncementService** — The instance-wide notices an admin posts. Two reads that are not the same: a client gets the announcements that apply *now*, each carrying whether that account has dismissed it, and the administration page gets all of them including one that has not started and one that has run out. The window is a predicate of the query, so an announcement starts and stops being served on time on an instance with no working cron — the same rule a timed mute and an expiring filter follow. Dismissal is per account and never hides the announcement: Mastodon keeps serving it and flips `read`. There is no edit route, because changing a notice under the accounts that have already dismissed it is worse than posting a new one
- **DomainBlockService** — Per-account blocks of a whole instance, stored as a domain and applied to the host of an account's actor id. Not `FediverseService`, which is the admin's instance-wide access list. Nothing is federated; the timelines enforce it from inside `filterHiddenActors()`, so a domain block reaches everything a per-account block reaches
- **AccountRelationService** — The private note, the endorsement and the mute expiry of one account towards another, and `decorate()`, which is where `FollowService::generateRelationship()` fills in `domain_blocking`, `note` and a mute that has run out. A timed mute stops applying because the read says so, not because anything ran to delete it
- **StatusRevisionService** — The versions a status has been through. The first edit records the version being replaced as well as the new one, so the first entry of a history is always what was posted
- **DirectoryService / SuggestionService / TrendService / FeaturedTagService** — Discovery. The directory is opt-in through `discoverable`, applied as a predicate of the deciding query rather than as a filter over rows already read; suggestions are two counted facts (friends of friends, then locally active accounts) rather than a scoring model; trends count from the rows a like, a boost and a link preview already write, so a trend cannot drift from the counts a status reports
- **BannerService** — The banner across the top of a profile. Three routes set one — a picked file, a URL, and `header` on `update_credentials` — and all three end in the same work: store the bytes, point the cached actor at them, tell the followers. It is one service so those three cannot drift on the parts that matter, which are the banner being public where an attachment is not, and the `Update{Person}` that is the only reason anybody else ever sees it

**Posting a picture that is already in Nextcloud.** `ApiController::mediaFromFile()`
(`POST /api/v1/media/from-file`) attaches a file out of the user's own storage,
so the one thing this app should never ask of the person running it — download
your own photo, then upload it back — is not required. The path is resolved
through `IRootFolder::getUserFolder()` and checked against that folder again
afterwards: the route names a file and returns its contents, which is the shape
a mistake here would be exploited in, so the boundary is stated twice.

The bytes are **copied, not referenced**. A post keeps the picture it was
published with, so moving, renaming or deleting the original cannot empty a post
that has already federated, and the attachment can take the post's visibility
the way an upload does. The copy goes through a temp file into
`saveFromTempToCache()`, which is the same code an upload takes — so the MIME
allow-list, the size ceiling, the resizing and the blurhash cannot drift between
the two ways a picture gets in. `ApiController::storeAttachment()` is the shared
half that guarantees it.

Note what this means for where the file lives afterwards: attachments are held
in **appdata**, not in the user's file tree. A picture posted from Files has a
copy in app storage; the original stays where it was, untouched.

### System

- **ConfigService** — App/user configuration and the derived URLs (cloud URL, social URL, social address, max download size, self-signed toggle), plus ActivityPub id generation
- **CheckService** — Installation checks (is `/.well-known/webfinger` reachable) and repair of invalid follow and note rows
- **ClientService** — OAuth 2.0 client registration, authorization and token issuing
- **DetailsService** — Computes a `StreamDetails` object describing which local viewers a stream reaches
- **MiscService** — Logging helper and the running Nextcloud major version
- **ReportService** — Moderation reports: stores what was filed locally over the client API or arrived from a remote instance as a `Flag`, and notifies the instance admins
- **ModerationService** — Acts on a report: silence, suspend, or take one post down. the two levels are described under ActivityPub Federation below
- **FederationHealthService** — What the outbound queue looks like to an administrator: which instances deliveries are failing against, so an instance that has quietly stopped receiving anything is distinguishable from one nobody posted to
- **TestService** — Backs the WebFinger probe of `occ social:check:install`
- **PushService** — on a new stream, resolves the local audience through `DetailsService` (home + direct viewers) and pushes a `social_timeline` custom event per user through the notify_push app when it is installed; without notify_push every call is a cheap no-op and web clients keep polling. The web client listens via `@nextcloud/notify_push` and drops its 30-second poll to a 5-minute safety net when push is available

---

## ActivityPub Federation

The app is an ActivityPub **Server** that both produces and consumes ActivityPub messages.

### Actor Identity

Each Nextcloud user that has been given a Social account gets a Person actor:

- **ID:** the configured social URL plus `@username`, e.g. `https://cloud.tld/apps/social/@username`
- **Keys:** RSA 2048-bit key pair, generated per actor by `SignatureService::generateKeys()`
- **Endpoints:** `/@{user}/inbox`, the shared `/inbox`, `/@{user}/outbox`, `/@{user}/followers`, `/@{user}/following`, `/@{user}/collections/featured`
- **Collections:** followers, following and outbox answer with an `OrderedCollection` whose `first` and `last` point at `?page=N`, and with `?page=N` they answer with a real `OrderedCollectionPage` of `OrderedCollection::PAGE_SIZE` (40) items linked by `next`/`prev`, so a consumer can enumerate them. `featured` — the actor's pinned posts, which is how a remote server learns about a pin — is a single unpaged collection

Alongside the per-user actors, the instance has one `Application` actor of its own at `<social url>actor`, with its own RSA key pair in app config. It is what signs every outbound ActivityPub GET and what `ReportForwardService` files a `Flag` as; it is not an account and appears in no directory, no client API and no statistic. Its `inbox` is the shared `/inbox` — anything addressed to the server is addressed there — and it names no outbox, followers, following or featured collection, because an actor document naming a collection no route serves is worse than one that omits it.

Every local note carries a `replies` collection at `<post id>/replies`, served paged by `ActivityPub#replies`. A reply reaches the instances that hold the post it answers and nowhere else, so without it a reader on a third instance sees a post with no replies. It lists the ids of the **public** replies only, and remote notes are not given one: their replies live on the server that holds them.

### Outgoing Flow

1. A user action (post, edit, delete, follow, unfollow, like, boost) has a service build the activity
2. `SignatureService::signObject()` adds a Linked Data Signature — for Create, Update, Delete, Like, Announce and their Undos. Follow, Accept, Reject, Block and `Undo{Block}` are **not** LD-signed; they travel with the HTTP signature only
3. `ActivityService::request()` expands the activity's instance paths into concrete target inboxes
4. Targets on this instance are dropped: everyone here already has the item, because recipients are written into `social_stream_dest` when it is saved, which is what puts it in a local timeline. Posting to our own inbox would only hand us back what we wrote, and it is a request the server has to be able to make to its own public address — behind a reverse proxy, split-horizon DNS or an SSRF guard it often cannot, and the delivery then fails its way to being abandoned while remote instances queue up behind it. This holds only for activities whose effect is already applied when the item is saved. A **Follow** has no such path, so `FollowService::followAccount()` detects a local target and runs `FollowInterface::processIncomingRequest()` in process instead — setting the activity's origin to this host first, because that handler is the inbox's and checks it. Without that the row stayed `accepted = 0` for ever and no local follow ever completed
5. `RequestQueueService::generateRequestQueue()` writes one `social_req_queue` row per remaining target
6. At most one row is delivered inline: `RequestQueueService::getPriorityRequest()` hands back the first row only when its priority is `TOP`, or `HIGH`/`MEDIUM` under narrow conditions, and otherwise throws `NoHighPriorityRequestException` so nothing is sent synchronously. If rows remain on standby, `CurlService::asyncWithToken()` fires a request at the app's own `/async/request/{token}` route to drain them
7. `Cron\Queue` (12-minute interval) retries whatever the query says is due, with the backoff above, after returning rows a dead worker left `running` to standby
8. Every delivery is an HTTP POST signed by `SignatureService::signRequest()`

A delivery is retried when the peer's answer says it might accept the activity later — 408, 429 and any 5xx — and the row is dropped only on an answer that says it never will, or once `MAX_TRIES` is reached. A host that has just answered with a transient status is added to the run's failing set, so the rest of the run does not ask it once per queued activity.

**Activities the app emits:** Create, Update, Delete, Follow, Accept, Reject, Like, Announce, Block, Undo.

Reject goes out when a follow request is refused (`FollowInterface::rejectFollowRequest()`, also used to answer a `Follow` from a blocked actor) and when an accepted follow is severed by a block. Block and `Undo{Block}` go out from `RelationshipService`, unless the `federate_blocks` app setting is `0`.

The app never emits Add, Remove or Move. It can parse all three — `AP::getItemFromType()` constructs each one for an incoming document — but no service builds one to send: a pin is a local row that remote servers read from the `featured` collection, and nothing here migrates an account away.

### Incoming Flow

1. A remote instance POSTs to `/@{username}/inbox` or the shared `/inbox`
2. `InboxLimiter::assertAllowed()` spends the per-address bucket, before anything is read
3. `SignatureService::checkRequest()` checks the `date` header for freshness, that `content-length` matches the body, that `digest` matches the body, and then the HTTP signature. It returns the verified origin host, or throws — a request whose signature does not verify never reaches step 4
4. `FediverseService::authorized()` is called with that origin, then `InboxLimiter::assertOriginAllowed()` spends that origin's own, looser bucket. The per-origin ceiling is spent here rather than at step 2 because before step 3 the origin is only what the sender wrote
5. `ImportService::importFromJson()` parses the body into a typed object
6. If the body carries a valid Linked Data Signature the origin is taken from it, otherwise the HTTP-signature origin is used
7. `ImportService::parseIncomingRequest()` looks the handler up with `AP::getInterfaceForItem()` and calls `processIncomingRequest()`. Every exception from the handler is logged and swallowed
8. The controller answers 200 and then drains the inbound stream queue for that request token

**What a refused delivery is answered with.** The status is the only thing a peer reads to decide what to do next, and Mastodon re-queues a 5xx with backoff for about two days — so answering every rejection 500, which a catch-all used to do, turned one refused delivery into a dozen and made blocking an instance *multiply* its traffic. `ActivityPubController::statusForRejection()` maps the failure instead:

| Status | When |
|--------|------|
| 401 | Nothing about the request proves who sent it: no signature, one that does not verify, a replay, a `Date` outside the window, a `Signature` header missing its parts, or an activity whose actor is not the origin that signed for it |
| 403 | The instance access list refuses this origin — a decision about who may talk to this instance, not a failure |
| 400 | The bytes could not be read as an activity, or the `Date` could not be parsed. Redelivering the same bytes cannot help |
| 404 | Addressed to a local actor that does not exist |
| 429 | A rate limit, either bucket |
| 503 | The signature could not be *checked*: the host holding the signing key was unreachable, or its last fetch failed recently enough to still be in backoff. This is the one rejection that invites a redelivery |
| 500 | A fault of this instance's own, and nothing else |

An activity that is understood but has no handler is still answered 200 (see below), as is one whose signature says the key is gone.

**Incoming activities that are actually acted on:**

| Activity | Effect |
|----------|--------|
| `Create` (Note) | Post is stored in `social_stream`, notification generated |
| `Update` (Note) | Stored post is updated |
| `Update` (Person) | Cached remote actor is refreshed |
| `Delete` (Note, Person) | Item is deleted; also handled when only an object id is given |
| `Follow` | Saved and auto-accepted — an `Accept` is queued straight back |
| `Accept` (Follow) | Local follow row marked accepted |
| `Reject` (Follow) | Local follow row deleted |
| `Undo` (Follow, Like, Announce) | The wrapped relation or action is deleted |
| `Like` | Stored as an action, notification generated |
| `Announce` | Stored as a boost, notification generated — unless the announced object is one this instance holds and is not public, in which case the activity is dropped. A boost carries the *booster's* audience, so storing it would republish a followers-only post to everyone the booster reaches; the local boost path has always refused to create one |
| `QuoteRequest` | Somebody asks to quote a local post. Answered with an `Accept` carrying the approval, or a `Reject`, according to the post's own policy — see **Quote posts** below |
| `Accept` / `Reject` (QuoteRequest) | The answer to a request of ours: the approval is written onto the quoting post, or the quote is marked rejected |
| `Move` | Actions, follows, streams and cached documents are repointed to the target actor — but only after the target actor (refreshed from its server) lists the moving actor in its `alsoKnownAs`; a Move whose target does not acknowledge the actor is refused |

**Who may moderate.** Nextcloud's own settings delegation, and nothing beside
it. `AdminSettings` implements `IDelegatedSettings`, so an administrator can
hand the Social section to a group under *Administration privileges*; the page
then opens for that group because core gates it on the delegation, the buttons
on it work because every `ModerationController` and admin `AnnouncementController`
method carries `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, and
the Mastodon admin API agrees because `AdminApiService::isAdministrator()` asks
`IManager::getAllowedAdminSettings('social', $user)`. Before this, moderating
meant administering the whole server — a great deal of power to hand somebody
so they can act on a report — and a second list of moderators kept somewhere of
this app's own would have been one more thing to disagree with the page.
`getAuthorizedAppConfig()` is deliberately empty: a delegate writes the
retention period and the access list through the validating routes above, not
through core's raw app-config endpoint. The check is asked of the *user id*,
never of the token: a scope on an OAuth token says only that some client asked
for it, since registration stores whatever scope string arrives.

**Moderation.** `social_moderation` holds what the *instance* has decided about an account, as against `social_actor_relation`, which holds what one of its users has. Two levels: `silence` keeps the account reachable for its followers and drops it from the public and global timelines (`StreamRequest::filterSilencedActors()`, a small NOT IN rather than a join, because a moderator acts rarely); `suspend` deletes the account's streams and cached actor, makes `ImportService::parseIncomingRequest()` refuse everything it sends afterwards, and — for a local account — stops it acting at all: posting, editing, boosting, liking and following each ask `ModerationService::assertNotSuspended()` first, so the refusal holds for every entry point rather than for whichever controller was remembered — without that last part a suspension would undo itself the next time the account posted. Lifting removes the record; it cannot undo a deletion, and the admin panel says so before suspending.

**Admin metrics.** `MetricsService` answers Mastodon's three metric shapes — a measure (one number a day over a window), a dimension (the ranked list behind one number) and retention (how much of each month's new accounts is still posting later) — plus the three admin trend routes, which answer exactly what the public ones answer because a trend here is what the counts say and there is no review queue to report on. A key this instance cannot answer is **refused with a 422 naming the ones it can**, never answered with zeroes: most of Mastodon's keys describe a sign-up, an invite system, an email address or a media store this app does not own, and `0` reads as "none", which is a different claim and the one an admin acts on. The window is snapped to whole days and capped at 370, because these are full scans of a date range rather than index probes. The SQL lives in the service in `protected` methods, as `AdminApiService`'s does and for the same reason: what decides which question gets asked is then testable without a database. `active_users` counts accounts that **posted**, not accounts that logged in — this app has no session of its own to count.

**Blocks that are about an address.** Mastodon keeps three lists for this — IP blocks, email-domain blocks, canonical email blocks — and all three exist to police a sign-up. This app has no sign-up: an account is a Nextcloud account and the server decides who gets one. So two of the three are given the only meanings they can honestly have, and the third is not implemented rather than stored and never consulted. An **IP block** at `no_access` is enforced in `AccessBlockMiddleware`, which every request this app serves passes through — "no access" is a statement about the whole app, and a block that held on the inbox but not on the API, or on last month's routes but not on the ones added since, is not what an admin switched on; it answers **403**, so a peer stops redelivering. The two sign-up severities are refused at the API rather than stored. An **email-domain block** is checked once, in `AccountService::createActor()`: whether a Nextcloud account gets a fediverse identity at all, which is the same question Mastodon asks one step earlier and is the only one left that is this app's to answer. A **canonical email block** is a hash of the address of a deleted Mastodon account, kept so the same person cannot sign up again; nothing here holds an account's address after deletion, because the address is not this app's to hold. Ranges are matched on packed bytes (`inet_pton`) rather than on text, which is the only way `::1` and `0:0:0:0:0:0:0:1` are the same address and the only way a prefix that falls inside a byte means anything.

**The moderation panel.** The Social section of the administration settings is server-rendered PHP (`templates/settings/admin.php`) with three scripts behind it: the hand-written `js/social-adminSettings.js` for the reports table and the access list, `src/adminAnnouncements.js`, and `src/adminModeration.js` for the account browser and the **Take down** button beside each reported post. The browser reads `GET /moderation/accounts`, which is `AdminApiService::accountPage()` — the same read the Mastodon admin API answers — narrowed to the six fields a table draws; before it, only a *reported* account could be acted on from the web, and everything else needed a moderation client and a token. Every cell is written with `textContent`: a handle and an instance name are whatever a remote server sent, and this is the page whose buttons delete accounts.

**Custom emoji.** `/api/v1/custom_emojis` answered `[]` unconditionally and outbound posts carried no `Emoji` tags, so emoji from every other instance rendered here and this one could publish none — the asymmetry somebody moving here notices first, because their own instance's emoji stop working. `EmojiService` holds the set: the row is in `social_emoji`, the picture in appdata under `emoji/`, and both a local client and a remote server dereference the same URL (`/emoji/{shortcode}`, unauthenticated like `/media/{uuid}` and for the same reason). What a post carries is the shortcode as text plus an `Emoji` tag saying where the picture is, added by `StreamService::addCustomEmojis()` on creation *and* on edit — rebuilt rather than appended to, so an edit that removes a shortcode removes its tag. The scan is Mastodon's own pattern: a colon on each side, neither of them against a word character or another colon, which is what stops `12:30:45` carrying an emoji called `30`. A shortcode this instance has no picture for stays the text it already was. Managed with `occ social:emoji`.

**Strikes.** `social_moderation` is what stands *now*: one row an account, replaced by the next decision and deleted when it is lifted. `social_strike` is the history it used to throw away. Every `ModerationService::decide()` writes one, `warn()` writes one that applies nothing — Mastodon's `none`, and the step the ladder was missing between doing nothing and taking an account out of the timelines — and nothing removes one. A lift says the decision no longer stands, not that it was never taken; without that, the third silence in a month looked exactly like the first, because whoever lifted the last one took the only evidence it had happened. A strike carries the moderator who took it and the report it came from, so a history names somebody. The account is told through Nextcloud's notifications (`moderation_warning` in `Notifier`), and only a **local** account can be: telling a remote one means telling its instance, and no ActivityPub activity says "your user has been warned". The count is what the account browser shows and the history is what opens behind it; `StrikesRequest::countForActors()` answers a whole page in one query, because asking per row turned a forty-account page into forty-one.

**Silencing an instance.** The same middle tier, applied to a whole server. A domain block (`social:fediverse add`, followed by `social:domain:purge`) cuts the instance off in both directions and deletes what it already sent, which also cuts off the local users who deliberately follow somebody there — so the tool was too blunt to reach for and the nuisance stayed. `social:fediverse silence <host>` adds the host to a second list (the `silenced_list` app value, read by `FediverseService::getSilencedAddresses()`) and changes exactly one thing: `StreamRequest::filterSilencedInstances()` drops the instance's posts from the **public**, **global**, **hashtag** and **followed-tag** timelines. Delivery, fetching, webfinger, following, and the home timeline of somebody who already follows the account are untouched — a silence is deliberately not enforced in `authorized()`. The clause is a `LIKE` on `s.attributed_to` rather than on a host column, because there is none: an actor id begins with the scheme and host, so the domain and everything under it is a prefix match, read the way a domain block reads subdomains. `LIKE` is not indexed, which is why it runs only on the timelines that need it and why the list is meant to stay an admin-written handful. Nothing is deleted, so `social:fediverse unsilence` brings the posts back — the difference between this and a block, whose purge does not come back.

**Inbox forwarding (ActivityPub §7.1.2).** A reply to a local post arrives from the replier's instance and from nowhere else, so the followers of the local post would never see it: everyone would read a different, shorter thread. `ForwardService::forwardReply()` therefore passes such a reply on to the followers of the post it replies to, and `NoteInterface::activity()` offers it every newly stored note (a re-delivery finds the note already stored and is not offered again, so nobody is sent the same reply twice).

A reply is forwarded only when all of this holds: it arrived with a **valid linked-data signature** (`SignatureService::ORIGIN_SIGNATURE`), because the recipients must be able to check the author's own signature rather than take our word for it; the post it replies to is **local**, since only the instance holding a post owes its followers the thread; and both the reply and the post are **public or unlisted**, so a private audience is never widened. The body sent is `getSource()` — the document exactly as it arrived, since re-encoding our model of it would drop the signature and change what was signed. Delivery goes through the normal request queue at `PRIORITY_LOW` via `RequestQueueService::generateRequestQueueFromSource()`, so nothing about it holds up the inbox response. The sender's instance and this one are left out of the recipient list.

**`Add` and `Remove`** are handled by `FeaturedCollection`, which is how a remote account's pinned posts arrive: the activity's `object` is resolved against the local store (Mastodon sends a bare URI, not an embedded object), `target` must be the actor's own `featured` collection, and the post must be one this instance holds and that actor authored. A pin is stored as an action row, capped at `MAX_REMOTE_PINS`. `BlockInterface` records the incoming block as an `ActorRelation` of type `TYPE_BLOCKED_BY`.

**Actor types:** `Person`, `Service`, `Group`, `Organization` and `Application` all resolve to a handler, so all five are cached and can be followed — which is what Lemmy communities, Friendica and a.gup.pe groups, and Mastodon's instance and relay actors need. `OrderedCollection` and `Stream` have no handler, being containers rather than things an activity is about.

An activity whose type this app does not implement is logged at `notice` with its type, activity id, object id, actor and origin, and answered `200 OK` so the sender does not redeliver it forever. It used to be discarded without a word, which made "posts from that instance never arrive" impossible to diagnose from this side.

`Tombstone` has no interface either, and deliberately so: it names a deleted object rather than being one. `DeleteInterface` handles it by id — when an embedded object has no handler it looks the id up as a note, then as an actor, the same path a `Delete` carrying a bare id string takes. This is how a deletion from Mastodon, which sends `Delete` with an embedded `Tombstone`, is applied.

An incoming `Block` targeting a local user is remembered as a `blocked_by` relation and severs the follow relationship in both directions; `Undo{Block}` lifts it. A `Follow` from an actor the target has blocked is answered with a `Reject`.

### Quote posts

A quote is a post that embeds another post rather than linking to it, and the
part that needs agreeing on is not the embedding — it is consent. FEP-044f, and
Mastodon 4.5 with it, treats a quote as something the quoted author grants, and
a quote without that grant renders as a bare link no matter what the quoting
server says about it. So the feature is a handshake, and this app is on both
ends of it.

**Quoting.** `Status::import()` reads a client's `quote_id`, `PostService`
stores it on the post as `quote`, and the post is published straight away — the
author should not wait on somebody else's server. In the same step
`requestQuoteApproval()` sends a `QuoteRequest` to the quoted author's inbox
naming the quoting post as its `instrument`. Until an answer comes back the
quote's state is `pending`; a failure to even send the request is logged and
nothing more, because the post is already out.

**Being quoted.** `QuoteRequestInterface::processIncomingRequest()` answers for
local posts. The policy is `Stream::isQuotable()` — public and unlisted, yes;
anything narrower, no — which is the same rule `PinService::pin()` and
`BoostService::create()` apply, and for the same reason: a quote carries the
audience of the quoter, so a narrower post would reach readers its author never
addressed. It is also exactly what `interactionPolicy.canQuote` advertises on
our posts, and the two have to agree, because Mastodon offers its users a quote
button on the strength of the advertisement and shows them an error if the
request is then refused. A `Yes` is an `Accept` whose `result` is the URI of the
approval.

**Quoting a post of our own.** Then this server is the authority the request
would be addressed to, and there is nobody to ask: a `QuoteRequest` would be the
instance delivering to its own inbox and waiting for its own answer.
`PostService::applyQuote()` grants the approval on the spot instead — the post
was already checked against the same policy — and stamps it onto the note before
the wire object is snapshotted, so the first delivery already carries it.

**What a client is told.** The `quote` entity's state is read from the approval,
not from whether the quoted post happens to be in the database: `accepted` means
the author said yes, `pending` means no answer yet. Holding the quoted post
answers a different question — whether we *could* show it — and deriving the
state from that reported every quote as accepted the moment it was written,
including ones the author went on to refuse. An accepted quote whose post is
missing here, or closed to this particular reader, is still `accepted`, with a
null `quoted_status`; calling that `pending` would report the author as not
having answered when they have.

**The approval.** That URI is `<quoted post>/quote_authorizations/<stamp>`,
where the stamp is the quoting post's id in base64url. Carrying the id rather
than a digest of it is what lets the endpoint be stateless: a peer that
dereferences the URI — Mastodon does, before it will render the quote inline —
gets a `QuoteAuthorization` document built from the stamp and the post's current
policy, with nothing stored in between. Deriving the stamp from the id also
means a request redelivered twice is answered with the same URI both times
instead of two approvals that disagree.

Answering from the *current* policy is deliberate, and it is the only way a
grant is taken back on this side. An author who narrows a post has withdrawn the
permission, and a peer that re-checks the approval finds the endpoint no longer
answering. Nothing pushes that news: statelessness has a price, and this is it —
approvals granted are not recorded, so there is no list of who to tell. A peer
that never re-checks goes on showing the quote. Withdrawal in the other
direction does arrive promptly: a `Reject` for a quote that was previously
accepted is applied as a revocation, the stamp comes off both the
`quote_authorization` column and the stored wire object so later deliveries stop
claiming an approval, and the client sees the quote's state as `revoked` rather
than `rejected`.

**On the wire.** `quote` is FEP-044f's name and what Mastodon 4.5 reads first;
`quoteUrl` and `_misskey_quote` are emitted beside it for the servers that
predate the FEP. `quoteAuthorization` carries the approval once there is one.

### Discovery

`WellKnown/WebfingerHandler` is registered as a Nextcloud well-known handler and serves three services at the server root:

- **WebFinger:** `/.well-known/webfinger?resource=acct:user@domain` — returns the `self` link to the actor. The href is the actor's **stored id**, not a URL built from the host the request arrived under: on an instance reachable under two names, the request-derived form handed a remote server an actor id that disagreed with the document it then fetched
- **NodeInfo:** `/.well-known/nodeinfo` — returns the discovery document pointing at the app's own `/apps/social/.well-known/nodeinfo/2.0` route (`OAuthController::nodeinfo2()`), which carries the actual server metadata
- **host-meta:** `/.well-known/host-meta`

All three return the previous handler's response untouched when `FediverseService::jailed()` says the instance is in allow-list mode with an empty list.

---

### Reading a timeline

A timeline is read in two queries rather than one.

The first decides *which* posts belong in the page: it carries all the joins
and filters (recipients, follows, hidden actors, duplicate suppression) but
projects a single column, `s.nid`. The second fetches the rows for exactly
those ids, joining only what is needed to render them.

The reason is `SELECT DISTINCT`. Joining the recipients and follows tables can
return a stream more than once, so the query has always been `DISTINCT` — over
eighty columns, several of them TEXT. A database cannot deduplicate that
without building and sorting the entire matching set first, which is why a
timeline used to cost the same whether twenty rows were asked for or a hundred.
Deduplicating one integer is cheap; the wide read is then a primary-key lookup
of twenty rows.

Measured with `occ social:benchmark` on 20 000 notes: the home timeline went
from 219 ms to 89 ms and the public timeline from 121 ms to 29 ms, returning
the same rows in the same order.

The status a row *points at* — the post a boost repeats, the post a notification
is about — is joined by `SocialCrossQueryBuilder::leftJoinObjectStatus()`, and
that join carries the viewer bound of its own: addressed to the public
collection, written by the viewer, addressed to the viewer, or written by
somebody the viewer follows, as correlated `EXISTS` clauses rather than as
further joins (a second FROM entry for the dest or follow table is a cartesian
product). A boost carries the audience of the *booster*, not of the post, so
without this a remote `Announce` of a followers-only status handed that status,
in full, to everyone the booster reaches. When the viewer is not entitled to the
object the joined columns come back empty, which reads downstream as "no
object": the row stays, its content does not.

## Frontend Architecture

The user interface is a **Vue 3** front end using Vue Router, Pinia, `@nextcloud/vue` components, `@nextcloud/axios`, DOMPurify (via `src/utils/sanitizeHtml.js`), linkifyjs, and twemoji.

### Entry bundles

`webpack.common.js` defines five entries; the Nextcloud webpack preset prefixes the output with the app id, so they land in `js/` as:

| Bundle | Source | Loaded by |
|--------|--------|-----------|
| `social-social.js` | `src/main.js` | `templates/main.php` — the main SPA |
| `social-dashboard.js` | `src/dashboard.js` | `SocialWidget::load()` |
| `social-oauth.js` | `src/oauth.js` | `templates/oauth2.php` |
| `social-profilePage.js` | `src/profile.js` | `ProfileSectionListener` |
| `social-ostatus.js` | `src/ostatus.js` | nothing — no `addScript()` call references it |

The OStatus bundle and `src/views/OStatus.vue` are therefore dead code today: `OStatusController::subscribe()` and `followRemote()` both render the `main` template, so remote-follow lands in the main SPA on its `/ostatus/follow` route.

### Store

`src/store/` holds five Pinia stores — `timeline`, `account`, `settings`, `errors` and `notifications` — and `index.js` creates the Pinia every entry point installs. Components reach them through `mapStores`, or through a composable where the same few values are wanted together: `useServerData`, `useCurrentUser` and `useAccount` in `src/composables/` replaced the three mixins the app used to carry.

Server-side state is not a store: it is passed through Nextcloud's initial state as `serverData` and read by `useServerData`.

Each store is installed per Pinia instance rather than per module registration, which is the difference that matters for tests — two Pinias give two sets of state, where the Vuex modules shared one object literal between them.

### Routes and views

`src/router.js` declares one redirect, six named routes and one unnamed route:

| Path | Route name | View |
|------|-----------|------|
| `/` | — (redirects to `timeline`) | — |
| `/timeline/:type?` | `timeline` | Timeline |
| `/timeline/:type?/tags/:tag` | `tags` | Timeline |
| `/@:account` | `profile` | Profile + ProfileTimeline |
| `/@:account/followers` | `profile.followers` | Profile + ProfileFollowers |
| `/@:account/following` | `profile.following` | Profile + ProfileFollowers |
| `/@:account/:id` | `single-post` | TimelineSinglePost |
| `/ostatus/follow` | — | Profile + ProfileTimeline |

`profile.followers` and `profile.following` render the same `ProfileFollowers` component; it decides what to load from the route.

Views outside the router: `Dashboard.vue` (mounted by the dashboard entry), `OAuth2Authorize.vue` (mounted by the OAuth entry on `#social-oauth2`), `ProfilePageIntegration.vue` (registered by the profile entry as the `social-profile-section` custom element), and `OStatus.vue` (unreachable, per the table above).

### Components

`src/components/` holds the timeline and profile UI: `TimelineList`, `TimelineEntry`, `TimelinePost`, `TimelineAvatar`, `ActorAvatar`, `ProfileInfo`, `FollowButton`, `UserEntry`, `Navigation`, `Search`, `MediaAttachment`, `PostAttachment`, `Emoji`, `EmptyContent`, `QuotedPost`, `HashtagFollowButton`, `HashtagFollowedList`, the `Gallery` group (`GalleryCarousel`, `GalleryMedia`, `GalleryRatio.js`), the `Composer/` group (`Composer`, `PreviewGrid`, `PreviewGridItem`, `SubmitStatusButton`), the `Visibility/` group (`VisibilitySelect`, `VisibilityIcon`), and `MessageContent.js`, a render-function component that parses a post body and rebuilds it as Vue nodes (turning mentions and hashtags into `router-link`s and emoji into `Emoji` components).

`ProfileInfo.vue` keeps every control for the profile in one dialog: the banner
(a file, or the address of one), the bio and the metadata fields. The banner
buttons used to float over the picture on the owner's own profile, which put
editing chrome on a page whose job is to show somebody. Applying a banner from a
URL leaves the dialog open, because the bio and the fields may still be being
edited.

`Composer.vue` grows a second attach control beside the paperclip: the
`@nextcloud/dialogs` file picker, so a picture already in the user's Nextcloud
goes straight to `POST /api/v1/media/from-file` instead of being downloaded and
uploaded back. Both sources fill the same attachment map and share one ceiling
of eight, and with anything attached the preview grid moves above the text box —
in the DOM, so the tab order follows the eye — and the box becomes a caption
field. A picture with no alt text is marked as such on its own thumbnail, and a
description is saved on leaving the field rather than only when the post goes
out, so it survives a post that is never sent.

`Composer.vue` carries a full `tributeOptions` config for `@` account and `#` hashtag completion. `tributejs` is a plain DOM library rather than a component: it is attached to the contenteditable in `mounted()` and detached in `unmounted()`, and it appends its menu to the body, which the unscoped `.tribute-container` rule at the end of the file styles. The account collection searches `/api/v1/global/accounts/search` and the hashtag collection `/api/v1/global/tags/search`, both debounced. The emoji picker is a separate `NcEmojiPicker`.

`QuotedPost.vue` renders a status's `quote`. Only an `accepted` quote whose
`quoted_status` came back becomes a card; `pending`, `rejected`, `revoked` and
an accepted quote the reader may not see each get a line saying which, because
a quote that silently renders as nothing is indistinguishable from a bug. A
quoted post that itself quotes something is not nested a second time — the
component prints one line and stops, so no chain and no cycle can recurse.

**The Photos view.** The sidebar's `Photos`, directly under Home, is the home
timeline with `only_media` — the people you follow, but only what they showed
rather than what they said. It is the same query and the same filters, one
predicate narrower, so nothing about visibility, blocks, mutes or silencing is
decided twice.

**One column, one owner.** `--social-column` in `App.vue` is the width of the
timeline — 900px — and every view that shows the same column reads it from
there: the profile, the followers and follow-request lists, the blocked
accounts, search, the welcome banner and the composer. They each used to carry
their own `600px`, so widening the timeline alone would have made every other
page snap back on navigation. (The OAuth consent card keeps its own narrow
width; it is a dialog, not the column.) The list keeps `--social-column-gutter` inside that, so a
post is narrower than the column by a gutter on each side; the composer takes the
column whole and therefore stands that much proud of the posts beneath it, which
is deliberate — the box you write in should read as the thing that makes them
rather than as one of them. Both used to carry the same max-width independently
while only one of them had a gutter, which is how they came to disagree.

`TimelineList` owns its own layout and the views that render it do not touch
it. That is not style: `.social__timeline` is a child component's **root**, and
a scoped rule still reaches a child's root — so a view styling it lands beside
the list's own rule at the same specificity and wins or loses on whatever order
the bundle puts them in. `Timeline.vue` set `margin: 0` there, which beat the
list's `margin: 0 auto` and left the timeline flush to one side while the
composer beside it stayed centred. Where a view genuinely needs to shift the
list — the reply spine in `TimelineSinglePost` — it says so through its own
element (`.thread .social__timeline`), which wins on specificity rather than on
luck.

Every entry in the list has the same edges for the same reason. A notification
is a card, because it is a thing that happened and the post inside it is quoted
evidence; a boost is not, because it is somebody else's post with a line saying
who passed it on. Giving a boost a card put a box inside a box and inset the
post by the outer padding, so boosted posts were narrower than their neighbours.

**Posts that are pictures.** A post carrying attachments and no content
warning is laid out around them: `TimelinePost.vue`'s `mediaLeads` puts
`PostAttachment` above the text, which then reads as a caption. A warning wins
over that — its cover has to come before anything it covers — and so does edit
mode, where the text is the thing being worked on. One or two pictures are a
mosaic; from three (`CAROUSEL_FROM`) they become a `GalleryCarousel` paged with
the arrow keys, Home and End, because eight thumbnails side by side are eight
pictures in which nothing can be made out. The older thumbnail grid stays for
every place the text still leads.

`GalleryRatio.js` reserves each picture's box from `meta.original` before it
loads, clamped between 3:4 and 16:9, so a photo timeline does not jump under the
reader's thumb as images arrive; `MediaAttachment.vue` paints the `blurhash`
into that box meanwhile. `GalleryMedia.vue` carries the ALT badge — the
description is what the picture *is*, and a reader who cannot see it is not the
only one who wants it — and sets the `alt` attribute from the same value.

`HashtagFollowButton.vue` reads `/api/v1/tags/{tag}` on mount and whenever the
route's tag changes, and takes its state from what the server answers rather
than from what was asked, so a refused follow does not leave the button lying.
It renders nothing on the public page, where there is no viewer to follow
anything. `HashtagFollowedList.vue` is the disclosure beneath it.

---

## Integration Points

| Integration | Class | Registered in | Description |
|-------------|-------|---------------|-------------|
| Dashboard | `SocialWidget` | `Application::register()` | Recent Social notifications, rendered client-side; loads `social-dashboard` |
| Dashboard | `SocialTimelineWidget` | `Application::register()` | Home timeline, 300-second reload interval |
| Dashboard | `SocialMentionsWidget` | `Application::register()` | The notifications probe filtered to `mention` |
| Dashboard | `SocialDirectWidget` | `Application::register()` | Direct messages |
| Dashboard | `SocialBookmarksWidget` | `Application::register()` | Saved posts |
| Dashboard | `SocialFollowRequestsWidget` | `Application::register()` | Follow requests awaiting an answer; conditional — offered to a locked account, or one that still has requests waiting |
| Dashboard | `SocialTrendingWidget` | `Application::register()` | Trending hashtags over one day, 900-second reload interval; needs no viewer |
| Dashboard | `SocialReportsWidget` | `Application::register()` | Open moderation reports; conditional — admins only |
| Dashboard | `SocialFederationHealthWidget` | `Application::register()` | Instances the outbound queue is failing to reach; conditional — admins only |
| Unified Search | `UnifiedSearchProvider` | `Application::register()` | Searches URIs, accounts, hashtags and **status content** (case-insensitive substring over the statuses the viewer may see: own posts, public/unlisted, and what is addressed to them — the timeline viewer bound). Local hits link to the post page, remote hits to their origin. Honours the query's cursor and limit — each source is asked for one entry past the end of the page, and a further page is offered only when one of them supplied it. It used to advertise a next cursor unconditionally while reading neither, so "load more" served the first page for ever |
| Notifications | `Notifier` | `Application::register()` | Prepares Social notifications for the NC notification system |
| User migration | `UserMigration\SocialMigrator` | `Application::register()` | Puts the user's Social data in a Nextcloud account export, and reads it back on import. See "Account export and import" below |
| Profile Page | `ProfileSectionListener` | `Application::register()` (on `BeforeTemplateRenderedEvent`) | Adds the `social-profilePage` script to the user profile page |
| User Events | `UserAccountListener` | `Application::register()` (on `UserUpdatedEvent`) | Re-caches the local actor when the NC account changes |
| WebFinger / NodeInfo / host-meta | `WebfingerHandler` | `Application::register()` | ActivityPub discovery at the server root |
| Contacts Menu | `ContactsMenuProvider` | `appinfo/info.xml` | "Follow %s on Social" entry linking to the actor page |
| Background Jobs | `Cron\Cache` | `appinfo/info.xml` | 12-minute interval: reaps deleted actors, refreshes local and remote actor caches, caches documents, recomputes hashtag trends, prunes remote statuses past retention (bounded to 5000 per run), syncs remote timelines |
| Background Jobs | `Cron\Queue` | `appinfo/info.xml` | 12-minute interval: drains the outbound request queue and the inbound stream queue |
| Background Jobs | `Cron\ActorCleanup` | queued by `PersonInterface::delete()` | Finishes detaching a deleted account from the posts that addressed it, when there are more of them than one inbox request should rewrite. Not in `appinfo/info.xml`, for the same reason `Cron\DomainPurge` is not: it is meaningless without an argument. Re-queues itself while rows remain |
| Background Jobs | `Cron\DomainPurge` | queued by `FediverseService::addAddress()` | Queued with a domain when one is added to the deny list, never registered in `appinfo/info.xml` — a job listed there is added once at install time with no argument, and this one is meaningless without a domain. Runs 10 batches of `DomainPurgeService` per pass and re-queues itself while anything of the domain is left |
| Background Jobs | `Cron\ScheduledPosts` | `appinfo/info.xml` | 5-minute interval: publishes the scheduled posts whose time has come, at most 50 per run. Shorter than the other two jobs on purpose — a post may be published up to one cron period late, and a period longer than the five minutes' notice the API demands would promise a precision the app cannot keep |
| Repair step | `Migration\EncryptPrivateKeys` | `appinfo/info.xml` | Seals legacy plaintext actor private keys with ICrypto, once. A row it cannot process is named and skipped rather than aborting `occ upgrade` with the instance in maintenance mode |
| Repair step | `Migration\HashClientSecrets` | `appinfo/info.xml` | Rewrites legacy plaintext client secrets/codes/tokens as sha256 digests, once. Asks the database for the rows that still need converting instead of hydrating the whole client table, and isolates a row it cannot process |
| Repair step | `Migration\BackfillRemoteVisibility` | `appinfo/info.xml` | Backfills the empty visibility of remote statuses stored before estimation landed (public/unlisted set-based, followers/direct per author), idempotent |
| Repair step | `Migration\CacheFeaturedCollections` | `appinfo/info.xml` | Rebuilds the cached copy of every local actor when something the cache carries has changed — the `featured` URL, the display name. Gated on a `VERSION` marker rather than re-running on every upgrade, and it counts the local actors before loading any |
| Repair step | `Migration\BackfillStreamPostFields` | `appinfo/info.xml` | Fills in `social_stream.tags`, `language`, `updated`, `quote` and `quote_authorization` for the rows stored before those columns existed, by re-reading each row's wire object through `Stream::importFromDatabase()` — one parser, not a second copy of it. Pages on the primary key, writes only the rows that disagree, and is gated on a marker so it is not a full scan of the largest table on every later upgrade |

The four timeline tiles (home, mentions, direct, bookmarks) extend
`Dashboard\TimelineWidget`, which resolves the viewer, builds the `ProbeOptions`
and turns a stream row into a tile row; a subclass supplies the probe, the
`/timeline/{path}` it opens and its empty-state wording. A row's `sinceId` is
the stream `nid`, which is what the widget feeds back to `ProbeOptions::setSince()`
on the next poll — `setMinId()` would return the oldest matching rows instead of
the newest. A boost renders as the post it repeats, subtitled with who boosted
it; a boost or notification whose subject did not resolve has no row.

Twenty-one occ commands are registered in `appinfo/info.xml`. `lib/Command/` also holds `ExtendedBase.php`, a shared base several of them extend; it calls no `setName()`, so it registers no command of its own. See `docs/OCC-Commands.md`.

---

## Account export and import

`lib/UserMigration/SocialMigrator.php` implements the server's
`OCP\UserMigration\IMigrator` (and `ISizeEstimationMigrator`), registered in
`Application::register()`. It is what makes a user's Fediverse identity part of
`occ user:export` / `occ user:import` and of the account-transfer UI; before it
existed, an exported account carried nothing of this app at all.

The migrator id is `social` and the export format version is `1`. Everything it
writes lives under `social/` in the archive:

| File | What it holds |
|------|---------------|
| `social/actor.json` | The actor: id, handle, display name, bio, profile fields, `locked`, `discoverable`, `indexable`, `bot`, `sensitive`, default privacy, language, avatar and header URLs, `alsoKnownAs`, `movedTo`, the **public** key and the creation date |
| `social/following_accounts.csv` | Who the account follows, in Mastodon's `following_accounts.csv` shape (`Account address,Show boosts,Notify on new posts,Languages`) — written by `MigrationService::exportFollowsCsv()`, read by `MigrationService::parseFollowsCsv()`, and accepted by Mastodon's own "Import follows" |
| `social/followers.csv` | Who follows the account, same shape. A record for the user; nothing imports it, because a follower is somebody else's decision |
| `social/blocked_accounts.csv` | Blocked handles, one per line (the shape Mastodon exports) |
| `social/muted_accounts.csv` | Muted handles with the `Hide notifications` column |
| `social/bookmarks.csv` | The URLs of the bookmarked posts |
| `social/likes.csv` | The URLs of the favourited posts |
| `social/outbox.json` | The user's own posts as an ActivityPub `OrderedCollection`, written a page at a time through a temporary file so that an account with years of posts never has to fit in memory |

Reads are paged everywhere (`SocialMigrator::PAGE`, 50 rows); the block and mute
lists come from one capped query (`RELATIONS_LIMIT`, 5000), and reaching the cap
is reported on the console rather than silently truncating. A follow whose
account this server never cached is left out of the CSV instead of being written
as a bare actor URL, which no reader of the format accepts.

### What deliberately does not travel

- **The actor's private key.** It is the only secret that lets anything speak as
  that account, ActivityPub has no revocation for it, and the app encrypts it at
  rest (`PrivateKeyCipher`, see Security above) precisely so that a copy of the
  database is not enough to impersonate a local actor. An export archive is an
  ordinary file the user downloads and keeps, so a plaintext key in it would
  undo that — and for nothing: an account imported elsewhere is a *new* actor
  with a new id, and `AccountService::createActor()` gives it a fresh pair.
  Identity continuity is carried by `alsoKnownAs` plus a `Move` from the old
  server (`MigrationService::move()`), which is why the import records the old
  actor id as an alias. The public key is exported, because it is public and
  says which actor this was.
- **Other people's posts.** The local copies of remote statuses are a cache of
  somebody else's content, re-fetched wherever they are needed.
- **Moderation decisions taken against the account**, and reports. A suspension
  deliberately outlives even the deletion of an actor (`PersonInterface::delete()`),
  so it must not be something a user can shed by exporting and re-importing.
- **Tokens, OAuth clients and client secrets**, and the outbound request queue.
  A credential that survived a move would be one nobody can revoke.
- **Media files.** Attachments are referenced by the URLs in `outbox.json`; the
  cached files themselves are not copied into the archive.

### What an import does

An import is safe on a server where the account already exists: the actor is
taken as found and only created when there is none
(`AccountService::getActorFromUserId($uid, create: true)`), every write is
idempotent, and nothing is ever deleted. A missing file is not a failure — an
archive from an older version, or one assembled by hand, imports whatever it
does carry, and an archive with no version for this migrator is skipped
entirely (the migrator is not mandatory). An archive that carries a version but
none of the files above — the export of a user who never used this app — creates
no account either: a Fediverse identity is something a user asks for.

- the profile — `locked`, `discoverable`/`indexable`, the fields and the bio —
  goes back through `AccountService`, the same path the API uses. The **display
  name** does not: it belongs to the Nextcloud account, the core `account`
  migrator carries it, and `AccountService` re-derives the actor's name from it.
- the old actor id is recorded in `alsoKnownAs`, so a `Move` from the old
  account is accepted here. `movedTo` is **not** imported: it would point the
  new account's own followers somewhere else.
- the follows are re-created through the ordinary follow path
  (`MigrationService::importFollows()`), one handle at a time, and a handle whose
  server is unreachable is reported without stopping the rest.
- blocks and mutes are written straight to the relation table. Resolving a
  handle may fetch the remote actor (a signed GET), but no `Block` is federated:
  the account on the other end was already blocked, and was never told about the
  move.
- bookmarks and favourites are re-marked on the posts this server already has.
  A post nobody here has seen is skipped rather than fetched from its origin,
  and a favourite is not re-federated as a `Like`.
- the posts in `outbox.json` are **not** replayed into the timeline. Their ids
  belong to the server they were written on, the threads around them are not
  here, and minting new ids would either publish years of posts to the Fediverse
  again or fill the timeline with statuses no remote server can resolve.
  Mastodon's own import does not restore statuses either.

So the only thing an import sends to other servers is a `Follow` per followed
account — which is the only way a follow can exist at all — plus the single
`Update{Person}` that any bio change sends to the account's followers, of which
a freshly imported account has none. No `Delete`, no `Move`, no `Like`, no
`Block`.

---

## Security

**What is enforced**

- **Authorized fetch (inbound)** — a signature on a **GET** is verified by `SignatureService::checkGetRequest()` and resolved to the account behind it by `AuthorizedFetchService::reader()`, so what this instance serves can depend on who asked: `displayPost()` reads the object as that remote account, which is what lets a followers-only post reach the people who follow it from another server. Before this, verification ran on inbox POSTs only, every GET served what an anonymous reader gets, and a follower elsewhere saw a profile with nothing on it — safe, and also wrong. A GET has no body, so the digest and content-length checks that bind one are not asked for; everything else is the POST path's, including the requirement that `(request-target)`, `host` and `date` be inside the signature and the replay window on the date. An **unsigned** GET is not an error — it is the ordinary case, and it gets what it always got. A signature that is present and *fails* leaves the reader anonymous rather than answering 401: a peer whose clock has drifted, or whose key cannot be fetched at that moment, should still see the public object the route exists to serve. A signer on an instance the access list excludes, and a *local* actor's key signing an inbound fetch (this instance talking to itself, or a replay of one of our own requests), are both refused as readers. **Secure mode** — `secure_mode`, off by default — turns the other half on: an unsigned ActivityPub GET is a 401. It is off by default because switching it on makes this instance invisible to every peer that does not sign, which is a decision about who to federate with rather than something to arrive at by upgrading
- **HTTP Signatures on outbound requests** — every queued delivery is signed with the sending actor's RSA private key over `(request-target)`, `content-length`, `date`, `host` and `digest`. Outbound ActivityPub **GET**s are signed too, by `HttpSignatureService::signFetch()`, over `(request-target) host date` — there is no body to digest. Without this, any peer running Mastodon's authorized-fetch or GoToSocial's secure mode answers 401 to every actor, object and collection fetch, which reads as "user not found" when following and as threads that stop at the first remote reply. The signing identity is one fixed local actor rather than whoever is reading, so a remote instance is not told which of our accounts read which of its posts; a peer that rejects a signed GET is retried once unsigned, so nobody becomes less reachable than before. WebFinger, host-meta and NodeInfo stay unsigned
- **HTTP Signature verification on inbound requests** — `SignatureService::checkRequest()` requires `(request-target)`, `host`, `date` and `digest` to all be within the signed header set, so the signature binds the body and cannot be replayed against another host; it rejects a missing, stale or future `date` (±`DATE_DELAY`, 300 s), a `content-length` that disagrees with the body *when the header is sent* (a chunked sender omits it, and refusing those outright cost interoperability for nothing), and a `digest` that does not match. `Digest` and `Content-Digest` are parsed rather than byte-compared, so a lowercase algorithm token, a multi-value digest or an RFC 9530 header is accepted as long as one algorithm we can compute matches. A signature algorithm that is neither `hs2019` nor absent is refused by name instead of being assumed to be sha256, which used to fail an Ed25519 key with a misleading message. When the signed `host` differs from the configured one — which fails every inbound delivery on a multi-domain or non-default-port install — the log now names both. A signature that does not verify, or whose key cannot be retrieved, is refused by `checkRequest()` itself (it throws), rather than returning an empty origin for a later check to catch
- **Inbound inbox deliveries are rate-limited** — `InboxLimiter` caps deliveries in two places (`inbox_throttle` app setting, default 300 per minute, 0 disables). Before any signature work, `assertAllowed()` spends a bucket keyed on the **source address**, which is the one thing about an unauthenticated request the sender cannot choose. After the signature has been verified, `assertOriginAllowed()` spends a second, looser bucket (`HOST_LIMIT_FACTOR`, 4×) keyed on the **verified origin**, which bounds what one instance can send from however many addresses. The second bucket used to be spent up front on the host named in the sender's own unverified `keyId` — which meant four cheap addresses could fill a large instance's bucket every minute and have its genuine deliveries answered 429, cutting this server off from it. Only a peer that can sign for a host now spends that host's budget
- **LD signatures are bounded in time and replay-checked** — `checkObject()` refuses a signature whose `created` lies more than `LD_WINDOW` (24 h) from now, and remembers accepted signatures in a distributed cache for twice the window, so a captured activity cannot be re-POSTed indefinitely by an instance that once saw it
- **Linked Data Signatures** — outgoing Create, Update, Delete, Like, Announce and Undo carry an RsaSignature2017 signature; incoming ones are verified by `SignatureService::checkObject()`, which also retries against a refreshed public key. Follow and Accept are not LD-signed
- **Instance access control** — `FediverseService::authorized()` is checked on both inbox routes and on every outgoing `CurlService` request. It reads one app config value, `access_type`, which is either `all_but` (the default: everything is allowed unless the host is in the list) or `none_but` (only listed hosts, plus the local host, are allowed), together with a single host list in `access_list`. Hosts are compared case-insensitively and without the trailing dot of the absolute form, and the two modes read the list differently on purpose: a deny-list entry covers the domain and everything under it (`isListed()`), because blocking `evil.test` while `www.evil.test` walks straight back in is not a block; an allow-list entry matches exactly (`isExactlyListed()`), because a subdomain of an allowed domain is a different instance and whoever runs the parent was never asked. `occ social:fediverse` manages both
- **Outbound requests cannot be steered at the local network.** A host that is, or resolves to, a private, loopback, link-local, multicast or otherwise reserved address is refused before anything is sent, unless the instance has set `allow_local_remote_servers` (`lib/Security/RemoteAddress.php`; a name that resolves to nothing is refused too — failing closed). The server's HTTP client then enforces the same rule itself, and re-checks it on **every redirect it follows**, which is why `CurlService` leaves redirect handling to it; only `http`/`https` are ever followed, so a `file://` or `gopher://` location cannot be reached. The banner-by-URL endpoint applies the same rules and a size ceiling before fetching
- **JSON-LD contexts are served only from the copies shipped in `context/`.** Signature normalisation never resolves a document's `@context` over the network, so a remote activity cannot make the server open an arbitrary URL and cannot substitute the bytes a signature is computed over; an unrecognised context makes the LD signature unverifiable rather than triggering a fetch
- **HTML sanitisation** — remote HTML reaches local timelines, so `ACore` runs `lib/Security/HtmlSanitizer.php` over every `AS_CONTENT` field it imports, and strips tags from string, username and account fields. The frontend sanitises again with DOMPurify in `src/utils/sanitizeHtml.js`
- **Actor private keys are encrypted at rest** — `social_actor.private_key` holds the PEM encrypted with the instance secret (`ICrypto`, via `PrivateKeyCipher`), so a database dump alone is not enough to impersonate a local actor — it also takes the `secret` from `config.php`. Rows written before encryption existed (recognisable by their `-----BEGIN` prefix) are still readable and are rewritten once by the `EncryptPrivateKeys` repair step on upgrade
- **Client secrets, authorization codes and access tokens are stored hashed** — `sha256:<hex>` digests (`SecretHasher`). A presented secret that already carries that prefix is never looked up as a legacy plaintext row, so the stored digest is not itself a working credential: offering it for lookup made a database dump, a backup or a read-only SQL flaw hand out usable tokens, which is the one thing hashing them is for
- **Self-signed certificates** — TLS peer verification is skipped only when the `allow_self_signed` app config value is `1`

**Known gaps — these are real and deliberate to record**

- **The federation endpoints are readable by anyone.** `ActivityPubController::actor()`, `actorAlias()`, `outbox()`, `followers()`, `following()` and `displayPost()` all carry `#[PublicPage]` with `#[NoCSRFRequired]`. This instance does not *require* a signed fetch of its own collections, so any anonymous caller can read a local actor's profile, outbox, follower and following collections and individual posts. (Outbound fetches this app makes *are* signed — see above; the two directions are independent.)
- **The older dual blacklist/whitelist implementation in `FediverseService` is commented out**. What remains is the single-list `access_type`/`access_list` mechanism described above
- **`FediverseService::getKnownAddresses()` returns an empty array** unconditionally
- **The base URL is set once.** `ConfigService::setCloudUrl()` will overwrite it, but stored actor and stream ids embed the old URL, so changing it in practice requires `occ social:reset`

---

## Keeping this document in sync

This file, `docs/API.md` and `docs/OCC-Commands.md` describe the current implementation. They must be updated in the same change as the code they describe.

`tests/DocumentationTest.php` mechanically enforces the parts that can be checked, in both directions where that is possible:

- the registered occ commands, **and every option and argument each of them declares** — a documented flag that does not exist, and an existing flag nobody documented, both fail;
- the HTTP routes of `appinfo/routes.php` against the route tables of `docs/API.md`;
- the repair steps of `appinfo/info.xml` against the integration table above;
- the tables declared in `CoreRequestBuilder` against the schema table above;
- the supported Nextcloud and PHP version ranges, and the app version stated at the top of this file, against `appinfo/info.xml`;
- two claims of *absence*, which is the direction the rest of it is blind in: a sentence saying there is no `/some/route` must be true, and a symbol the docs call "commented out" may not be called by live code. The false claim that key-pair rotation was unavailable, published six lines after the flag that performs it, is what these were written for.

Everything else is on the author of the change. In particular nothing can check a paragraph of prose against the behaviour it describes, so a feature described in words the "not implemented" guard does not recognise, or a mechanism described plausibly and wrongly, still gets through.
