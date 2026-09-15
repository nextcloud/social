# Nextcloud Social — Architecture Overview

## Contents

- [Introduction](#introduction)
- [Directory Structure](#directory-structure)
- [HTTP routing](#http-routing)
- [Database Schema](#database-schema)
- [Key Services](#key-services)
- [ActivityPub Federation](#activitypub-federation)
- [Frontend Architecture](#frontend-architecture)
- [Integration Points](#integration-points)
- [Account export and import](#account-export-and-import)
- [Security](#security)
- [Keeping this document in sync](#keeping-this-document-in-sync)

---
## Introduction

Nextcloud Social is a federated social networking app built on the W3C ActivityPub standard. It integrates into Nextcloud as an app, providing each user with an ActivityPub identity (Person actor) that can interact with Mastodon, Friendica, and other Fediverse platforms.

**App ID:** `social`  
**Namespace:** `OCA\Social`  
**License:** AGPL-3.0-or-later  
**App version:** 0.20.6  
**Supported Nextcloud versions:** 35 – 36  
**Supported PHP versions:** 8.3 – 8.5  

All of the above come from `appinfo/info.xml`.

---

## Directory Structure

```
social/
├── appinfo/
│   ├── info.xml                # App metadata, dependencies, cron jobs, occ commands
│   └── routes.php              # One route; the other 258 are attributes on the controller methods
├── lib/
│   ├── AP.php                  # ActivityPub type registry (factory + interface lookup)
│   ├── AppInfo/
│   │   └── Application.php     # Bootstrap, integration registration
│   ├── Command/                # occ CLI commands (+ ExtendedBase, a shared base that registers no command of its own)
│   ├── Controller/             # HTTP entry points (ActivityPub, Mastodon-ish API, local API, OAuth, OStatus, navigation, queue, config, moderation, public pages)
│   ├── Cron/                   # Background jobs (Cache, Queue, ScheduledPosts, ExpiredStories; DomainPurge and ActorCleanup are queued with an argument)
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
│   ├── Settings/               # Admin settings (moderation panel: reports, Fediverse access list, Server card)
│   ├── SetupChecks/            # The four ISetupCheck classes shown in Administration → Overview
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

## HTTP routing

The app registers 259 routes. 258 of them are `#[FrontpageRoute]` attributes on
the controller method that answers the request, next to the `#[PublicPage]`,
`#[NoCSRFRequired]` and rate-limit attributes that decide who may call it — url
and policy in one place. None are `#[ApiRoute]`: that is the OCS type, and the
server serves OCS routes under `/ocsapp`, which is not where these paths are
published.

Two things about the order the server reads them in, because two routes of this
app can match the same url:

- `OC\Route\Router::getAttributeRoutes()` walks `lib/Controller` with a
  `DirectoryIterator` and reflects over each `*Controller.php`, so attributes
  are read in method-declaration order **within** a class, and in whatever order
  the filesystem lists the files **between** classes. Where a url is ambiguous,
  only the within-a-class order can be relied on:
  `ActivityPubController::displayPost()` (`/@{username}/{token}`) is declared
  after `getInbox()`, `outbox()`, `followers()` and `following()` for that
  reason, and has to stay there.
- `appinfo/routes.php` is loaded after every attribute route of the app. That is
  why `ApiController::accountGet()` is still declared there: its
  `/api/v1/accounts/{id}` accepts slashes in `{id}`, so it also matches
  `/api/v1/accounts/{account}/lists` and `/api/v1/accounts/{account}/featured_tags`,
  which live in `ListController` and `DiscoveryController` — no arrangement of
  attributes can put it after routes of another class.

A route's name is derived, not written: the controller's short name without the
`Controller` suffix, then `#`, then the method. Two routes on one method
therefore share a name unless one carries a `postfix`, and the later one wins —
which is what `NavigationController::navigate()` uses `postfix` for.

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
| `social_cache_actor` | Cached remote federated actors (inbox/outbox URLs, public keys, counts), and the refresh bookkeeping `sync_attempt`/`sync_failures` |
| `social_cache_doc` | Cached remote and local media attachments |
| `social_client` | OAuth 2.0 client registrations |
| `social_follow` | Follow relationships (actor → object, with accepted flag) |
| `social_hashtag` | Hashtag trend data: a JSON `trend` blob per hashtag, plus one sortable integer column per window (`trend_1h` … `trend_10d`) |
| `social_instance` | Known federated instances (version, metadata) |
| `social_req_queue` | Outbound ActivityPub delivery queue, indexed for the drain's own sort on `(status, priority, tries, last)` |
| `social_stream` | Core content table: posts, notes, activities. Nine JSON-in-TEXT columns (`to_array`, `cc`, `bcc`, `hashtags`, `tags`, `details`, `instances`, `attachments`, `cache`) beside the scalar ones; `source` holds the ActivityPub wire object verbatim, and `archived` — a post its author has put away |
| `social_discover_cat` | The subjects an instance says Explore is about: a name and the hashtags it means, in the order an administrator put them in |
| `social_story_react` | What was said back to a story: one row per reaction or reply, deleted with the story it answers |
| `social_stream_view` | Who has opened a post's own page: one row per (post, viewer), unique on the pair, so the number the author sees is people rather than visits |
| `social_stream_act` | Per-viewer stream flags (`liked`, `boosted`, `replied`, `bookmarked`, `values`) |
| `social_stream_dest` | Stream visibility targets (who sees what) |
| `social_stream_queue` | Inbound stream processing queue |
| `social_stream_tag` | Stream-to-hashtag mapping |
| `social_actor_relation` | Blocks and mutes: one row per (local actor, target actor, `block`/`mute`/`blocked_by`) |
| `social_report` | Moderation reports, local and federated `Flag` activities, with a `resolved` flag |
| `social_media_block` | Pictures this instance refuses by sha256 of the file, with the reason, who decided it, and how many times it has since been turned away |
| `social_moderation` | The decision taken about an account: one row per silenced or suspended actor (`level`), and `force_sensitive` — every post by this account marked sensitive |
| `social_strike` | Every decision ever taken about an account, including the warnings that took none: `action`, `text`, who took it and which report it came from |
| `social_client_auth` | One authorization per (app, account): the code, the token, the scopes granted and the account they were granted to. Unique on (client, account) |
| `social_access_block` | The blocks that are about an address rather than an account: an IP range this instance answers nothing from, and an email domain it gives no fediverse account to |
| `social_announce_react` | The emoji accounts have put on an announcement, unique on (announcement, account, emoji) |
| `social_emoji` | The custom emoji this instance publishes: `shortcode` (unique), the appdata file behind it, its media type, its picker category and whether a picker offers it |
| `social_stream_card` | The link-preview card of a status (url, title, description, image, provider), one row per stream |
| `social_reaction` | Who reacted to which post with which emoji; unique on (actor, post, emoji), so a redelivered `EmojiReact` is refused rather than counted twice |
| `social_gif` | The instance's shared picture library the composer offers, unique on the slug; the bytes live in appdata beside the custom emoji |
| `social_followed_tag` | The hashtags an account follows: one row per (actor, lowercased tag), unique on the pair |
| `social_collection` | Collections: an album an account curates out of its own posts, with its title, description and visibility |
| `social_collection_item` | What is in a collection: one row per (collection, post), unique on the pair, ordered by `position` |
| `social_place` | Places: one row per distinct place this instance has seen, deduplicated on (name, country). No geocoder — see the migration |
| `social_import_post` | What an account has brought over from another server: one row per (account, original id), unique on the pair, naming the local post it became |
| `social_post_hold` | The posts waiting for a moderator: the client's request, the rule that held it, and the digest the queue is unique on |
| `social_story` | Stories: one picture that expires after a day, with its caption, hold time, `expires_at`, the ActivityPub id it travels under (`source_id`/`source_id_prim`) and whether this instance wrote it (`local`) |
| `social_story_view` | Who has seen a story: one row per (story, viewer), unique on the pair |
| `social_list` | Mastodon lists: one row per (owner, list), with its title, `replies_policy` and `exclusive` flag — and `group_id`, the Nextcloud group a list follows, `''` for one made by hand |
| `social_list_member` | Who is in a list: one row per (list, account), unique on the pair |
| `social_filter` | Keyword filters: one row per (account, filter) with its contexts, action and expiry |
| `social_filter_kw` | The keywords of a filter: one row per keyword, with its `whole_word` flag |
| `social_filter_st` | The posts a filter covers by name: one row per (filter, post) |
| `social_convo_state` | What one account has done with one conversation: how far it has read and dismissed the thread, both as a message nid, and whether it has muted it, one row per (account, thread root) |
| `social_domain_block` | Instances one account has blocked for itself: one row per (account, domain), unique on the pair |
| `social_account_note` | The private note one account keeps about another: one row per pair, never federated |
| `social_mute_expiry` | When a mute runs out: one row per (muter, muted), and only for a mute that was given a duration |
| `social_stream_rev` | The versions a status has been through: one row per version including the original, oldest first |
| `social_featured_tag` | The hashtags an account pins to its profile: one row per (actor, lowercased tag), unique on the pair |
| `social_announcement` | The instance's announcements: one row per notice, with the text as typed and the window it is served in (both bounds nullable) |
| `social_announce_read` | Who has dismissed which announcement: one row per (account, announcement), unique on the pair |
| `social_scheduled` | Posts asked to be published later: one row per waiting post, with the client's request as JSON in `params` and the resolved visibility inside it |

`Version1000Date20260611000001` only drops the abandoned `social_3_*` tables from an earlier prototype. `Version1000Date20260907000001` adds the timeline indexes and the missing primary keys, `Version1000Date20260907000002` adds `social_actor_relation`, `Version1000Date20260907000003` adds the `bookmarked` flag to `social_stream_act`, `Version1000Date20260908000001` widens `social_client.app_client_secret` for its hashed value, `Version1000Date20260908000002` adds the `locked` flag to `social_actor`, `Version1000Date20260908000003` adds `social_report` (moderation reports), `Version1000Date20260908000004` adds the `fields` column to `social_actor` (the profile metadata fields), `Version1000Date20260908000005` adds `social_stream_card` (link previews), `Version1000Date20260909000001` adds `social_moderation` (the silence/suspend decisions, indexed on `level`), `Version1000Date20260910000001` adds the indexes the hot paths were querying as if they existed (`social_cache_doc.id_prim` and `parent_id_prim`, `social_stream_act` by (actor, flag), `social_stream_tag` by tag, `social_action` by (object, type), both queues by `status`/`id`, `social_client.token`, `social_stream.creation`, `social_cache_actor` by (local, details_update) and `social_follow` by (object, actor)) and drops the redundant five-column `ipoha` unique index on `social_stream`, `Version1000Date20260910000002` adds the sortable `trend_*` counter columns to `social_hashtag` zeroed (the JSON `trend` column stays and remains what the API hands back), `Version1000Date20260910000003` fills those columns in from the JSON, `Version1000Date20260911000001` adds the `sensitive` flag to `social_stream`, and `Version1000Date20260911000004` adds `social_followed_tag` (the hashtags an account follows, unique on (actor, tag) — which is also the index the home timeline reads). `Version1000Date20260911000005` adds `social_list` and `social_list_member` — Mastodon's lists and their membership, the membership table unique on (list, account), which is both what makes adding an account twice a no-op and the index the list timeline joins `social_stream.attributed_to_prim` on. `Version1000Date20260911000006` adds `social_filter` and `social_filter_kw` — the keyword filters an account mutes posts with, indexed by owner and by filter, which are the two reads there are. `Version1000Date20260911000007` adds `social_convo_state`, unique on (account, thread root) — the read and dismissed markers behind `/api/v1/conversations`. The conversations themselves get no table: a conversation is a thread of `social_stream` rows derived from `in_reply_to` at read time, and its id is the nid of the thread root. `Version1000Date20260911000008` adds `social_domain_block`, `social_account_note` and `social_mute_expiry` — the per-account instance blocks, the private notes and the expiry of a timed mute, each unique on the pair it is keyed by, which is both what makes writing one twice a no-op and the index its read path probes. An endorsement is not among them: it is a row in `social_actor_relation` with type `endorse`, which is what that table already holds. `Version1000Date20260911000009` adds `social_stream_rev` (the revisions of an edited status, indexed on (status, id), which is the only read there is) and `Version1000Date20260911000010` adds `social_featured_tag` (the hashtags an account pins to its profile, unique on (actor, tag)). `Version1000Date20260911000011` adds `social_announcement` and `social_announce_read` — the announcements and their dismissals, the dismissal table unique on (account, announcement), which is both what makes dismissing twice a no-op and the index the client read probes. The announcements table gets no index beyond its key: every read of it is its whole active set, and it holds a handful of rows. `Version1000Date20260911000014` adds `social_scheduled` — the posts a client asked to have published later — with two indexes, one per read there is: `(actor_id_prim, scheduled_at)` for one account's list and the daily cap, and `(scheduled_at)` for the cron's "what is due across every account", which the first index cannot answer because its leading column is the account. `Version1000Date20260911000020` adds `forwarded` to `social_report`: whether a report was passed on to the instance that hosts the reported account, which the admin API used to answer as a hardcoded `false`. `Version1000Date20260912000001` adds the two indexes `Version1000Date20260910000001` left out: `social_actor.user_id`, which resolves the logged-in user's actor on every authenticated request and had no index at all, and the four trend windows of `social_hashtag` other than `trend_1d` (`trend_1h`, `trend_12h`, `trend_3d`, `trend_10d`), each of which `getTrending()` filters and orders on. `Version1000Date20260912000002` adds `social_actor.bot` — whether a local account is automated, which is what Mastodon's `bot` reports and what decides whether the actor document says `Service` or `Person`; it was accepted from clients and dropped. `Version1000Date20260912000003` adds `social_strike` — the history of moderation decisions, indexed on the account, which is the only read there is. `Version1000Date20260912000011` adds `social_convo_state.muted` — the thread an account has stopped hearing from, a column rather than a table because that row already records what one account has done with one thread. `Version1000Date20260912000010` adds `social_client_auth`, unique on (client, account) with an index on each of the two secrets it is looked up by — and carries the authorization already on each client row across, so a token in use today goes on working. `Version1000Date20260912000006` adds `social_access_block`, unique on (type, value) — one table for two lists, because what differs between Mastodon's two is a severity column and a count, and neither is worth a second table on an instance that holds tens of these rows. `Version1000Date20260912000005` adds `social_announce_react`, unique on (announcement, account, emoji) — both what makes reacting twice with the same emoji a no-op and the index its two reads use. `Version1000Date20260912000004` adds `social_emoji`, unique on the shortcode — which is both what makes re-adding one a replacement rather than a second row nothing can tell from the first, and the index every read of it uses. `Version1000Date20260912000008` adds `social_collection` and `social_collection_item` — the albums an account curates out of its own posts, the item table unique on (collection, post) so adding one twice is a no-op, with a second index on the post because deleting one has to find every collection holding it. `Version1000Date20260912000009` adds `social_story` and `social_story_view` — a picture that expires after a day and who has seen it, indexed on (account, expiry) for the reads and on expiry alone for the cron that sweeps them, the view table unique on (story, viewer). `Version1000Date20260912000012` adds `social_place` and `social_stream.place_id` — where a post was taken, deduplicated on (name, country) with the name hashed because a unique index on a TEXT column is not portable; no geocoder is involved anywhere, see the migration. `Version1000Date20260914000002` adds `social_list.group_id`, indexed — the Nextcloud group a list follows, which is what `GroupListService` reads when a group changes: every list bound to it, whoever owns it. `Version1000Date20260914000001` adds `social_req_queue.object_id_prim`, indexed — the md5 of the id of the object a queued delivery is about, which is what lets a post ask the queue where it got to; empty on rows queued before the column existed, which are at most a few days of retries. `Version1000Date20260914000005` adds `social_gif` — the shared pictures the composer offers, unique on the slug, with the bytes in appdata beside the custom emoji: it is the same shape of thing, a small curated set served to everybody that must not break because a file moved in somebody's Files. `Version1000Date20260914000004` adds `social_reaction` — who reacted to which post with which emoji — unique on (actor, post, emoji) and indexed on the post, which is the read it exists for. A table of its own rather than another `type` in `social_action`: a like and a boost are a fact about a pair, which is what that table's key says, while a reaction carries a third thing and one account may react to one post several times over. `Version1000Date20260915000001` adds `social_filter_st` — the individual posts a filter covers, beside the keywords it matches, which is the other half of Mastodon's v2 filters and the only part of that API this app did not serve. The post is named by its `nid`, the id a client sends, with an index on the filter (how a filter's entries are read and deleted) and one on the status (not unique: two filters of one account, and two accounts, may each cover the same post). `Version1000Date20260915000002` adds `social_import_post` — what an account has brought over from an export, unique on (account, original id). A post written by the importer is a *new local post*: its original id belongs to the server it was written on and cannot be kept, so without this row nothing would remember where it came from, a second run of the same archive would write every post again, and a reply — which an archive names by its parent's original id — would have nothing to hang off. Nothing cascades: a post the account later deletes leaves its row, because the row says "this was imported" and importing it again because it was deleted here would undo a decision the account made. `Version1000Date20260915000003` adds `source_id`, `source_id_prim` and `local` to `social_story`, unique on the hashed id. A story used to be local by definition — no ActivityPub identity, no recipients — so the row had no way of saying whose network it belonged to; it is published to followers as an `Add` now and arrives from peers the same way. The unique index is what makes a story delivered twice one row, which matters more here than elsewhere because a fan-out reaches an instance once per follower on it, and `local` is what decides which stories are published outward and which may be deleted through the API. Existing rows are local and have their id minted on first read rather than in a `postSchemaChange`: a story lives a day, so within a day of the migration the question has answered itself. `Version1000Date20260915000004` adds `social_post_hold` — the review queue. What it stores is the *request*, the same shape `social_scheduled` holds, and not a post: a held post that existed as a row in `social_stream` with a flag on it would be one forgotten predicate away from a timeline, a hashtag page, a profile or an outbox, and this app has shipped exactly that leak before. A post that is not in the table cannot be read out of it by code nobody has written yet. Unique on `digest`, the md5 of the account and the text: a client told its post was held will be pressed again by its user and the Pixelfed app retries a 422 by itself, so without it one post held once would be twenty identical rows for a moderator to work through. Indexed on (`actor_id_prim`, `id`) for the author's own list and the per-account cap; the queue itself is read in `id` order off the primary key, because it is drained by people. `Version1000Date20260915000005` adds `social_stream.archived` — a post its author has put away. Deleting was the only thing this app offered somebody who no longer wanted a post on their profile, which is a bad answer to a common question: a photograph from four years ago is not something to destroy because it has stopped belonging at the top of a profile. A column rather than a table because it is one fact about one post and every read that must not show one is a read of `social_stream`; no index, because almost every row is `false` and always will be, and the queries that filter it are already selected by their own timeline's index. The filter is **fail-closed**: `StreamRequestBuilder::hideArchived()` is applied by the two base selects every stream read is built from, and the two reads that should see an archived post — the author's own list, and a post fetched by its own address — ask for it. A read written later shows none until somebody decides it should, rather than leaking one until somebody notices. Nothing federates: an archived post is still on every server that received it, and taking it back from them is what `Delete` is for. `Version1000Date20260915000006` adds `social_moderation.force_sensitive` and `social_media_block`. The first is the step between doing nothing and silencing — an account asked to put a content warning on its pictures without being taken out of the timelines, which is Mastodon's own tier and what Pixelfed calls `cw` — applied in `StreamRequest::save()` because a local post, a post that arrived in the inbox and a post the importer restored are the same row and a rule that held for one of them would be a rule nobody could explain. The second is the one thing none of the account-level tools does: stop a **file** coming back. A hash of the bytes as they arrive, checked in `CacheDocumentService::saveFromTempToCache()` — the one place an upload and a fetched remote attachment both pass through — with the reason and the moderator beside it, and a count of how many times it has been turned away, because a blocklist with no evidence is one nobody dares remove anything from a year later. `Version1000Date20260915000007` adds `social_stream_view` — who has opened a post. An author could see three likes and had no way to know whether that was three out of five or three out of four hundred; a story has had a view count since it was written and a post had none. What is counted is deliberately narrow: **a post's own page, opened by a signed-in account that is not its author**. Not an impression in a timeline — a post scrolled past has not been read, counting it would make the number meaningless, and it would write a row for every post on every page of every timeline. Unique on (post, viewer), so the number is people rather than visits. It is never federated and is on the author's copy alone: a count that arrived from another server would be a number about that server's readers added to this one's, meaning neither. `Version1000Date20260915000009` adds `social_story_react` — the reactions and replies a story has been answered with. A story could be watched and nothing else: Pixelfed has three ways of answering one and a verb for each (`View`, `Story:Reaction`, `Story:Reply`), and this app sent and understood none of them, so a story posted to Pixelfed followers came back silent. One table for reactions and replies because they are the same row with a different word on it — who, about which story, what they said — which is how Pixelfed keeps them too. A reply is deliberately **not** a post: no `social_stream` row, so it cannot reach a timeline, a profile or an outbox through a query nobody has written yet, and it is deleted with the story rather than outliving what it was about. Unique on the hash of the activity's own id, which is what makes a retried delivery one row: an inbox is retried, and a reaction counted twice would be two reactions. Indexed on (story, id) for the one read there is and on (story, account) for the per-account cap. Deleting a story now takes its views and its answers with it in one place — `StoriesRequest::deleteRelatedTo()` — which also closes a gap: a story withdrawn by its author with a `Delete` used to leave its view rows behind. `Version1000Date20260915000008` adds `social_discover_cat` — the subjects an instance says it is about. Explore is trending, and trending on a small instance is four hashtags and a wedding, which reads as abandoned rather than as somewhere to start. What an instance would *like* to be known for is a decision its administrators make and not something a counter can arrive at, so this is curated and sits above the counted lists, saying which of the two it is. The hashtags are a JSON array on the row rather than a table of their own: there are a handful of categories on any instance that has them, each naming a handful of tags, nothing joins on them, and the whole set is read at once by the one page that shows it. No index beyond the key, for the same reason. `Version1000Date20260914000003` adds the two columns the actor-cache refresh needed and the index the queue drain was missing: `social_cache_actor.sync_attempt` and `sync_failures` — when this instance last *tried* to refresh a cached remote actor (unix time, 0 for never) and how many attempts in a row have failed since one worked, indexed together with `local`, which is the other predicate of the query that orders on them. Integers rather than a datetime so that "never" is 0 and sorts the same on every database, where a NULL datetime does not. And `social_req_queue (status, priority, tries, last)`: `getStandby()` selects on `status` and orders on the other three, 200 rows at a time, and the only index it had was `(status, id)` — so every drain sorted the whole standby set to pick its window.

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
- **CacheActorService** — Central actor cache/resolver. Looks up actors by ActivityPub id or `user@host`, fetches unknown remote actors over WebFinger + HTTP on a cache miss, and probes their followers/following/outbox counts. The cron refresh (`manageCacheRemoteActors()`, `SYNC_BATCH` of 50 per pass) records every attempt in `social_cache_actor.sync_attempt`/`sync_failures` and takes the oldest attempt first. It used to select on `creation` — which for a remote actor is the `published` date its own instance reports and never moves — with no order and no record of having tried, so fifty rows on a dead instance were the fifty the refresh picked every twelve minutes and no live profile was ever refreshed again. A failure now doubles the wait from an hour (`CacheActorsRequest::syncWait()`, applied both in SQL by `limitToSyncDue()` and by the service) and after `SYNC_MAX_FAILURES` (10, about six weeks) the refresh stops asking. Nothing is deleted by any of that: the row, the follows pointing at it and the posts it wrote stay, an on-demand fetch still goes to the network, and one that works resets the count
- **AccountService::assertHandleAvailable() / linkExternalHandle()** — The setup screen's two answers. `NavigationController::navigate()` no longer creates an actor on the first load: a person without one is handed `needsAccount`, a `suggestedHandle` (`generateHandleFromUserId()`) and the `linkedHandle` their profile already names, and `LocalController::accountCreate()` makes the actor when they ask, with the handle they chose — refused when it is another Nextcloud user's id (one person publishing as another) or an actor already holds it. `accountLink()` writes an account elsewhere into the profile's `fediverse` field, which is where `ColleagueService` looks, and creates nothing
- **CacheActorService** — Central actor cache/resolver. Looks up actors by ActivityPub id or `user@host`, fetches unknown remote actors over WebFinger + HTTP on a cache miss, and probes their followers/following/outbox counts
- **RelationshipService** — blocking and muting: stores the relation, severs follows in both directions on a block, and federates `Block`/`Undo{Block}` unless the `federate_blocks` app setting is `0`. Mutes are purely local and never federated.

### Content & Timelines

- **StreamService** — Core stream/timeline engine. Assigns ActivityPub ids, expands recipients (public/unlisted/followers/direct), resolves reply chains, detects stream types, deletes local items, and reads the timelines (home, local, global/federated, tag, account, liked, direct, notifications). Deleting a post takes what belongs to it with it: `StreamRequest::deleteRelatedTo()` removes the `social_stream_dest`, `social_stream_tag`, `social_stream_act` and `social_stream_card` rows, the `social_action` rows pointing at the post, and its cached attachment rows together with the files on disk. The cascade sits in `StreamRequest`, so every caller gets it rather than retention alone. `syncRemoteTimeline()` — a profile timeline asking the account's own server for its outbox — walks at most `SYNC_ITEM_LIMIT` (20) entries of the page it gets back, and puts every field of each through the same validation helpers the inbox path uses. It used to walk the whole page unbounded and store `url`, `content`, `summary` and the tags unchecked, from a public route, against a host the caller names
- **PostService** — Creates posts (text, attachments, reply-to, mentions, hashtags, the content warning and the `sensitive` flag) and edits existing local posts, delegating federation to `ActivityService`. A visibility a client sent is translated once, in `Post::setType()`: Mastodon calls a followers-only post `private` and this app calls it `followers`, and a visibility the app does not recognise becomes `direct` rather than being addressed to `as:Public`. The content warning is stored as plain text on both paths (`strip_tags()`), because it is read as plain text everywhere it is shown — as the object's `summary`, as `spoiler_text`, and interpolated rather than rendered by this app's own frontend; entity-encoding it federated `Bob&#039;s finale` and baked the entities into the next edit. The body takes the opposite path: it is rendered by `LinkifyService` after the recipients and hashtags are resolved, so the links in the published HTML and the `tag` array come out of one parse
- **PostReviewService** — First-post review and the spam rules: which posts a person should see before anybody else does, and what happens to one afterwards. Two switches, both on by default (`review_first_post`, `autospam`), and a very short list of rules — the first post of an account that has published nothing here, a wall of links in a short post, and mentions scattered by an account nobody follows and that follows nobody. No wordlist and no score: a queue that says "0.82" tells a moderator nothing they can act on. A **direct message is never held**, because putting private correspondence in front of a moderator who was not written to, for a machine's reason, is worse than the spam; neither is a post by an account a moderator has already decided about, which is being dealt with by that decision. What is held is the request — `StatusAssemblyService` turns it back into the `Post` an immediate one would have been, and approving publishes it dated now, since that is when it became a post. Refusing records a `delete_statuses` strike, so the author is told and the next moderator can see it happened
- **StatusAssemblyService** — The post a stored request becomes, and the request a client's `Status` becomes. Shared by the scheduler and the review queue: a note built by one path and federated by another drifts, because the quote approval, the language fallback and the source snapshot all happen inside `PostService::createPost()`
- **ScheduledStatusService** — `POST /api/v1/statuses` with a `scheduled_at`, and the publishing of what it stores. Keeps the client's request rather than a rendered post, because publishing has to go through `PostService::createPost()` over a `Post` assembled exactly as `ApiController::statusNew()` assembles one — a note built by one path and federated by another would drift on the quote approval, the language fallback and the source snapshot. `MIN_LEAD_TIME` (300 s) is Mastodon's five-minute minimum and is also what makes the cron interval workable; `MAX_PENDING` (300) and `MAX_PENDING_PER_DAY` (25) are Mastodon's caps, and are needed because a scheduled post is the only thing a client can store unpublished and unbounded where no moderator can find it. The visibility is resolved at scheduling time so a later change of `source[privacy]` cannot move a waiting post's audience, and everything `createPost()` would refuse — length, an unusable poll — is refused while there is still a client to tell. `publishDue()` claims a row by deleting it before publishing: two workers that read the same due row both try, the database lets one affect it, and only that one posts
- **PollService** — Federated polls: serves the Mastodon Poll entity of a stored `Question` and votes on remote polls (one vote note per choice to the poll's author; the viewer's choices are remembered in the per-viewer stream action, authoritative counts arrive as `Update{Question}`)
- **FollowService** — Follow/unfollow flows, follower/following collections, and relationship lookups
- **LikeService** — Creates and undoes Like activities
- **StreamPruneService** — Retention: deletes remote statuses older than `retention_days` (default 0 = disabled) that no local user interacted with, whose author nobody follows, that no local status replies to or boosts, and that are not DMs — together with their dest/action/tag rows and cached attachments. Runs bounded in the Cache cron and unbounded via `occ social:stream:prune`
- **CacheActorSweepService** — The other half of retention, for accounts rather than posts: evicts the cached remote actors nothing here refers to any more, with their avatars and headers. A row is written the first time this instance meets an account — a like on a local post, a boost seen in a timeline, a reply in a thread — and until this existed nothing removed one but the account's own instance sending a `Delete` (`PersonInterface`) or a moderator purging its domain (`ModerationService`), so a year of federating left tens of thousands of rows and a picture each for accounts nobody here has anything to do with. An actor goes when nobody here follows it, it follows nobody here, no post of its is stored, no follow request or block/mute/endorsement names it either way, and it has been neither seen nor tried for `cache_actor_days` (default 180; 0 disables). Paged like `StreamPruneService` and bounded per cron pass (`Cron\Cache::SWEEP_BATCH`, 500). What is swept is fetched again the moment it is needed, so what is lost is a request and never a relationship or a post. A like or a boost of a local post is deliberately not one of the conditions — the same line `tootctl accounts prune` draws: the `social_action` row stays and names the actor, and the profile is fetched again when the list of who liked the post is opened
- **BoostService** — Creates and undoes Announce (boost/reblog) activities
- **ActionService** — Dispatcher for the Mastodon-style status actions. favourite/unfavourite create and delete a Like, reblog/unreblog an Announce, and bookmark/unbookmark toggle the viewer's local `bookmarked` flag (never federated, served by `/api/v1/bookmarks`), and pin/unpin hand off to `PinService`; `mute`/`unmute` mute the conversation. `translate` is deliberately **not** one of these: it used to be, returning the status unchanged, which a client cannot tell from a translation — it is `ApiController::statusTranslate()` now, answering a Translation entity from a real provider
- **TranslationService** — One status in the reader's language, through whatever translation provider this Nextcloud has (`OCP\TaskProcessing`, the same one Talk, Mail and the assistant use). There is no translation engine in this app and there should not be one. A server with no provider announces `configuration.translation.enabled: false` and the route answers 503; it never hands back the original text, which is what the old stub did and what a reader could not tell from a translation. The body and the content warning are always translated, then poll options and alt texts while `MAX_TEXTS` lasts — each text is a round-trip — and what is past the budget is left out of the entity rather than returned untranslated
- **NotificationGroupService** — Mastodon 4.3's grouped notifications: favourites and boosts of one post, and follows, become one group with a count and a sample of accounts; mentions never group. The `group_key` names what the group *is* (`favourite-{status id}`), never the notifications in it, so it still names the same group after more arrive and a client's dismiss still lands
- **NotificationPolicyService** — The five questions an account may ask about whoever is writing to it (`for_not_following`, `for_not_followers`, `for_new_accounts`, `for_private_mentions`, `for_limited_accounts`), and the requests inbox the held notifications are gathered into, one row per sender. Everything starts at `accept`, and an account that has not touched the policy pays no query for it. `drop` behaves as `filter`: the row is written by the inbox long before anybody reads it, and the policy is applied when the list is read, so a policy loosened next week can still show what it caught this week
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
- **HttpSignatureService** — The one place an outbound HTTP signature is produced. Both entry points take the URL the request will actually be sent to and the body it will actually carry, and answer with the headers to send: `(request-target)` is the path and query of that very URL and `host` its authority, so the signature and the request cannot drift apart. A delivery is signed by its own author over `(request-target) content-length date host digest`; an ActivityPub GET is signed over `(request-target) host date` by `signFetch()`, as the instance's own `Application` actor. Never as a person: the owner of a signing key is dereferenced by every peer that checks it, so a borrowed account would appear in every peer's logs as this instance's reader and a block or suspension of it anywhere would stop every signed fetch from here — and an instance whose users have no Social accounts yet would have nobody to borrow. A key that cannot sign raises rather than sending an empty signature, and an instance that cannot produce one at all fetches unsigned, which is what the request was until now
- **InstanceActorService** — The instance's own actor and its key pair, served at `/actor`, discoverable as `acct:<host>@<host>`. The key pair lives in two app config values rather than in `oc_social_actor`: a row there is a local account — listed by the directory, resolved by webfinger, offered to the client API, counted in the statistics, handed a followers collection and an outbox — and the instance actor is none of those, so every one of those places would have needed a clause excluding it. The private half is sealed with the instance secret the way an actor's is, so a config dump alone is not enough to sign as this server. Generated on first use; when two requests race, both adopt whichever pair was written last, because that is the one the actor document publishes
- **LinkifyService** — The plain text somebody types, turned into the HTML every other implementation publishes: `<p>` paragraphs, `<br />`, and links for URLs, mentions (`u-url mention`) and hashtags (`mention hashtag`, `rel="tag"`). Content used to leave as `nl2br(htmlentities(…))`, and peers render `content` without looking for anything to linkify, so every link, mention and hashtag written here arrived everywhere as dead text. The text is escaped first and markup is only ever built around the escaped pieces — nothing is un-escaped and no markup is assembled by interpolating input. The entities are found once, and that same list is what `PostService` addresses the post from and what `StreamService` builds the `tag` array out of, so the markup can never link somebody the `tag` array does not name — which is the list a receiving instance checks a mention against before it notifies anybody
- **ForwardService** — Inbox forwarding (ActivityPub §7.1.2); see below
- **ProfileLinkVerifier** — The tick next to a profile link: the page a field names is fetched (through the guarded client, http(s) only, no local hosts unless allowed, `MAX_SCAN` of it read) and has to link back to the actor id or profile URL with `rel="me"`. Verdicts live in the cached actor's details (`fields_verified` keyed by the field value, `fields_checked` for when), which `Person::exportAsLocal()` reads as `verified_at`; a verdict stands `RECHECK_SECONDS` (a day) before the page is asked again. Local accounts are checked by `Cron\Cache` (`verifyLocalActors()`, `LOCAL_BATCH` per pass), remote ones with their details refresh in `CacheActorService::manageDetailsRemoteActors()`
- **FeaturedCollection::refresh()** — Reads a remote actor's `featured` collection (the first page of a paged one) with its details refresh and makes the stored pins match: known posts are pinned, an embedded Note by the actor is stored first when this instance does not hold it, pins no longer in the collection come down. Before this an `Add`/`Remove` was applied when it arrived and nothing asked for the pins an account already had
- **PinService** — Pinned posts, and the `Add`/`Remove` that tell the fediverse about one. A pin is not an activity of its own on the wire: what travels names the actor's `featured` collection as its `target`. Without that pair a pin was visible only to a peer that re-read the collection, which nothing prompts it to do — so a pin appeared elsewhere late or never, and an unpin never at all. The row is stored first and a failure to federate is logged rather than raised: the profile here is right either way, and a pin is not worth failing a request over
- **ReportForwardService** — Passes a local report on to the instance that hosts the reported account, as a `Flag`. Anonymised: the activity names this instance's `Application` actor and is signed with its key, so the receiving moderators see the server and not the person who filed it — which is the point, since that person is reporting an account on the instance being told. Delivered inline rather than through `social_req_queue`, because a queued delivery is signed by `HttpSignatureService::signDelivery()` from `oc_social_actor` by the queue row's author, and the instance actor is deliberately not a row there. One report is one POST with a 3-second timeout; a failure costs the forward and nothing else, and `social_report.forwarded` is set only when the remote inbox accepted it
- **DomainPurgeService** — Removes what a blocked instance already sent: its cached accounts, their posts, the follows in both directions, the notifications they caused and the deliveries still queued towards them. A block on its own only ever stopped the *next* request. Bounded (50 accounts per step, every underlying delete already batched, no transaction held open), idempotent (each step asks what of the domain is still stored rather than counting off an offset, so an interrupted purge resumes and a repeat is free) and terminating (a step that deletes nothing stops rather than spins). Reuses `ModerationService::purgeActor()`, so a domain purge detaches exactly what a suspension detaches. What it deletes is gone — unblocking the domain lets the instance reach us again but restores nothing. Matches the exact host, not subdomains, even though `isListed()` widens a block to cover them: refusing traffic from one instance too many is undone by editing the list, and deleting one is not
- **InboxLimiter** — Per-minute rate limits on the inbox routes, one spent before the signature is checked and one after; see [Security](#security)
- **RequestQueueService** — Manages `social_req_queue`: creates entries, hands out the priority entry, and re-offers standby entries once they are due. The `floor(tries^4 / 3)` second backoff and the `MAX_TRIES` (16) give-up are applied by the query (`CoreRequestBuilder::limitToQueueDue()`), and exhausted rows are marked `STATUS_ABANDONED` (8) before the 200-row window is read — filtered in PHP afterwards, the rows of one dead instance permanently occupied that window and starved every other delivery. A delivered row is kept as `STATUS_SUCCESS` and an exhausted one as abandoned for `RETENTION_SECONDS` (seven days), then purged by `purgeFinished()` on every cron pass and by `occ social:stream:prune`; they used to be deleted the moment they finished, which made the queue a to-do list that could never say where a post had got to. Every row carries `object_id_prim`, the md5 of the id of the object inside the activity, read off the JSON in `objectIdPrimOf()` so that forwarded third-party bytes are keyed the same way; `DeliveryService` reads the rows of one object back for the author (`GET /api/v1/statuses/{nid}/delivery`). A row whose delivery fails in a way `ActivityService` does not handle itself — a corrupt signing key, the database going away — is logged and handed back to standby by the caller (`Cron\Queue` and `QueueController`), because it was marked `running` before the attempt: left that way it was never retried, never counted against `MAX_TRIES`, and took the rest of the 200-row batch with it
- **StreamQueueService** — Manages `social_stream_queue`, the inbound side. Two queue types are implemented. `Cache`: for each Note a received stream references (a reply parent, a boosted post), it fetches that Note, caches its author, stores it, and embeds it in the referencing stream's cache — anything that is not a Note, or whose id does not match the URL it was fetched from, is rejected. `LinkPreview`: reads the page a post links to, once, and stores the card. Any other type is dropped. This side has the same 200-row batch cap and the same in-query backoff, give-up (`MAX_TRIES`, 10 here) and delete-on-success as the outbound queue; it used to keep one permanent row per activity ever cached and was never pruned
- **CurlService** — Outbound HTTP for ActivityPub fetches, WebFinger and host-meta lookups, and the async self-call that drains a delivery token. The transport is the server's own client (`OCP\Http\Client\IClientService`), and a caller hands it a method, a URL and at most four options — `headers`, `body`, `timeout`, `json_headers` — which is what the client itself takes; nothing in between describes an HTTP request a second time. So the CA bundle, the proxy configuration and the local-address checks come from the server, and what stays here is federation-specific: the protocol fallback (an instance reachable over `http` only, via `doRequestOverUrls()`), the signed fetch and its one unsigned retry, the download size ceiling, and the mapping onto the app's request exceptions. A URL somebody else wrote — an ActivityPub id, a cached-media link, a previewed page — is requested exactly as it is written rather than taken apart and reassembled, which is also what makes the path a signature covers the path the request is sent to
- **FediverseService** — Instance-level access control; see [Security](#security)
- **InstanceService** — Builds and returns the local instance's NodeInfo-style metadata

### Media

- **DocumentService** — Owns the cached document lifecycle: caching a remote document by id, serving originals and resized copies out of app storage, and caching the local actor's avatar and header. Serving applies a viewer bound: a cached attachment is handed to a logged-in user only if it hangs off a post they may read or is their own upload, and the unauthenticated `/media/{uuid}` route only for a row marked public
- **CacheDocumentService** — Writes uploads, remote downloads and temp files into app storage, filters MIME types against an allow-list, and reads content back out. An image and a video take deliberately different paths: an image is read into a string, because the metadata stripping, the HEIC conversion and the resize all work on one, while a video is streamed to storage a chunk at a time and never held whole — `fread()` of a two-gigabyte upload is two gigabytes of memory, and PHP's limit was the only thing that ever stopped it. That split is also why there are two size ceilings (`max_size`, `max_video_size`) and why the second is applied again against the *sniffed* type: the request-time check can only go on what the client declared. An upload is created non-public; it takes the visibility of the post it is attached to when that post is created (`ApiController::scopeMediaToVisibility()`, public for public and unlisted, non-public otherwise), because which post an upload belongs to is only known then
- **VideoThumbnailService** — One frame out of a video, so a timeline of them is not a wall of black rectangles each of which has to be downloaded before it shows anything. ffmpeg where the server has it, skipped silently where it does not (an app that refused uploads without ffmpeg would be worse than one that shows no poster), and **not transcoding**: it decodes one frame and asks ffprobe how long the video runs. The poster becomes the video's `resized_copy` — which is what that column means, the small image standing in for the file — and from there the player's `poster`, which is what lets a page of videos be scrolled without fetching one. The frame is taken from the bytes as they are written and they are only written once, so a video stored before posters existed (or while the server had no ffmpeg) would never get one: `occ social:media:posters` is the backfill, and it has to do two things rather than one, because a post keeps its own copy of its attachments. Making the poster changes the *document*; rewriting the copies the posts carry is what a reader sees
- **BlurService** — Generates a blurhash string from a GD image
- **AnnouncementService** — The instance-wide notices an admin posts. Two reads that are not the same: a client gets the announcements that apply *now*, each carrying whether that account has dismissed it, and the administration page gets all of them including one that has not started and one that has run out. The window is a predicate of the query, so an announcement starts and stops being served on time on an instance with no working cron — the same rule a timed mute and an expiring filter follow. Dismissal is per account and never hides the announcement: Mastodon keeps serving it and flips `read`. There is no edit route, because changing a notice under the accounts that have already dismissed it is worse than posting a new one
- **DomainBlockService** — Per-account blocks of a whole instance, stored as a domain and applied to the host of an account's actor id. Not `FediverseService`, which is the admin's instance-wide access list. Nothing is federated; the timelines enforce it from inside `filterHiddenActors()`, so a domain block reaches everything a per-account block reaches
- **AccountRelationService** — The private note, the endorsement and the mute expiry of one account towards another, and `decorate()`, which is where `FollowService::generateRelationship()` fills in `domain_blocking`, `note` and a mute that has run out. A timed mute stops applying because the read says so, not because anything ran to delete it
- **StatusRevisionService** — The versions a status has been through. The first edit records the version being replaced as well as the new one, so the first entry of a history is always what was posted
- **StarterPackService** — Named handfuls of accounts worth following, answering the question `SuggestionService` structurally cannot: suggestions work off the follow graph, and a new account has none, so the fallback is whoever posted recently — a list of strangers sorted by luck. A pack is a list of `user@host` handles and nothing else; no table, because the accounts are not this instance's to own and the handles are the only durable reference to them. The index resolves nobody (a handle costs a WebFinger lookup and an actor fetch), so resolution happens only when a pack is opened, and a handle that will not resolve is *reported* rather than dropped — a pack that quietly shrinks looks like one somebody wrote badly. The shipped packs are the official accounts of the projects this app federates with, which is the one editorial line defensible without becoming a directory nobody agreed to be in; the `starter_packs` app value replaces or extends them, and a configured pack whose slug matches a shipped one replaces it
- **DirectoryService / SuggestionService / TrendService / FeaturedTagService** — Discovery. The directory is opt-in through `discoverable`, applied as a predicate of the deciding query rather than as a filter over rows already read; suggestions are two counted facts (friends of friends, then locally active accounts) rather than a scoring model; trends count from the rows a like, a boost and a link preview already write, so a trend cannot drift from the counts a status reports
- **BannerService** — The banner across the top of a profile. Three routes set one — a picked file, a URL, and `header` on `update_credentials` — and all three end in the same work: store the bytes, point the cached actor at them, tell the followers. It is one service so those three cannot drift on the parts that matter, which are the banner being public where an attachment is not, and the `Update{Person}` that is the only reason anybody else ever sees it

**Serving media.** `/media/{uuid}` answers byte ranges (`RangedFileResponse`).
`FileDisplayResponse`, which it used to use, sends the whole file and says
nothing about ranges — fine for a picture and wrong for anything with a timeline
in it: a browser cannot seek a video it can only receive from the beginning, so
the scrub bar does nothing, and asking for the duration alone costs the whole
file. On a page of twenty videos that was twenty full downloads before anybody
had pressed play. The rules are RFC 9110's, and an unparseable range is answered
with the whole file rather than refused, because that is always correct.

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

- **ConfigService** — App/user configuration and the derived URLs (cloud URL, social URL, social address, max download size, self-signed toggle), plus ActivityPub id generation. It also owns the two config-derived parts of every outbound request: `requestOptions()` (the timeout and connect timeout, whether the peer's certificate has to check out, whether local addresses may be reached) and `activityPubHeaders()` (the `Accept` a federation GET carries and the `Content-Type` a POST does). `withRequestTimeout()` bounds everything a call makes, overriding what the caller asked for
- **CheckService** — Installation checks (is `/.well-known/webfinger` reachable) and repair of invalid follow and note rows
- **ClientService** — OAuth 2.0 client registration, authorization and token issuing
- **DetailsService** — Computes a `StreamDetails` object describing which local viewers a stream reaches
- **MiscService** — Logging helper and the running Nextcloud major version
- **ReportService** — Moderation reports: stores what was filed locally over the client API or arrived from a remote instance as a `Flag`, and notifies the instance admins
- **ModerationService** — Acts on a report: silence, suspend, or take one post down. the two levels are described under ActivityPub Federation below
- **GroupListService** — Nextcloud groups as Social lists: every group a person is in becomes a list of theirs, holding the group's members that have a Social account, kept in step three ways — the lists a viewer is missing are made when they ask for their lists (`ensureForViewer()`, from `GET /api/v1/lists`), a group change is applied the moment it happens (`GroupListListener`, on the four `OCP\Group\Events`), and the cron reconciles every group list against its group (`reconcile()`, from `Cron\Cache`) for what neither saw, such as an account created after the lists were. A group larger than `MAX_GROUP_SIZE` (500) gets no list. A group list is a private list like any other — same table, same timeline, same visibility — whose title and membership are the group's rather than the owner's: `ListController` refuses to delete one or edit its members (422) and keeps its title on an update
- **DeliveryService** — What the outbound queue looks like to an author: the rows of one post, counted as delivered / sending / waiting / failing / abandoned and listed per server, the ones that need an eye first. Only as good as the queue's retention, which the answer carries, so an old post reports nothing rather than reporting wrongly
- **FederationHealthService** — What the outbound queue looks like to an administrator: which instances deliveries are failing against, so an instance that has quietly stopped receiving anything is distinguishable from one nobody posted to. Both states, not one: what is still being retried (`getFailing()`, STANDBY with at least one failure) and what has been **given up on** (`getAbandoned()`, `STATUS_ABANDONED`), each grouped per host. The second was reported nowhere — a request on its fifteenth attempt was counted as failing and the moment it was abandoned it left every count, so the queue looked healthiest exactly when a peer had been lost for good. The abandoned figures reach back `RequestQueueService::RETENTION_SECONDS` (seven days), which is how long a finished row is kept; `occ social:queue:status`, the admin settings and the dashboard widget all read this one summary, and `occ social:queue:retry --instance HOST` is what acts on it
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

A story is the one object here that is neither a post nor a collection. It is published to the author's followers as an `Add` whose object is a `Story` and withdrawn with a `Delete` — Pixelfed's verbs, because Pixelfed is the network that has stories — and it resolves at `/@{username}/stories/{id}` for a peer that would rather fetch it than trust the copy it was handed, under the same rule the client API keeps: the author, or a signed reader who follows them. `StoryInterface` takes the incoming side and keeps a story only when somebody here follows its author, for no longer than a day whatever the sender's `expiresAt` says. The published shape is Pixelfed's down to the field names, because Pixelfed is the only network that has stories: its `StoryFetch` reads nothing out of the activity, requiring `object.object` as a **bearcap** (`bear:?t=…&u=…`, FEP-d8c2) that it then fetches with the token, so the `Add` carries the capability beside the fields, `attachment` is one object typed `Image`/`Video` rather than a list, and `published`, `expiresAt` and `can_reply`/`can_react` are all stated. The token is an HMAC of the story's address under an instance secret made on first use — derived rather than stored, because a story lives a day and a row per story to write and expire is a table for a value that can be recomputed — and `ActivityPubController::story()` accepts it as a bearer token beside the signed-follower rule, since a bearcap fetch signs nothing. What cannot be fixed from this side: Pixelfed fans stories out with `FollowerService::softwareAudience($id, 'pixelfed')`, so it delivers them only to instances it has identified as Pixelfed and none of its stories arrive here whatever this app does. A `Create` is deliberately not used: it would put a story where posts go, and a server that does not know the type ignores an `Add` of one, which is the outcome to want.

Every local note carries a `replies` collection at `<post id>/replies`, served paged by `ActivityPub#replies`. A reply reaches the instances that hold the post it answers and nowhere else, so without it a reader on a third instance sees a post with no replies. It lists the ids of the **public** replies only, and remote notes are not given one: their replies live on the server that holds them.

### Outgoing Flow

1. A user action (post, edit, delete, follow, unfollow, like, boost) has a service build the activity
2. `SignatureService::signObject()` adds a Linked Data Signature — for Create, Update, Delete, Like, Announce and their Undos. Follow, Accept, Reject, Block and `Undo{Block}` are **not** LD-signed; they travel with the HTTP signature only
3. `ActivityService::request()` expands the activity's instance paths into concrete target inboxes
4. Targets on this instance are dropped: everyone here already has the item, because recipients are written into `social_stream_dest` when it is saved, which is what puts it in a local timeline. Posting to our own inbox would only hand us back what we wrote, and it is a request the server has to be able to make to its own public address — behind a reverse proxy, split-horizon DNS or an SSRF guard it often cannot, and the delivery then fails its way to being abandoned while remote instances queue up behind it. This holds only for activities whose effect is already applied when the item is saved. A **Follow** has no such path, so `FollowService::followAccount()` detects a local target and runs `FollowInterface::processIncomingRequest()` in process instead — setting the activity's origin to this host first, because that handler is the inbox's and checks it. Without that the row stayed `accepted = 0` for ever and no local follow ever completed
5. `RequestQueueService::generateRequestQueue()` writes one `social_req_queue` row per remaining target
6. At most one row is delivered inline: `RequestQueueService::getPriorityRequest()` hands back the first row only when its priority is `TOP`, or `HIGH`/`MEDIUM` under narrow conditions, and otherwise throws `NoHighPriorityRequestException` so nothing is sent synchronously. If rows remain on standby, `CurlService::asyncWithToken()` fires a request at the app's own `/async/request/{token}` route to drain them
7. `Cron\Queue` (12-minute interval) retries whatever the query says is due, with the backoff above, after returning rows a dead worker left `running` to standby
8. Every delivery is an HTTP POST to the queue row's inbox URI. `SignatureService::signRequest()` is given that URL and the body about to be sent and answers with the signed headers; `CurlService::retrieveJson()` sends both

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

**OAuth: one authorization per account.** `social_client` is the app registration — the name, the redirect URIs, the client id and secret — and nothing else. Everything about *who authorized it* is a row of `social_client_auth`: the code, the token, the scopes granted and the account they were granted to, unique on (client, account). Before that, the app row held one `auth_user_id` and one `token`, so an app registration belonged to exactly one person at a time and the second person to sign in with Elk or Phanpy — which register one app per instance — signed the first one out, silently. Re-authorizing replaces that account's row and nobody else's; revoking takes one authorization, where it used to take the app row's only token and sign out everybody; and the expiry sweep deletes authorizations rather than the whole `social_client` row, which used to take the app's registration with an idle token and make the client register again. A code is spent in the same statement that writes the token, so two requests arriving together cannot both exchange it. Every read joins the app row, so the rest of the app still sees one `SocialClient` carrying both halves.

**Notifications that are not activities.** Six of the nine notification types are an activity somebody sent — a Like, an Announce, a Mention, an Update, a Follow, a follow request — and `Stream::NOTIFICATION_TYPES` maps each to its Mastodon name. The other four are events this instance raises itself, with no ActivityPub verb behind them, so the subtype is this app's own name for the event: `poll` (swept by `PollService::announceClosedPolls()` from the cron, because a poll closes by its end time passing and nothing happens at the moment it does), `status` (the bell on a profile — a `notify` row in `social_actor_relation`, written by `POST /accounts/{id}/follow` with `notify`, and raised for local subscribers only since a remote one is told by their own server), `moderation_warning` (raised beside the Nextcloud notification a strike already sends, because a Mastodon client cannot see that one) and `severed_relationships` (raised by `DomainPurgeService` for the local accounts a block cuts off, counted *before* the purge because afterwards there is nothing left to count).

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

**Setup checks.** Four `OCP\SetupCheck\ISetupCheck` classes in `lib/SetupChecks/`, registered in `Application::register()` and shown in **Administration → Overview**: `WebFingerReachable` (the `CheckService::checkWellKnown()` probe, asked about the oldest live local account rather than about the viewer, who may never have opened Social), `CloudAddressMatches` (the stored `cloud_url` against what the server now reports), `CronRanRecently` (`Cron\Queue`'s last run, read off the job list) and `OutboundQueueNotStuck` (abandoned rows, and standby rows last tried more than a day ago — past anything the retry schedule would wait on purpose). `occ social:check:install` runs the same objects rather than a second copy of the logic, so the console and the settings page cannot drift apart; `--offline` leaves out the one that goes out on the network. Each links to [Admin.md](Admin.md), through `SetupChecks\Docs` so a moved guide is one edit.

**The Server card.** `ServerSettingsService` is the one place that reads and writes the eight instance-wide settings the page can reach — `contact_email`, `extended_description`, `max_size`, `max_video_size`, `inbox_throttle`, `secure_mode`, `publish_blocks`, `allow_self_signed` — and the one place that says what each may be. Every one of them existed as an app config key that only `occ config:app:set` could write, which is where they stayed. `ServerSettingsController` is deliberately *not* delegated: it carries no `AuthorizedAdminSetting`, so a group holding the Social section is refused, and `AdminSettings` does not render the card for one.

**The audit trail.** `AuditService` dispatches `OCP\Log\Audit\CriticalActionPerformedEvent` for the decisions that are worth keeping longer than `social.log` keeps them: suspend, silence, lift, takedown, and an instance going on or off the access list. It is called from `ModerationService` and from `FediverseService::addAddress()`/`removeAddress()` rather than from the controllers, so the settings page, the Mastodon admin API and `occ social:fediverse` all record the same thing. A lift and a takedown also write a `social_strikes` row now (`Strike::LIFT`, `Strike::TAKEDOWN`) naming the acting moderator; both used to be an info line and nothing else. `Strike::COUNTED` is what the browser's strike column counts, and a lift is not in it.

**Moderation.** `social_moderation` holds what the *instance* has decided about an account, as against `social_actor_relation`, which holds what one of its users has. Two levels: `silence` keeps the account reachable for its followers and drops it from the public and global timelines (`StreamRequest::filterSilencedActors()`, a small NOT IN rather than a join, because a moderator acts rarely); `suspend` deletes the account's streams and cached actor, makes `ImportService::parseIncomingRequest()` refuse everything it sends afterwards, and — for a local account — stops it acting at all: posting, editing, boosting, liking and following each ask `ModerationService::assertNotSuspended()` first, so the refusal holds for every entry point rather than for whichever controller was remembered — without that last part a suspension would undo itself the next time the account posted. Lifting removes the record; it cannot undo a deletion, and the admin panel says so before suspending.

**Admin metrics.** `MetricsService` answers Mastodon's three metric shapes — a measure (one number a day over a window), a dimension (the ranked list behind one number) and retention (how much of each month's new accounts is still posting later) — plus the three admin trend routes, which answer exactly what the public ones answer because a trend here is what the counts say and there is no review queue to report on. A key this instance cannot answer is **refused with a 422 naming the ones it can**, never answered with zeroes: most of Mastodon's keys describe a sign-up, an invite system, an email address or a media store this app does not own, and `0` reads as "none", which is a different claim and the one an admin acts on. The window is snapped to whole days and capped at 370, because these are full scans of a date range rather than index probes. The SQL lives in the service in `protected` methods, as `AdminApiService`'s does and for the same reason: what decides which question gets asked is then testable without a database. `active_users` counts accounts that **posted**, not accounts that logged in — this app has no session of its own to count.

**Blocks that are about an address.** Mastodon keeps three lists for this — IP blocks, email-domain blocks, canonical email blocks — and all three exist to police a sign-up. This app has no sign-up: an account is a Nextcloud account and the server decides who gets one. So two of the three are given the only meanings they can honestly have, and the third is not implemented rather than stored and never consulted. An **IP block** at `no_access` is enforced in `AccessBlockMiddleware`, which every request this app serves passes through — "no access" is a statement about the whole app, and a block that held on the inbox but not on the API, or on last month's routes but not on the ones added since, is not what an admin switched on; it answers **403**, so a peer stops redelivering. The two sign-up severities are refused at the API rather than stored. An **email-domain block** is checked once, in `AccountService::createActor()`: whether a Nextcloud account gets a fediverse identity at all, which is the same question Mastodon asks one step earlier and is the only one left that is this app's to answer. A **canonical email block** is a hash of the address of a deleted Mastodon account, kept so the same person cannot sign up again; nothing here holds an account's address after deletion, because the address is not this app's to hold. Ranges are matched on packed bytes (`inet_pton`) rather than on text, which is the only way `::1` and `0:0:0:0:0:0:0:1` are the same address and the only way a prefix that falls inside a byte means anything.

**The timeline switcher.** `TimelineSwitcher.vue` sits above the posts on the three timelines that are the same place seen from three distances — the home timeline (`My Feed`), `timeline` (Local) and `federated` (Global) — and on no others: everywhere else it would be a switch between three places the reader is not. It routes rather than fetching, so `Timeline.vue` and the store go on being the single answer to "which timeline is this". The values are the route's own words rather than the labels, because `timeline` is what the store calls the local one and `federated` the global one, and a second vocabulary in a component that only routes would be one more place for the two to disagree; `home` is the route with no `type` at all, so it is pushed as the bare route rather than as `type: 'home'`, which names a timeline nothing serves. Local and Global have **left the sidebar**: they are scopes of the page the switcher sets rather than places of their own, and two entries that lead to the same list while saying it is somewhere else are two too many. The Home entry stays lit while either is being read — `isActive()` honours a `covers` list on a menu entry, which is the set of `type` params that entry owns — so the sidebar never shows nothing chosen.

It is built from plain buttons rather than from `NcCheckboxRadioSwitch`, for the one thing that component cannot do: a single indicator that *travels* between the three. Three controls that light up tell you where you landed; one pill that slides tells you where you came from, which is what makes a switch feel like a switch. The cost is that the accessibility is hand-written rather than inherited, so it is written in full — a `radiogroup` of `radio` buttons, `aria-checked`, arrow keys in both axes that wrap at the ends, a roving tabindex so the control is one tab stop rather than three, and the focus following the selection the way it does in a radio group. The pill itself is `aria-hidden`: what it shows is already on the options. The track is 42px — 30 for an option, 3 of padding either side — which is a little under a Nextcloud button, because the control labels a timeline rather than competing with it. Every option is `flex: 1 1 0` so all three are as wide as the widest, which is what lets a pill of one third of the track land exactly on one of them whatever the labels translate to; below 500px the labels go and the icons stay. The states are written as `.switcher .switcher__option` rather than as one class because the server styles bare `button` elements and its `button:not(.button-vue, [class^="vs__"]):hover` is the more specific selector — left alone, Nextcloud's hover colour paints over the pill on the option you just chose. The movement — the slide, the overshoot, the kick the chosen icon gives, the turn the globe makes — is all inside `@media (prefers-reduced-motion: reduce)`, which switches every bit of it off.

It chooses between whatever it is given rather than between three timelines it knows about: each option carries a `value`, a label, an icon and the route it stands for, and the component routes and animates. That is why a **profile** carries the same control — Posts, Photos and Videos are the same shape of choice, one account seen three ways rather than three places — and why the words in those routes are the route's own (`timeline`, `federated`, `image`, `video`) rather than the labels beside them.

**New post.** The one thing in the sidebar that is not a place to go, so it is not a row among rows: an `NcButton` in the primary colour across the width of the sidebar, above the places. It carries the app's resting elevation, lifts on hover, presses in, gives its `+` a disc that kicks the way the switcher's icons do, and lets one band of light cross it on the way in — all of it inside `prefers-reduced-motion: reduce`, which switches every bit of it off. It was an `NcAppNavigationItem` with no `to`, which renders `href="#"` and needed a `.prevent` to stop the bare fragment becoming a history entry; a button has nowhere to go by construction. `NcButton` rather than a bare `<button>` because the server's own rules for bare buttons all exclude `.button-vue`: a hand-rolled one has to win an argument with them in every state, and loses the pressed one — Nextcloud's `button:not(.button-vue, [class^="vs__"]):not(:disabled, .primary):not(.app-navigation-entry-button):active` sets the background back to the page colour, and outspecifies anything a single class can say. Its class is `navigation__compose` rather than `new-post`, which the composer card already is.

**Explore.** One `NcAppNavigationItem` with `allowCollapse` holding three
kinds of child: the hashtags the reader follows, their lists, and what the
instance is trending. It replaced two captions that each grew without limit —
somebody who follows forty tags pushed their own feed off a laptop screen —
and, since 0.19.99, the trending section that used to sit above it as a caption
of its own. The three are not equal and the order says so: a followed tag and a
list were *chosen*, a trending tag is merely popular, so trending fills what is
left after the chosen ones have their places (`chooseEntries()` in
`src/utils/explore.js`) and never takes a place from them. A trending tag the
reader already follows is dropped rather than drawn twice under the same name.
How many children fit is measured from the rail rather than assumed:
`measureRail()` reads the space between where the children start and where the
rail ends, takes the row pitch from two consecutive rows (`offsetHeight` misses
the margins), and re-measures on a `ResizeObserver` — so it copes with browser
zoom, a denser theme and an error entry appearing. `entriesThatFit()` is the
answer for the first paint and for jsdom, before there is anything to measure.
The open/closed state is a `localStorage` key, and the entry carries no icon of
its own so that the chevron is the only thing before the word and the children
line up under it.

**The account at the bottom.** The way out of every app in Nextcloud is the thing at the foot of the sidebar with your face on it, so the Social sidebar ends the same way: the **More** menu hangs off the reader's own account — their portrait, and the name they publish under — rather than off the word "More" next to a cog. `NcAppNavigationSettings` renders that cog from a hard-coded path and offers no slot to replace it, so the picture is handed to the stylesheet as `--social-face` and set as the icon box's background with the glyph hidden inside it. The picture comes from the server's own avatar endpoint rather than from the account's `avatar` field, because that endpoint answers for every account — generated initials when nobody has uploaded anything — so the button is never a blank circle, and it is the same face the rest of Nextcloud shows.

The account used to be a row of its own above the footer. It is not one any more, because it would be the same face twice; what took its place is **My profile**, first in the menu behind that face. Moving the button without putting the link back would have left the reader's own profile reachable from nowhere.

The account used to be a row of its own above the footer. It is not one any more, because it would be the same face twice; what took its place is **My profile**, first in the menu behind that face. Moving the button without putting the link back would have left the reader's own profile reachable from nowhere.

**Migration.** A page of the app's own, in the menu behind the account, for taking your data out and putting it back. Nextcloud can already export a whole account with `SocialMigrator` in it, but only if the admin installed the user migration app and only from `occ` or that app's page; taking a copy of what you wrote should not depend on either. `MigrationArchiveService` therefore drives **the same migrator** into a zip a person can download, and reads one back — so what travels, and what deliberately does not (the private key, above all: see the class comment on `SocialMigrator`), is decided in one place for both. `ZipExportDestination` and `ZipImportSource` are the two adapters that make a zip look like the framework's `IExportDestination` and `IImportSource`; they implement what the migrator actually calls and refuse the rest — `copyFolder()` throws rather than quietly producing an archive that claims to hold files it does not. A file added as a stream is copied to a temporary file and handed to `ZipArchive::addFile()` rather than read into a string: what arrives that way is as often a video as an outbox, and `stream_get_contents()` of a two-gigabyte upload is two gigabytes of memory. The file names are the migrator's, so an archive from this page and one from `occ user:export` are interchangeable; the extra `social/export.json` names the app version, the account and the migrator version, and an archive that holds the data but no manifest is read as version 1, which is what the server's own exporter wrote.

The import is **additive**: the profile, follows, blocks, mutes, bookmarks and favourites are restored alongside what is already there, and the posts in `outbox.json` are reported rather than re-published, so importing cannot flood the timelines of people who follow you. An archive with no `social/actor.json` is refused by name, because the likeliest mistake is picking the wrong zip.

The third part of the page is about arriving from somewhere else, and it is deliberately honest about what that means: what travels between servers is the **list of people you follow**, because a follow is a relationship two servers agree on rather than a row in a file. `POST /api/v1/migration/follows` re-follows each handle in a `following_accounts.csv` — the file Mastodon, Pixelfed, GoToSocial and Akkoma all export, and the one in this app's own archive — through the ordinary follow path. The networks that do not federate are named as such rather than promised. Moving a whole account is not a button: a `Move` federates to every server that knows you and cannot be undone, so the page points at `occ social:account:alias` and `occ social:account:move`.

The fourth part brings the **posts**, which is the thing a `Move` has never carried, and `PostImportService` is where the rules of that live: each post is written as a *new local post* of the importing account (`StreamRequest::save()`, never the delivery path — not one request is queued, because re-publishing somebody's five years of posts would put five years of posts into every follower's timeline in one afternoon), dated when it was written, with its pictures through the ordinary upload path so an imported picture is stripped of its metadata like any other. The original id is remembered in `social_import_post`, which makes a second run of the same file a no-op and lets a reply find its parent. Five shapes are read: this app's archive, Mastodon's and GoToSocial's, a bare `outbox.json`, Pixelfed's `pixelfed-statuses.json`, and **Instagram's** "Download your information" — the one most people arrive at Pixelfed by. Instagram's archive has no ids, no visibility and no hashtags as data, so an id is derived from the file a post carries, the importing account's **own default visibility** is used for every post in the run, and the hashtags are read out of the caption; its captions are mojibake by construction (the exporter escapes each UTF-8 byte as a character) and are converted back, guarded so that a caption genuinely containing an accented character is left alone. `stories.json`, `archived_posts.json` and `recently_deleted_content.json` are deliberately not read: an importer that quietly republished what somebody put away would be worse than one that imported nothing.

The routes are **session routes with CSRF**, not client-API ones: an archive of everything an account ever wrote is not something a third-party token should be able to ask for. The export answers with a `DataDisplayResponse` whose `Content-Disposition` is set after construction — `DataDownloadResponse` builds that header through Symfony's `HeaderUtils`, a class the server has and this app does not depend on, so the download would work on a server and be untestable here.

**Writing a post's recipients once.** A post names the same account more than once as a matter of course: `Item::getToAll()` returns `to` alongside `toArray`, the author is appended to the `to` side, and an account addressed in both `to` and `cc` appears in each. The unique index `sat` is on `(stream_id, actor_id, type)` *without* the subtype, so every one of those is the same row. They used to be sent to the database one at a time and refused there. `insertIgnoreConflict()` keeps a refusal from failing the transaction the save runs in, but InnoDB allocates the auto-increment value before it notices the conflict, so each duplicate burned an id and dirtied the index that was about to reject it — on a development instance `social_stream_dest` had reached 882,837 ids for 4,976 live rows, with a 20 MB index over 1 MB of data. `StreamDestRequest::uniqueRecipients()` now names each account once before any of them is written, and the first subtype to name an account wins, which is the row the database kept when the duplicates were still being sent.

**Reading a timeline in two queries.** A page is chosen as a list of `nid`s and the rows are fetched afterwards by id — `getStreamNidsSelectSql()` then `streamsByNids()`. The reason is the recipient join: a post can match `social_stream_dest` more than once, so the page query needs `SELECT DISTINCT`, and a `DISTINCT` over the full stream column set makes the database sort or hash several kilobytes a row to deduplicate integers. Two rules keep that honest. First, a page query **joins** the cached actor without selecting it (`joinCacheActors()`), because it only needs the author to constrain on, never to read. Second, `DISTINCT` is asked for only where a page can actually duplicate: `social_stream_dest` is unique on `(stream_id, actor_id, type)`, so a query that fixes the actor and the type — public, notifications, direct messages, the marked timelines — cannot match a post twice and selects without it. The home timeline does duplicate, by design, and keeps it. `getNidsFromRequest()` also deduplicates the twenty integers in PHP, which covers the one case the unique index does not: the left join on expired mutes can match twice for a viewer who timed-muted both a booster and the account they boosted.

**A domain block is not a join.** The viewer's blocked instances are read once per request (`CoreRequestBuilder::blockedDomainsOf()`, memoised and dropped when `DomainBlockService` writes one) and compared as constants in the `WHERE`. It used to be a `LEFT JOIN` against the block table whose `ON` clause held four `LIKE`s against `LOWER(attributed_to)`, evaluated for every candidate row of every timeline read — including for the overwhelming majority of accounts, who have blocked nothing and now add no clause at all. The patterns are still `domainPatterns()`, so what a block matches is unchanged: the exact host, both schemes, closed by the `/` that ends it. A subdomain is a separate block, which is what `DomainBlockTimelineTest` pins — the silenced-instance filter next door reads subdomains, and the two are easy to confuse.

**The moderation panel.** The Social section of the administration settings is one Vue application (`src/adminSettings.js`, components in `src/components/admin/`), built out of the same `NcSettingsSection`, `NcTextField`, `NcSelect`, `NcCheckboxRadioSwitch`, `NcButton`, `NcNoteCard` and `NcDialog` components as the rest of the administration settings. `templates/settings/admin.php` is the element it mounts on and nothing else; what the server knows when it renders — the first page of the open reports, the resolved count, the federation summary, the access list, the retention window and, for an administrator proper, the Server card — travels as initial state from `AdminSettings::getForm()`, in the same shape the routes answer in, so a row that arrived with the page and one fetched afterwards are the same thing to the table that draws them. It was three hand-written plain-DOM scripts, on the reasoning that mounting Vue on a server-rendered page would pull the runtime into a page that had none; the shared `social-framework` chunk ended that, and the page had stopped looking like the rest of the administration settings. The account browser reads `GET /moderation/accounts`, which is `AdminApiService::accountPage()` — the same read the Mastodon admin API answers — narrowed to the six fields a table draws; before it, only a *reported* account could be acted on from the web, and everything else needed a moderation client and a token. Nothing on the page uses `v-html`, and `tests/js/components/admin/AdminSettings.test.js` pins that: a handle, an instance name and the comment on a report are whatever a remote server sent, and this is the page whose buttons delete accounts.

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

### PeerTube and federated video

A `Video` is one of the note-like types in `AP::NOTE_LIKE_TYPES` — object types
other servers `Create` into a timeline that this app has no model of its own for
— and like the rest of them it is stored as a `Note` carrying its wire type in
`subtype`. That is what makes it storable, queryable and readable by a Mastodon
client without a second kind of post existing anywhere downstream.

It is the one of the five that is read in detail, because it is the one whose
whole point is a file to play. PeerTube writes four things where an ordinary
`Note` does not look, and `PeerTubeService` is where each is read:

- **`url` is a list**, not a string: the watch page (`text/html`), one link per
  transcoded resolution (`video/mp4`), the HLS playlist
  (`application/x-mpegURL`), a torrent and a magnet URI. The best playable
  file wins — `video/mp4` up to 1080p, by height — and the playlist is taken
  only when there is no file at all, since Safari is the only browser that
  opens one. `magnet:` and the `rel: ["metadata"]` links are not something to
  hand a `<video>` and are dropped. The list is also **nested**, and that is
  not decoration: an instance transcoding to HLS — the default, and what a
  public PeerTube actually federates — publishes *one* top-level link, the
  playlist, and hangs the playable file for each resolution off that link's
  `tag`. Reading only the top level found a playlist and nothing else on the
  majority of real videos, so `tag` is walked as well.
- **`attributedTo` is a list of two actors**, the channel (a `Group`) and the
  account behind it (a `Person`), where every other server sends one id as a
  string. The channel wins: it is what the `Create` is signed by, what a reader
  follows, and what the video is listed under on PeerTube itself. `Stream::import()`
  asks for a string and got neither, so a federated video used to arrive
  attributed to nobody.
- **The title is in `name`**, which a `Note` has no use for — and must not be
  copied into, since `name` on a note means the option a poll vote chose. So the
  title becomes the first paragraph of the content, linked to the watch page.
- **The description is markdown**, and the object says so in its own
  `mediaType`. It is escaped and paragraph-split when the object declares
  `text/markdown` or `text/plain`, and passed through as html otherwise, which
  is what every other object's `content` is. Believing the declaration in both
  directions is the point: escaping html would show somebody their own tags, and
  rendering markdown as html would hand a remote server a way to put markup in a
  post that went through no sanitiser.

  The little of markdown a description actually uses — links, bare urls, bold
  and italic — is then rendered, because left alone it reads as asterisks and
  brackets in the middle of a timeline (which is what Mastodon shows). The
  order is the safety: the text is escaped *first*, so every tag in the result
  is one this app wrote, and a link is only made of an `http(s)` target.
  Headings, lists and code fences are deliberately not handled — rare in a
  video description, and each one a way to get this wrong.

A document arriving a **second** time — a redelivery, an `Update` of the post it
hangs off — describes a file on somebody else's server and knows nothing about
the copy this instance made of it. Written as it arrived it *cleared*
`local_copy` and `resized_copy`, orphaning the cached file and breaking every
post that showed the picture until the caching cron happened to fetch it again;
`DocumentInterface::keepWhatOnlyTheRowKnows()` moves the stored copies and the
row's key onto the incoming document first. That is a bug older than video —
every re-delivered Mastodon picture hit it — but a streamed row depends on it
twice over, since the key is what the media proxy is addressed by.

**The video is referenced, not mirrored.** Every other attachment is copied into
this instance's storage on the way in; a two-hour talk is not, and the row that
represents it carries `Document::COPY_STREAMED` in `local_copy` instead of a
uuid. That sentinel does two jobs: `DocumentInterface::save()` skips the fetch,
and the caching cron never picks the row up, because
`getNotCachedDocuments()` only looks at rows whose `local_copy` is empty. The
**thumbnail** is a second, ordinary document row — it is a few dozen kilobytes
and it is mirrored, which is what lets a video timeline be scrolled without
touching another server. Two rows rather than one: hanging the still off the
video row's `resized_copy` would have put one uuid on two rows, and
`getByCopy()` would answer with whichever the database felt like.

The attachment's url is rebuilt for the instance a reader is actually on, the
way the uuid links are (`MediaAttachment::onThisInstance()`): it names a cache
row rather than a copy, but it was written under whichever `overwrite.cli.url`
the inbox request ran under, which on many instances is not the address anybody
browses.

Playing it goes through **`GET /media/stream/{nid}`** (`ApiController::mediaStream()`),
which opens the origin and copies it to the reader a chunk at a time, storing
nothing. It exists because the page cannot point a `<video>` at the origin
directly — Nextcloud's content security policy says `media-src 'self'` — and
because widening that policy would also mean every reader who pressed play
announcing themselves to a server they never chose to talk to. The cost is that
this instance carries the bandwidth. What keeps the route from being an open
proxy is that it takes a **row id, not a url**: only a `social_cache_doc` row
this app itself wrote as streamed answers, and the request still goes out
through `CurlService`, so the domain access list and the local-address refusal
apply as they do to every other outbound request. The reader's `Range` header is
forwarded and the origin's `206` comes back untouched, which is what makes
seeking in a long video cost nothing.

**Publishing one.** The other direction is the same shape written rather than
read, and it lives in the same class so the two halves cannot drift:
`PeerTubeService::asVideo()`. A **local** post whose attachments are exactly one
video is serialised as a `Video` — `name` (a title, derived; see below),
`duration` in the xsd form, `icon` for the poster, and `url` as the link list
with the web page and the file. Only the serialisation changes; the row stays a
`Note`, exactly as an incoming `Video` is stored as one.

Three things about it worth knowing:

- **`attachment` is published as well.** A `Video` carries its file in `url` and
  has no need of it, but every Mastodon-family server reads `attachment` and
  nothing else, and these posts rendered there with an inline player before any
  of this existed. Publishing both costs a few hundred bytes and is the
  difference between gaining PeerTube and trading Mastodon for it.
- **The title is derived**, because a `Note` has none and this app does not ask
  for one: the first line of the post, then the video's alt text, then the word
  `Video`. (`name` on a Note means the option a poll vote chose — see
  `PollService::handleIncomingVote` — so nothing reads *that*.) An explicit
  title field is the obvious next step and is not here yet.
- **There is one rendition**, because this app does not transcode: the file is
  whatever was uploaded. A shorter list than PeerTube publishes, the same shape,
  and a reader takes the best playable link it finds.

It is on by default and an admin can turn it off with
`occ config:app:set social publish_video_objects --value 0`. The switch exists
because the one thing that cannot be proven from here is whether a
Mastodon-family server renders a `Video` as well as it rendered the `Note`; what
*is* proven is the round trip — everything published goes back through the
reader in the same class, in `PeerTubePublishTest`.

What is **not** done: `Audio` (Funkwhale), `Article`, `Page` and `Event` are
still read by `fillNoteLikeContent()` alone — title and link, no media. Nor are
there channels: a `Video` is attributed to the author's `Person`, where PeerTube
sends the `[Person, Group]` pair. Both are valid ActivityPub; only the second is
what PeerTube itself would send.

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

`webpack.common.js` defines these entries; the Nextcloud webpack preset prefixes the output with the app id, so they land in `js/` as:

| Bundle | Source | Loaded by |
|--------|--------|-----------|
| `social-social.js` | `src/main.js` | `templates/main.php` — the main SPA |
| `social-dashboard.js` | `src/dashboard.js` | `SocialWidget::load()` |
| `social-oauth.js` | `src/oauth.js` | `templates/oauth2.php` |
| `social-profilePage.js` | `src/profile.js` | `ProfileSectionListener` |
| `social-ostatus.js` | `src/ostatus.js` | nothing — no `addScript()` call references it |
| `social-filesAction.js` | `src/filesAction.js` | `FilesScriptsListener`, on the Files app's `LoadAdditionalScriptsEvent` — **alone**, see below |
| `social-adminSettings.js` | `src/adminSettings.js` | `AdminSettings::getForm()` — Administration → Social |

The OStatus bundle and `src/views/OStatus.vue` are therefore dead code today: `OStatusController::subscribe()` and `followRemote()` both render the `main` template, so remote-follow lands in the main SPA on its `/ostatus/follow` route.

None of those entries is self-contained. Vue, `@nextcloud/vue` and pinia used to be compiled into each of them, so opening the Dashboard and then the app downloaded the framework twice — 276 KB and then 347 KB gzipped, most of it the same bytes. The `framework` cache group in `webpack.common.js` puts what more than one entry needs into `social-framework.js`, which they share; `minChunks: 2` leaves a library only one entry uses inside that entry, so the single-page reader pays a few KB rather than the union. Gzipped: the app alone goes 347 KB → 354 KB, the Dashboard alone 276 KB → 306 KB, the Dashboard and then the app 623 KB → 368 KB, and adding a profile page after that 949 KB → 429 KB.

**Every `Util::addScript()` for this app therefore loads `social-framework` before the entry** — with one deliberate exception: `social-filesAction.js` registers "Share to Social" in the Files app and is loaded on every Files page, most of which will never post anything, so it is excluded from the `framework` cache group (`SELF_CONTAINED` in `webpack.common.js`), carries its few KB of `@nextcloud/files` and l10n itself, and is loaded by `FilesScriptsListener` with `addInitScript()` and nothing before it. It hands the picked paths to the app as `?attach=` query parameters; `Navigation.vue` opens the New post dialog with them and takes them off the address, and the composer attaches them through the same `attachPaths()` its own picker uses. The `overrides` entry for `@nextcloud/vue` in `package.json` exists for this dependency: `@nextcloud/vue` 9.12 optionally peers on a `@nextcloud/files` pre-release, and without the override `npm ci` refuses the 4.0.0 the server itself ships. Getting that wrong fails silently rather than loudly: webpack's runtime queues the startup module waiting for a chunk that never arrives, so the script runs to completion, nothing is thrown, nothing reaches the console, and the page simply stays empty. `tests/js/bundles.test.js` boots the built bundles in a jsdom window to pin it — an entry served alone injects no stylesheets and does nothing, and served after the framework it starts — and checks each of the five `addScript()` sites for the order.

`optimization.concatenateModules` stays `false`. Scope hoisting is worth about a kilobyte and makes the build irreproducible: two runs over identical source emit alternating Terser manglings, and CI compares the committed bundle against a fresh build.

### Store

`src/store/` holds six Pinia stores — `timeline`, `account`, `settings`, `errors`, `notifications` and `instance` — and `index.js` creates the Pinia every entry point installs. Components reach them through `mapStores`, or through a composable where the same few values are wanted together: `useServerData`, `useCurrentUser` and `useAccount` in `src/composables/` replaced the three mixins the app used to carry.

Server-side state is not a store: it is passed through Nextcloud's initial state as `serverData` and read by `useServerData`.

The reader's own account travels the same way. `NavigationController::provideViewerAccount()` puts the cached actor — the one `GET /api/v1/global/account/info` answers with, in the same export format — into the initial state as `currentAccount`, and `App.vue` seeds the account store from it. The app used to ask for it in `beforeMount()`, which cost every load a second authenticated round trip before anything could render, for something the page request was already holding. A page rendered before the account exists provides nothing, and the app asks the old way.

The Mastodon and ActivityPub entities the app exchanges are described as JSDoc
typedefs in `src/types/`, and `npm run typecheck` holds the stores, services and
utilities to them (`jsconfig.json`). Single-file components are outside that
check: `tsc` cannot resolve a `.vue` import without `vue-tsc`.

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

`src/components/` holds the timeline and profile UI: `TimelineList`, `TimelineEntry`, `TimelinePost`, `TimelineAvatar`, `ActorAvatar`, `ProfileInfo`, `FollowButton`, `UserEntry`, `Navigation`, `Search`, `FirstRun` (the four-step introduction a new account sees once, in place of the beta banner: the address, the colleagues and starter packs from the same routes Discover reads, the follows import Settings offers, and a hand-off to the composer), `FirstPostCelebration`, `MediaAttachment`, `PostAttachment`, `Emoji`, `EmptyContent`, `QuotedPost`, `HashtagFollowButton`, `HashtagFollowedList`, the `Gallery` group (`GalleryCarousel`, `GalleryMedia`, `GalleryRatio.js`), the `Composer/` group (`Composer`, `PreviewGrid`, `PreviewGridItem`, `SubmitStatusButton`, `LanguageSelect`), `ScheduledPosts` (the posts waiting to go out, in Settings), the `Visibility/` group (`VisibilitySelect`, `VisibilityIcon`), and `MessageContent.js`, a render-function component that parses a post body and rebuilds it as Vue nodes (turning mentions and hashtags into `router-link`s and emoji into `Emoji` components). AltBadge`, `Emoji`, `EmptyContent`, `QuotedPost`, `HashtagFollowButton`, `HashtagFollowedList`, the `Gallery` group (`GalleryCarousel`, `GalleryMedia`, `GalleryRatio.js`), the `Composer/` group (`Composer`, `PreviewGrid`, `PreviewGridItem`, `SubmitStatusButton`), the `Visibility/` group (`VisibilitySelect`, `VisibilityIcon`), and `MessageContent.js`, a render-function component that parses a post body and rebuilds it as Vue nodes (turning mentions and hashtags into `router-link`s and emoji into `Emoji` components).
`src/components/` holds the timeline and profile UI: `TimelineList`, `TimelineEntry`, `TimelinePost`, `TimelineAvatar`, `ActorAvatar`, `ProfileInfo`, `FollowButton`, `UserEntry`, `Navigation`, `Search`, `FirstRun` (the four-step introduction a new account sees once, in place of the beta banner: the address, the colleagues and starter packs from the same routes Discover reads, the follows import Settings offers, and a hand-off to the composer), `FirstPostCelebration`, `MediaAttachment`, `PostAttachment`, `Emoji`, `EmptyContent`, `QuotedPost`, `HashtagFollowButton`, `HashtagFollowedList`, the `Gallery` group (`GalleryCarousel`, `GalleryMedia`, `GalleryRatio.js`), the `Composer/` group (`Composer`, `PreviewGrid`, `PreviewGridItem`, `SubmitStatusButton`), the `Visibility/` group (`VisibilitySelect`, `VisibilityIcon`), the settings sections (`AccountSettings`, `ListsSettings`, `MigrationSettings`, `ShortcutList`) and the two account dialogs (`MuteDialog`, `ListMembershipDialog`), and `MessageContent.js`, a render-function component that parses a post body and rebuilds it as Vue nodes (turning mentions and hashtags into `router-link`s and emoji into `Emoji` components).

`ProfileInfo.vue` keeps every control for the profile in one dialog: the banner
(a file, or the address of one), the bio and the metadata fields. The banner
buttons used to float over the picture on the owner's own profile, which put
editing chrome on a page whose job is to show somebody. Applying a banner from a
URL leaves the dialog open, because the bio and the fields may still be being
edited.

Each field row in that dialog carries its verdict, because the one thing that
makes a field worth filling in is invisible otherwise: a value naming a web page
is verified by `ProfileLinkVerifier` fetching the page and looking for a link
back with `rel="me"`, and nobody finds that out by accident. The row says
verified and when, or not verified, or — for a bare `example.org` — that nothing
written that way can ever be verified; and as soon as any row is an address the
dialog shows the exact anchor to paste on the far end, with a copy button, and
what the check will and will not do. The verdicts come from the entity's own
`fields` on `verify_credentials`, never from `source.fields`, which is the
editable copy and carries no `verified_at`, and they are keyed by the value they
were made about, which is why editing a value drops its tick there and then.
`fieldLink()` in `src/utils/profileFields.js` is the client's copy of the
plain-text half of `ProfileLinkVerifier::linkOf()`, so the dialog offers a tick
for exactly the values the server would try to verify.

The verdicts themselves live on the **cached** actor's details, which is what
made them fragile: a local actor is rebuilt from the `actors` row, which has no
details column, so `ActorService::cacheLocalActor()` writing `details` whole
erased `fields_verified` on every write to a profile — a new bio, one flag, one
edited field — and the ticks came back only when the next `Cron\Cache` pass
re-fetched every linked page. It now carries the verdicts across, and only those:
`fields_checked` is still dropped, which is what makes that next pass look at an
edited value at once rather than waiting out `RECHECK_SECONDS`, and a verdict
whose value is no longer among the fields is dropped with it.

`Composer.vue` grows a second attach control beside the paperclip: the
`@nextcloud/dialogs` file picker, so a picture already in the user's Nextcloud
goes straight to `POST /api/v1/media/from-file` instead of being downloaded and
uploaded back. Both sources fill the same attachment map and share one ceiling,
the server's (see "What the server's limits are" below), and with anything attached the preview grid moves above the text box —
in the DOM, so the tab order follows the eye — and the box becomes a caption
field. A picture with no alt text is marked as such on its own thumbnail, and a
description is saved on leaving the field rather than only when the post goes
out, so it survives a post that is never sent.

**What a post says about itself.** Three controls in the same toolbar row as
the visibility menu, each of them a field the client API has always taken and
the composer never sent.

`LanguageSelect.vue` is an icon-sized `NcActions` wearing the current code,
defaulting to the reader's Nextcloud language without its region
(`src/utils/postLanguage.js`: `getLanguage()` says how the interface is
spelled, `de-DE`, while Mastodon's per-language filters are keyed by the plain
`de`) and remembering the last choice in `localStorage` the way the visibility
menu does. It is sent as `language` on every post: the server would fill in the
same default (`PostService::languageFor()`), but a guess the poster can see is
one they can correct, and a post that federates with `language: null` is seen
neither by the readers who filter for a language nor by the ones who filter one
out. The languages on offer are the ones Nextcloud itself is translated into,
named at run time by `Intl.DisplayNames` so the list carries no names to
translate.

**Scheduling** is the clock beside the poll button. `NcDateTimePicker` is a
date library and its locales, so it is a `defineAsyncComponent` fetched when
the clock is pressed rather than with every composer — the same treatment the
emoji picker gets. Pressing it proposes an hour from now rounded to five
minutes; the minimum is five minutes out, which is `ScheduledStatusService`'s
`MIN_LEAD_TIME`, checked here as well so the refusal arrives while the time can
still be moved. With a time set the Post button reads **Schedule**, the payload
carries `scheduled_at` as ISO 8601 in UTC, and the answer is a `ScheduledStatus`
rather than a `Status`: nothing is on a timeline yet, so the composer refreshes
nothing, celebrates nothing, and says when the post will go out instead.
`ScheduledPosts.vue` lists what is waiting, in a section of Settings rather
than a page of its own — the list is short (an account may hold 300), what is
done with an entry is one thing, and a `post-scheduled` event on the bus keeps
it in step with the dialog that opens over it. Moving one to another time is
left to the API.

**The focal point** is the crosshair on a thumbnail in `PreviewGridItem.vue`,
beside the alt text and saved by the same `PUT /api/v1/media/{id}`. The whole
picture becomes the control while the point is being set — pressed, dragged or
moved by the arrow keys, which is the only way to set one without a pointer —
and a badge on the thumbnail says a point is set once the editor is closed.
`src/utils/focalPoint.js` holds the three conversions in one place: the browser
measures a pointer from the top left with y pointing down, Mastodon reads a
point from the centre with y pointing *up*, and CSS wants two percentages from
the top left again. `ProfileMediaGrid.vue` crops its tiles through the same
function, so the editor and the grid cannot disagree about which way is up.

**A reply reaches the conversation.** `prefillMessageWithMentions()` starts a
reply with a pill per participant — the author of the post being answered, then
everyone that post mentioned, deduped by full handle and never the reader
themselves. It used to insert the author alone, so an answer in a conversation
of three reached one of three: the server turns the handles in the text into
recipients, `Mention` tags and inboxes (`PostService::fixRecipientAndHashtags()`
into `StreamService::addRecipient()`), and it can only address the people the
text names.
**The notifications page.** `/timeline/notifications` is the ordinary
`TimelineList` with three things of its own, all of them in the client so that
the API stays the shape every Mastodon client expects.

*The filter row* is `TimelineSwitcher` again, with no `to` on any option, so the
choice is the page's own state: `src/services/notifications.js` holds the seven
filters and `excludeTypesFor()` turns one into the `exclude_types` the server
takes (`ApiController::notifications()`). The choice is part of the store's
`params`, so it is part of `getTimelineIdentity` — changing it refetches rather
than hiding rows already on screen — and `rememberedFilter()`/`rememberFilter()`
keep it in `localStorage` under `social.notificationsFilter`, inside a
`try`/`catch` because reading it throws in a private window.

*Grouping* is `groupNotifications()` in the same service: consecutive favourites
or boosts of one status, and consecutive follows, become one card carrying
`accounts` (everyone in it) and `ids` (every row it stands for). Only
consecutive ones — folding across something that happened in between would
reorder what happened. `TimelineEntry` draws up to `GROUP_FACES` overlapping
avatars and `notificationSummary()` writes "Anna, Bob and 3 others liked your
post"; `newestIdOf()` is what keeps the read marker covering every folded row.

*Unread* has one rule: **the page is read once it has been in front of the
reader for `SEEN_AFTER` (two seconds) with the tab visible**, and it is read up
to the newest card on it at that moment. It used to be read by an
`immediate: true` watcher, i.e. by rendering — and the marker is the
server-side Mastodon one every client shares, so a tab opened in the background
cleared the reader's phone for notifications no human had seen. The "New" and
"Earlier" headings are drawn against `notificationsStore.fetchLastRead()`
(`GET /api/v1/markers`), read once when the page opens and then frozen in the
component: a boundary that followed the marker would rub itself out as the page
was read.

**What the server's limits are, and who asks.** `MAX_LENGTH` and
`MAX_ATTACHMENTS` were hard-coded in `Composer.vue`, `TimelinePost.vue` and
`src/filesAction.js` — the server's numbers on the day they were typed, and
nothing kept them so. `src/services/instanceLimits.js` reads them from
`GET /api/v1/instance` (`configuration.statuses.max_characters`,
`max_media_attachments`), once per page, with the old constants as the fallback
a failure leaves standing. It is framework-free — no Vue, no Pinia, no axios —
because the Files action is loaded on every Files page without any of them;
`src/store/instance.js` is the reactive face of it for the components.

**What waits for the timeline.** Every page used to fire four requests as it
mounted — the timeline, `trends/tags`, `lists` and `notifications/unread_count`
— and on a small server they contended for PHP workers: 2.3 to 3.4 seconds each
in parallel where the timeline alone takes 0.4. `src/services/boot.js` is the
one place that knows about it: the timeline store calls `noteTimelineRequest()`
with every request it sends and the first one on the page is the one everything
else yields to, while `afterFirstTimeline()` runs its callback once that has
settled — or, on a page that sends no timeline request at all, in the first idle
slot after the first paint. The decision is taken in that idle slot rather than
at mount, because the sidebar mounts before the timeline below it and at mount
time there is nothing yet to yield to. `Navigation.vue` and the instance store
are the callers.

**The ALT badge.** `AltBadge.vue`: a badge in the corner of a thumbnail that
carries a description, and the description under it when it is pressed. It
renders two roots (the button and the text) so that both position themselves
against whatever frame holds them, which therefore has to be
`position: relative` — `.photo` in `GalleryMedia.vue`, `.attachment-frame` in
`PostAttachment.vue`. It was `GalleryMedia`'s own markup and so appeared on the
media-first mosaic only; the same picture in an ordinary two-up card had its
description in an `alt` attribute and nowhere else.

**Phone layout.** One breakpoint, 600px, stated twice on purpose: as `PHONE_WIDTH` in `src/services/phone.js` (a shared `matchMedia` query with `isPhone()` and `onPhoneChange()`) and as the `@media (max-width: 600px)` rule in the stylesheets that lay themselves out differently on a phone — `TimelineEntry.vue` (the avatar column goes; the face, 36px, sits inside the card over the corner `.post-header` leaves for it, which is why `TimelineAvatar` takes a `size`), `TimelinePost.vue` (less padding), `TimelineSinglePost.vue` (the 64px the fine print and the spine kept for the avatar column), and `Composer.vue` (the toolbar wraps, the visibility menu is icon-only, Post keeps the end of its row). Nextcloud's own mobile breakpoint, 1024px, is where the sidebar collapses; the only rule at that width is `Timeline.vue`'s, which starts the page's first element below the sidebar toggle. A tablet in portrait is between the two and keeps the avatar column.

`Composer.vue` carries a full `tributeOptions` config for `@` account and `#` hashtag completion. `tributejs` is a plain DOM library rather than a component: it is attached to the contenteditable in `mounted()` and detached in `unmounted()`, and it appends its menu to the body, which the unscoped `.tribute-container` rule at the end of the file styles. The account collection searches `/api/v1/global/accounts/search` and the hashtag collection `/api/v1/global/tags/search`, both debounced. The emoji picker is a separate `NcEmojiPicker`.

**The Settings page, and what is on it.** `src/views/Settings.vue` is a list of
sections, each with an id — `#account`, `#lists`, `#shortcuts`, `#migration` —
because other pages link to one of them: the Follow requests page's empty state
sends the reader to `#account` for the switch it talks about. The two large
sections are `defineAsyncComponent` imports in a `settings` chunk, since nobody
loads them until they open the page, and a section that arrives after the page
did is why the scroll to the hash is retried in `updated()`.

`AccountSettings.vue` is `PATCH /api/v1/accounts/update_credentials` as a form:
the display name, `locked`, `discoverable`, `indexable`, `bot` and
`source[privacy]`. It sends **only the fields that changed**, which is not an
optimisation: the route writes only what it is given, and a form that posted the
whole of itself back would re-save a display name into a backend that owns it
(LDAP, SAML) and be refused for a switch it never meant to touch. The bio, the
banner and the metadata fields stay in the profile's own editor, because they are
what a visitor reads and are edited where they are seen.

**The default audience.** `source.privacy` lives on `verify_credentials` and
nowhere else — the account the initial state seeds the store with is the plain
Account entity and has no `source` — so the account store keeps the
CredentialAccount separately as `credentials` and exposes
`defaultPostVisibility`, which translates the wire's `private` into the
composer's `followers` and answers `''` for anything it does not know. The
composer's chain is: what the caller passed, the post being replied to, the
account's default, the last visibility the reader used, and failing all of those
`followers`. Because `verify_credentials` can land after a composer is already on
screen — the timeline draws one as the page opens — a watcher takes the default
when it arrives, unless the audience has been settled by a caller, a reply or the
reader.

`ListsSettings.vue` is the whole of `ListController` in one section: create,
rename, delete with a confirmation, and members added through the same
`/api/v1/global/accounts/search` the composer's mention autocomplete uses.
Lists carrying `nextcloud_group` are shown — a reader looks here when they wonder
who is in "Design" — with their members and without any of the three controls,
because the group decides all of it and the server answers 422 to anybody who
tries. The sidebar draws the lists from a fetch of its own, once per page, so
every change here is announced on the event bus as `LISTS_CHANGED` (exported by
`src/services/eventBus.js`, because two unrelated components have to agree on the
name) and `Navigation.vue` asks again.

**Muting asks two questions.** `MuteDialog.vue` — whether the notifications go
quiet too, and for how long: indefinitely, an hour, a day, seven days or thirty —
is opened from a profile and from the overflow menu of any post its author did
not write, in an `account-dialogs` chunk shared with `ListMembershipDialog`. It
mutes itself rather than handing the answers back, because both callers would do
exactly the same with them. Blocking is a plain confirmation in the same two
places. A relationship reports `mute_expires_at` while a timed mute is running,
which is what the profile writes out under the name.

**The Statistics page.** `src/views/Statistics.vue` behind the account menu, and
`StatisticsService` behind that. Everything is counted from this instance's own
rows when the page is opened — nothing stored, nothing precomputed by a cron —
which is what keeps it from showing a total a deletion has already made false,
and the walk is bounded at `MAX_POSTS` with the answer saying how far it got.
The engagement figures come from each post's `details`, which is a JSON blob:
that is why the sum is a walk in PHP rather than a `SUM()`, because the three
databases this app supports do not agree on how to reach inside one. A boost the
account made is counted as something it did and then left out of everything
else, because the likes on a boosted post belong to whoever wrote it. The bar
charts are CSS — a chart library would cost more than the page it draws — and
every bar carries its own figure in a `title`, because a bar whose only value is
its height says nothing to a reader who cannot see it.

What the page reports beyond the totals is what somebody running an account
professionally asks of it: engagement per post and per follower (the second is
the industry's "engagement rate", against followers because this app has no
impressions to divide by and says so rather than inventing a denominator), the
median beside the mean because one viral post makes a mean meaningless, the
share of posts that got no answer at all, which kind of post averages best
(media, hashtags, originals against replies, each visibility), which weekday and
hour do, which hashtags are worth using as opposed to merely used, and where the
audience is — the hosts the followers are on, which is what a Fediverse account
has instead of a geography. Two sample-size rules keep those from being noise:
an hour is not named until three posts fall in it, and a hashtag's average is
not reported until it has been used twice.

**And the way back out.** A post opened from a timeline is somewhere the reader
went *into*, so `TimelineSinglePost` carries a Back button above the thread. It
uses `history.state.back` — which the router writes whenever it navigates inside
the app — to tell a post opened from a timeline from one opened from a link
somebody sent: the first goes back, the second goes to the home timeline, because
a button inside the app should not be the thing that leaves it.

**Which is only a way back if the timeline is still there.** The store holds one
list at a time, and `changeTimelineType()` used to clear it on every call — which
includes the call `Timeline.vue` makes in `beforeMount()` when the view is
mounted again on the way back out of a post. A reader four pages into a timeline
came back to fifteen posts. `switchTimeline()` compares the list being asked for
with the one being held (`getTimelineIdentity`: type, account and params, the
same value that tells a page in flight it is no longer wanted) and clears only
when they genuinely differ, and it keeps **one** list aside — `remembered`, the
one just left — so that coming straight back to it finds it whole. One and not a
cache of all of them: each entry holds a full status index, and keeping every
timeline ever opened is the leak `resetTimeline()` was written to stop. A
restored list also sets `restored`, which is how `TimelineList` knows not to ask
for another page on top of the ones it already has.

Putting the reader back where they were then needs the page to exist first. Vue
Router applies the offset Back remembers as soon as the route has changed, and at
that moment the timeline is one screen tall, so the browser clamped a four-page
offset to the bottom of what was there. `scrollBehavior` returns a promise
instead: `TimelineList` emits `timeline:rendered` on the event bus once its
entries are on the page, and the router waits for that, or for two seconds,
whichever comes first — a view that never says anything still scrolls. The offset restored is the content column's, not the window's: a Nextcloud app is given a fixed viewport and the column scrolls inside it, so the position Vue Router remembers is always zero and applying it moved nothing. A `beforeEach` guard records `#app-content-vue`'s `scrollTop` for the view being left, at most ten views deep.

The "N new posts" pill that polling puts up is sticky, and so is the box the
reader writes in: same stacking context, and the composer both taller and above.
The pill only appears once the reader is a screen or so down, which is exactly
when the composer is stuck to the top — so it was painted behind it every time.
`TimelineList` measures the composer, which is a sibling above it rather than a
parent, and sets the pill's `top` below it; measured and not assumed, because a
content warning, a row of attachments or a poll all make the composer taller.
The watching lasts only as long as the pill is on screen.

**One card, and a line only when there is a conversation.** The post a page is
about used to be drawn inside two boxes: `TimelinePost`'s own card, and around
it a second card in `TimelineSinglePost` — white ground, padding, rounded
corners, a shadow, and a border in the accent colour on top of that. What marks
the post the page is about is that it is the one at the top with the thread
hanging off it, so `.main-post` is spacing and stacking now and the card inside
does the drawing. The 24px it is indented by is the 16 of margin plus the 8 of
padding the lists above and below take, which is what puts every avatar in the
conversation on one line — the line the spine runs down. And the spine itself
is drawn only when `hasThread` holds, meaning there is a parent or a reply:
beside a post with neither, it was a line from nothing to nothing.

Under it, "No replies yet" is a small drawing over a line of muted text rather
than a heading. `EmptyContent` takes an optional `illustration` name alongside
the `image` the timelines use, resolved through a map of components that draw
themselves in markup — `NoReplies` is two speech bubbles in `currentColor` over
the page's own background, so it follows the theme with no filter to correct it
on dark. It is a component and not another file in `img/undraw` because those
eight illustrations are licensed for this app by permission covering those eight
and nothing else (see `img/undraw/readme.md`); anything new has to be ours. A
state with a small drawing keeps the compact layout — the 60vh of height is room
for the full-size ones only.

**A link to a post, opened cold.** `/@{username}` and `/@{username}/{token}` are
ActivityPub addresses first, so `ActivityPubController` owns them; a request
whose `Accept` header asks for HTML is handed to `SocialPubController`, which is
the browser half of those two routes and nothing else — it has no routes of its
own. Who is asking decides what they get. A reader with a session gets the app,
the very page `NavigationController::navigate()` serves at `/`, because the
client-side router has a view for each of these paths. This used to serve the
public page to everybody, so a link to a post or a profile landed a logged-in
reader on a page with a blue header, a "Get your own free account" banner and a
Follow button that started the remote-follow flow for an account they could have
followed with one click. An ActivityPub request is untouched by any of it.

The app writes its own links to a post as `/@acct/<nid>` — the numeric id its
client API uses — while the address a post is published under ends in a
different token, and the post used to be looked up by that address alone. So a
link opened in a new tab, a reload, or a link somebody sent found nothing,
provided no `item`, and the page said the post did not exist.
`SocialPubController::resolvePost()` falls back to `getStreamByNid()` for a
numeric token, on the browser branch only: an ActivityPub request asks for a
post by its address, and answering a second identifier there would invent a
second canonical id for every post. The fallback goes through the same viewer
filter every other read does, so it shows what the reader may see and nothing
more. The other end of that is in `TimelineSinglePost`: the post the server
rendered into the page carries its *client* id, and the view goes on that rather
than on what is in the address, so `/context` and the store are asked in the one
identifier they speak. The initial-state post is only used when the address
names it — by that id, or by the last segment of its `uri` — since the page is
rendered once and the reader goes on reading, and a second post used to be
answered with the first.

An address that names nothing is a **404** rather than a 500. Looking an account
up used to try to *fetch* it, and a bare username is not an account anybody can
resolve, so the exception saying so came out as a server error; the lookup is of
what this instance already has now (`getFromAccount($username, false)`) — an
anonymous page request is no reason to webfinger whatever is in the address. A
visitor gets `templates/notfound.php`, a small guest page shaped like the
server's own; a reader with a session gets the app with a 404 status, and its
views say "User not found" and "This post is not available" once they have
asked.

On the client, `TimelineSinglePost` asks for the post itself when nothing has
loaded it — `timelineStore.fetchStatus()`, which is `GET /api/v1/statuses/{id}`.
`/context` answers with what is *around* a post and never with the post, so a
page reached from anywhere outside a timeline had nothing to draw. A tile on
Discover is exactly that: those posts belong to the Discover view and never
reach the timeline store.

**The pictures on Discover** were not drawn at all, and had not been since the
tab was added. `ProfileMediaGrid` builds each tile's route with the grid's
`account` prop, which Discover leaves empty because the grid is everybody's —
and `account` is a required route param, so `router-link` threw while resolving
and took every tile with it. A tile links at the account that wrote the post it
draws, falling back to the grid's own account where a post carries none.

**The hashtags on Discover.** `TrendingHashtags` is a ranking rather than a
list of names: a row carries its position, the tag, how often it was used in the
window asked for, and a bar showing its share of the busiest tag on the list —
which is the only comparison these numbers support, since `history` from this
server is a single bucket for the chosen window rather than a series (the
`accounts` field is always `0`, so "how many people" is not something the page
may claim). The window itself is the useful part: `/api/v1/trends/tags` takes a
`period` of `1h`, `12h`, `1d`, `3d` or `10d` and **orders by that window's own
column**, so choosing one re-ranks rather than relabels, and an answer that
arrives after the reader has moved to another window is dropped rather than
drawn. The empty state says which of the two emptinesses it is: nothing tagged
in this stretch of time, or — over ten days — an instance where hashtags are not
used.

Following is answered once for the page. `HashtagFollowButton` looks a tag up
for itself when nobody has told it, which is right for the one button on a
hashtag timeline and wrong for twenty on a ranking: it takes an optional
`known` list and reads its state from that instead, so the page costs one
`/api/v1/followed_tags` call rather than twenty lookups before anything can be
drawn. `null` means nobody has said, which is not the same as "not followed" —
hence a list rather than a boolean, since a `Boolean` prop cannot carry the
third state.

**What else a post's own page says.** Four things that belong to a post being
read rather than to a post being scrolled past.

`PostReactedBy` puts the faces behind the two counts under the post, through
`GET /api/v1/statuses/{nid}/reblogged_by` and `/favourited_by` — both of which
the server has answered since the moderation tier and neither of which the web
client called until now. Nothing is requested when a count is zero, which is
most posts, so the page costs the two requests only when there is something to
answer with; a refusal draws nothing, since the counts are still on the post and
this row is only the elaboration. A dozen faces at most, with the count saying
how many there are in all, and each row reloads on its own when its own count
changes — the reader's own boost lands in the row it just changed.

`PostDetails` is the fine print: the full date, the audience in the words the
composer uses, the language named rather than coded (`Intl.DisplayNames`, the
code itself when nothing can name it), when it was last edited, and — for a post
from another server — a link to where it actually lives. A local post gets no
such link, its own address being the page the reader is on. Mastodon's
`application` is not among these: the entity this app builds does not carry one,
and inventing a value would be worse than the absence.

The composer sits under the post, pointed at it, rather than at the top of the
page waiting to be summoned by a reply button. `Composer` takes `inReplyTo` for
this: it seeds `replyTo`, and it is a *default* rather than a fixed target — pressing reply on
another post in the thread retargets the box as it always did, and sending or
dismissing that reply comes back to the anchor rather than leaving the box
pointed at nothing. The header naming who is being replied to is hidden while
the target is the post directly above the box, where it would be the page
repeating itself. There is no box at all on the public page, where there is no
account to send from.

The anchor is the one thing that does not open the box. Everywhere else a
`replyTo` means a reply in progress, which is why it counts as expanded; a box
that is under every post would then be eight controls and a text area under
every post. So `expanded` ignores the anchored target, the box is a line of
placeholder until somebody clicks into it, and it closes again once the reply
has been sent. Pressing reply on the post it sits under sets `openedByHand`
directly — the target does not change there, so opening is the whole of what
that button can do.

And when the thread is shorter than the post says it is, the page says so.
`replies_count` is what the post's own instance reported plus what has arrived
here, so the two differ honestly: a reply from a muted or blocked account is
filtered out of the thread but still counted, and a remote thread is only ever
as complete as what has reached this server. The note counts against the
*direct* replies on screen, since that is what the number on the post counts,
and it waits for `TimelineList` to emit `settled` — before that, every reply is
one this page has not drawn, and saying so would be counting the loading.

Saying it out loud found a counter that was wrong. Deleting a reply removed its
row and left its parent's `replies` detail alone, so a post whose reply had been
deleted claimed one that no page could ever show — invisible until something
compared the number with the thread. Both deletes now recount:
`NoteInterface::delete()` for a `Delete` that arrives from another server, and
`StreamService::deleteLocalItem()` for your own post, which never passes through
that interface at all. The arithmetic itself is `StreamRequest::recountReplies()`
— `remote_replies` (what the post's own instance reported, which nothing here can
see) plus `countRepliesTo()` — which is a recount rather than a bump, because the
two things that move the number cannot both be expressed as one, and a recount
repairs a count that has already drifted instead of tracking one. It runs after
the row is gone, or it counts the reply it has just removed.

**A post is a link to itself.** Pressing anywhere on a post in a timeline opens
the post with its replies — the card, its picture, its video. `TimelinePost`
works out `postRoute`, which is `null` for exactly one post: the one whose page
the reader is already on (`$route.params.id`). A reply on that page is another
post and links to its own page like any other. `onPostClick` is what keeps the
card from swallowing everything else: a link, a button, a control, a modified
click asking for a tab, or a press that ended a text selection all keep their
meaning. Below the card, `PostAttachment` takes the same route as a `to` prop —
where it is set, a press on the media routes; where it is `null`, it opens the
viewer — and `MediaAttachment` takes an `interactive` flag that decides whether
a video gets `controls` at all. A player in a timeline would put a play button
in the way of the post and swallow the press meant to open it; the poster is
what the reader is choosing from. On the post's own page the media is the
subject again: the picture opens full size, the video plays.

**An avatar is a link.** `ActorAvatar` and `TimelineAvatar` wrap the face in a
link to the account, so the most obvious thing on screen to click does the
obvious thing wherever either is used. Three cases, because a link has to go
somewhere that exists: the router where there is one; the account's own address
where there is not, which is the profile section this app adds to a Nextcloud
user page — a custom element running an app of its own, where a `router-link`
resolves to nothing and takes the avatar with it; and nothing at all for an
actor with neither a handle nor an address. Each link is named ("Open the
profile of @alice"), because its only content is an avatar with empty alt text.
`link: false` turns it off where the avatar already sits inside a link — two
nested anchors is invalid and the browser resolves it by dropping content — and
on the composer's reply and quote lines, where following one would abandon a
draft.

**Account previews.** `AccountHoverCard.vue` is the card that opens when the
pointer rests on an avatar or a mention, fetched once per handle and cached in
the account store. It answers "who is this?" without opening the profile, so it
carries what the profile header does: display name and handle, when the account
joined, the bio, up to four of its metadata fields (`src/utils/profileFields.js`
parses those out of the HTML Mastodon sends them as, and the profile page uses
the same function so the two cannot disagree about what a link is), and the
three counts. Badges say what is true *of* the account rather than about it —
that it follows you, that it approves its followers, that it is automated — each
of which is something somebody deciding whether to follow wants before they
click. Everything is conditional: a card built from what a status carried knows
less than one built from a lookup, and a gap is better than an invented value. Two rules keep it consistent. Every avatar that *stands for
somebody* goes through `ActorAvatar` (or `TimelineAvatar`, which adds the
instance ring) rather than a bare `<img>`, so the small ones preview too — the
face on a "X boosted" line, the one on a notification, the ones in Discover.
And **every** `NcAvatar` in the app passes `disableMenu`, without exception,
because for a *local* account it otherwise hangs Nextcloud's own profile card
off the same hover and opens it over the top of this one: the reader would get
a different card depending on which instance the account was on. There is one
account preview in this app and it is this app's. The avatars that are not a
reference to somebody else — the reader's own face in the composer, the profile
header of the profile you are already reading, the two on the remote-follow
page — simply have no card rather than Nextcloud's.

That rule is the kind a new avatar added next year cannot be expected to
remember, so `tests/js/hoverCard.test.js` reads the templates and fails with the
file and line of any `<NcAvatar>` that is missing it.

`QuotedPost.vue` renders a status's `quote`. Only an `accepted` quote whose
`quoted_status` came back becomes a card; `pending`, `rejected`, `revoked` and
an accepted quote the reader may not see each get a line saying which, because
a quote that silently renders as nothing is indistinguishable from a bug. A
quoted post that itself quotes something is not nested a second time — the
component prints one line and stops, so no chain and no cycle can recurse.

**The Photos view.** The sidebar's `Photos`, directly under Home, is a timeline
with `only_media` — what people showed rather than what they said. It is the
same query and the same filters, one predicate narrower, so nothing about
visibility, blocks, mutes or silencing is decided twice.

Which people is the same switcher: Photos carries it too, so the photos of the
people you follow, of this instance and of everywhere are one control apart.
The scope rides in the **query** (`/timeline/photos?scope=timeline`) rather than
in a `type` of its own, for two reasons: the sidebar's Photos entry stays lit
whichever scope is being read, and the page is one page with a filter on it
rather than three pages that happen to look alike. It is the route's own three
words there as well (`timeline`, `federated`, and nothing at all for My Feed),
so no part of this translates between two vocabularies. The scope arrives from
the address bar, so `Timeline.vue` reads it rather than trusting it: anything
that is not one of the two named scopes is the default. It is also part of what
`Timeline.vue` reports as the timeline's params, which is what makes changing it
refetch instead of leaving the previous photos on screen.

**Posts, Photos and Videos on a profile.** The same switcher, above the account's posts, asking the server a different question rather than filtering the page on screen — a page filtered in the client is a page that can come back empty while there are still videos to find. The tab rides in the query (`/@alice?media=video`) so a profile stays one route and every link to it still names the same one, and the word is the API's own.

Behind it: `only_media` is Mastodon's own parameter on `/api/v1/accounts/{id}/statuses` and had never been passed on; `media_type` is a **Social extension** that narrows it to one kind, because Mastodon has nothing finer and two tabs need the difference. `ProbeOptions::setMediaType()` takes only the three kinds an attachment can be — `image`, `video`, `audio`, which are the first half of its MIME type and so the only values the column can hold — and reads anything else as no preference, since the value arrives from a query string. `media_type` implies `only_media`: a post with no attachments cannot be one carrying a video. The predicate is `SocialLimitsQueryBuilder::limitToMediaType()`, a `LIKE` on `"type":"video"` in the stored attachments — the column holds them as the client sees them, there is no column to compare and no JSON support to rely on across the three databases this app supports, and a description containing the same text is stored with its quotes escaped so it cannot collide. Unindexed, like the silenced-instance filter and for the same reason: it runs on a list something else has already narrowed to one account. Pinned posts are left out of a filtered tab, being about the account rather than about a kind of attachment.

**The composer is on your own profile and nowhere else.** Every profile used to
carry one, pre-filled with a mention of whoever it belonged to and set to a
direct message — so a page for reading an account looked like a page for writing
to them, and a stranger's profile asked "what would you like to share?". Your
own profile is a page you post from, the way the home timeline is; somebody
else's is a page you read. The sub-routes go with it: a list of followers is not
a place to post from either. Direct messages are still written from the Direct
messages timeline, which sets the visibility the same way.

**The tab also decides how it is drawn**, and there is nothing beside it to say otherwise: Posts is what somebody wrote, so it is a list of posts; Photos and Videos are what they showed, so they are grids. There used to be a grid/list switch here, remembered across profiles, and it could disagree with the tab — `ProfileMediaGrid` kept only the posts carrying a picture, so Posts showed sixteen of them as a list and three as a grid, with nothing to say where the other thirteen had gone. One question, one answer. The empty state comes from `TimelineList` in both views for the same reason: the grid carried one of its own that said "No photos yet" whatever the tab was, so an account with no videos was told it had no photos.

**The Videos view.** The sidebar's `Videos`, directly under Photos, is the same
page again with `only_video` — this app's own narrowing of `only_media`, because
a video timeline that asked Mastodon's question would answer with every holiday
photo on the instance. It carries the same switcher, in the same query
(`/timeline/videos?scope=federated`), through the same `isScopedPage` branch in
`Timeline.vue`: Photos and Videos differ in one predicate and in nothing else,
which is why `TimelineSwitcher` takes the page it is scoping as a prop rather
than a `photos` flag.

Two things make a post a video, and the query asks both (`limitToVideo()`): an
attachment whose Mastodon `type` is `video`, or a post that arrived as a PeerTube
`Video`. The second counts whether or not this instance found a playable file in
it — the post is a video either way, and a timeline that hid the ones it could
not play would be hiding exactly the videos worth reporting.

In the player, a video attachment with a **preview that is not the video itself**
gets that preview as its `poster` and `preload="none"`. Only a federated video
has one, and it is what lets a page of twenty of them be scrolled without opening
twenty connections to other servers: `preload="metadata"` on a proxied video is
not free the way it is on a local one. A video uploaded here has `preview_url`
pointing at the file, which is no use as a poster — a browser handed a video for
one downloads it to find a frame — so those keep `metadata` and no poster.

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
| Notifications | `Notifier` | `Application::register()` | Prepares Social notifications for the NC notification system: every subject in `NotificationService::SUBJECTS` is worded, linked into this app's own pages (`NotificationService::emit()` builds the link from the post's nid or the account's handle), and a `follow_request` carries Accept/Decline actions that POST to `/api/v1/follow_requests/{id}/authorize` and `/reject`; the answer dismisses the stored row and withdraws the bell entry (`onFollowRequestAnswered()`) |
| References | `Reference\PostReferenceProvider` | `Application::register()` | Unfurls links to this app's posts and profiles (`/@acct/{nid}`, `/@acct/{id tail}`, `/@acct`) into a card wherever Nextcloud renders references — Talk, Text, Deck — and on public shares too (`IPublicReferenceProvider`). Renders only public and unlisted posts and profiles, because the card is cached per link for everyone (`getCacheKey()` is `null`, the prefix is the link); anything narrower resolves to nothing and stays a link. Never fetches from another server: a remote account not cached here is not looked up |
| Activity | `Activity\Publisher`, `Activity\Provider`, `Activity\Setting`, `Activity\Filter` | `appinfo/info.xml` (`Publisher` is called from `NotificationService::emit()`) | Every bell entry is also an Activity entry with the same subject (`NotificationService::SUBJECTS`), worded by `Provider` with the actor as a rich `user` (local) or `highlight` (remote) and the post as the message. `Setting` puts it in the stream by default, the digest mail off by default, and Activity's own notifications off for good — this app has a bell. `Filter` is the "Social" entry in Activity's sidebar |
| User migration | `UserMigration\SocialMigrator` | `Application::register()` | Puts the user's Social data in a Nextcloud account export, and reads it back on import. See "Account export and import" below |
| Profile Page | `ProfileSectionListener` | `Application::register()` (on `BeforeTemplateRenderedEvent`) | Adds the `social-profilePage` script to the user profile page |
| Files | `FilesScriptsListener` | `Application::register()` (on `OCA\Files\Event\LoadAdditionalScriptsEvent`) | Adds the self-contained `social-filesAction` init script, which registers "Share to Social" on pictures and videos |
| User Events | `UserAccountListener` | `Application::register()` (on `UserUpdatedEvent`) | Re-caches the local actor when the NC account changes |
| User Events | `UserDeletedListener` | `Application::register()` (on `UserDeletedEvent`) | Deletes the Social account of a deleted Nextcloud user through `AccountService::deleteActor()`: the actor is tombstoned, what belongs to it is dropped and a `Delete` is federated. A user who never opened Social has nothing here and is skipped; a failure is logged rather than thrown, since the Nextcloud user is already gone |
| Group Events | `GroupListListener` | `Application::register()` (on `UserAddedEvent`, `UserRemovedEvent`, `GroupDeletedEvent`, `GroupChangedEvent`) | Keeps the group lists in step with the groups; never fails the group operation, a failure is logged and the cron's reconcile settles it |
| WebFinger / NodeInfo / host-meta | `WebfingerHandler` | `Application::register()` | ActivityPub discovery at the server root |
| Contacts Menu | `ContactsMenuProvider` | `appinfo/info.xml` | "Follow %s on Social" entry linking to the actor page |
| Background Jobs | `Cron\Cache` | `appinfo/info.xml` | 12-minute interval, `MAX_DURATION` of 300 seconds: reaps deleted actors, refreshes local and remote actor caches, caches documents, recomputes hashtag trends, closes polls, prunes remote statuses past retention (bounded to 5000 per run), sweeps cached remote actors nobody refers to (bounded to 500), syncs remote timelines (`getRemoteActorsToSync()`, its own batch — the refresh now stamps what it touched, so a shared selection would leave the sync nothing to do on a small instance), verifies profile links, reconciles group lists. The budget is threaded through the steps and into the two loops that make a request per remote actor; a step that has no time left is skipped and named in the log, and the next run **starts with the first step it skipped** (`cache_cron_start`), so the tail of the list is not the part that never runs |
| Background Jobs | `Cron\Queue` | `appinfo/info.xml` | 12-minute interval: drains the outbound request queue and the inbound stream queue |
| Background Jobs | `Cron\ActorCleanup` | queued by `PersonInterface::delete()` | Finishes detaching a deleted account from the posts that addressed it, when there are more of them than one inbox request should rewrite. Not in `appinfo/info.xml`, for the same reason `Cron\DomainPurge` is not: it is meaningless without an argument. Re-queues itself while rows remain |
| Background Jobs | `Cron\DomainPurge` | queued by `FediverseService::addAddress()` | Queued with a domain when one is added to the deny list, never registered in `appinfo/info.xml` — a job listed there is added once at install time with no argument, and this one is meaningless without a domain. Runs 10 batches of `DomainPurgeService` per pass and re-queues itself while anything of the domain is left |
| Background Jobs | `Cron\ExpiredStories` | `appinfo/info.xml` | Hourly: deletes the stories whose day is up, at most 500 per run. The read filter on `expires_at` is the other half of the guarantee; this is what stops the rows and pictures outliving it |
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

Twenty-four occ commands are registered in `appinfo/info.xml`. `lib/Command/` also holds `SocialCommand.php`, the base class all of them extend — it declares `--output` and the writers that honour it, in place of the server's private `OC\Core\Command\Base` — and `ExtendedBase.php`, a shared base several of them extend. Neither calls `setName()`, so neither registers a command of its own. See `docs/OCC-Commands.md`.

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
| `social/media_attachments/files/<id>/original.<ext>` | The file of one attachment of one of those posts, in the layout Mastodon's own export uses. `<id>` is the attachment id the post carries |
| `social/media_attachments/header.<ext>`, `avatar.<ext>` | The profile banner, and a profile picture where this app has one of its own. Named in `actor.json` as `headerFile` and `avatarFile` |

Reads are paged everywhere (`SocialMigrator::PAGE`, 50 rows); the block and mute
lists come from one capped query (`RELATIONS_LIMIT`, 5000), and reaching the cap
is reported on the console rather than silently truncating. A follow whose
account this server never cached is left out of the CSV instead of being written
as a bare actor URL, which no reader of the format accepts.

**The files travel with the posts.** As the outbox is walked, each attachment
that names a copy this instance stored is copied into the archive and its `url`
is rewritten to that path — relative, because an absolute one names the server
the archive is leaving. The address it had here is kept beside it as
`originalUrl`, so nothing the archive knew is lost and a reader that cannot use
the copy still knows where the picture was served from. The bytes go through
`IExportDestination::addFileAsStream()` from the stored copy's own stream: a
two-gigabyte video costs the export no more memory than a sentence does. An
attachment this instance has no bytes of — a streamed PeerTube video, a copy a
retention sweep removed — keeps the URL it had, which is all there ever was of
it. The size estimate (`ISizeEstimationMigrator`) counts the media too, per kind
(`SIZE_IMAGE`/`SIZE_AUDIO`/`SIZE_VIDEO`, from one grouped count in
`CacheDocumentsRequest::countLocalCopiesByType()`): the table stores no file
size, and an estimate that left the files out was wrong by orders of magnitude
for any account with a video on it.

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
- **Other people's media.** Only the files of the user's own posts are copied,
  and only where this instance stored them. A streamed video is deliberately
  never mirrored here (`Document::COPY_STREAMED`) and there is nothing to put in
  the archive; the Nextcloud account's avatar is the account's rather than this
  app's, and core's own migrator carries it.

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
- their **files** are, onto the posts this server does have: an attachment whose
  URL names a `media_attachments/…` path in the archive is stored through
  `DocumentService::storeLocalAttachment()` — which is the path the composer's
  own upload takes, so the type is sniffed from the bytes, anything
  `filterMimeTypes()` refuses is refused here too, and the metadata is stripped —
  and the post's stored copy of its attachments is re-pointed at it. Nothing is
  transcoded: the file in the archive is the one that was stored here, already
  converted and resized when it was first uploaded. Where the post's picture is
  still here nothing happens; where the post itself is not here the file stays in
  the archive rather than becoming a row nothing can show; and where the archive
  names a file it does not hold, the attachment keeps the address it had, which
  is what `originalUrl` is for. This is the case of an archive read back after
  the files were lost and the rows were not.
- the **banner** goes back through `BannerService::setFromTempFile()`, the path
  that owns it, so the actor cache and the followers are told exactly as they are
  when it is set by hand. The **avatar** is the Nextcloud account's, so
  `AvatarService::restoreFromArchive()` decides: a picture the account already
  has is never written over, and only an account still showing its generated
  initials gets the one out of the archive — the order the migrators of an
  account import run in is not this app's to depend on.

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
- the HTTP routes the app registers — the `#[FrontpageRoute]` attributes on the controllers, read by reflection the way the server reads them, plus what is left in `appinfo/routes.php` — against the route tables of `docs/API.md`;
- the repair steps of `appinfo/info.xml` against the integration table above;
- the tables declared in `CoreRequestBuilder` against the schema table above;
- the supported Nextcloud and PHP version ranges, and the app version stated at the top of this file, against `appinfo/info.xml`;
- two claims of *absence*, which is the direction the rest of it is blind in: a sentence saying there is no `/some/route` must be true, and a symbol the docs call "commented out" may not be called by live code. The false claim that key-pair rotation was unavailable, published six lines after the flag that performs it, is what these were written for.

Everything else is on the author of the change. In particular nothing can check a paragraph of prose against the behaviour it describes, so a feature described in words the "not implemented" guard does not recognise, or a mechanism described plausibly and wrongly, still gets through.
