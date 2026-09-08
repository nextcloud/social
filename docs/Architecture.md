# Nextcloud Social — Architecture Overview

## Introduction

Nextcloud Social is a federated social networking app built on the W3C ActivityPub standard. It integrates into Nextcloud as an app, providing each user with an ActivityPub identity (Person actor) that can interact with Mastodon, Friendica, and other Fediverse platforms.

**App ID:** `social`  
**Namespace:** `OCA\Social`  
**License:** AGPL-3.0-or-later  
**App version:** 0.11.5  
**Supported Nextcloud versions:** 28 – 35  
**Supported PHP versions:** 8.1 – 8.5  

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
│   ├── Command/                # occ CLI commands (+ ExtendedBase, a shared abstract base)
│   ├── Controller/             # HTTP entry points (ActivityPub, Mastodon-ish API, local API, OAuth, OStatus, navigation, queue, config)
│   ├── Cron/                   # Background jobs (Cache, Queue)
│   ├── Dashboard/              # Nextcloud Dashboard widgets
│   ├── Db/                     # Query-builder based repositories (`*Request` + `*RequestBuilder` pairs)
│   ├── Exceptions/             # Custom exceptions
│   ├── Interfaces/             # Per-ActivityPub-type handlers (Activity/, Actor/, Object/, Internal/)
│   ├── Listeners/              # Event listeners (ProfileSectionListener, UserAccountListener)
│   ├── Migration/              # Database schema migrations + repair steps
│   ├── Model/                  # ActivityPub model objects + support models
│   ├── Notification/           # Nextcloud notification integration (Notifier)
│   ├── Providers/              # Contacts menu integration
│   ├── Search/                 # Unified search integration
│   ├── Service/                # Business logic services
│   ├── Settings/               # Admin settings (moderation panel: reports + Fediverse access list)
│   ├── Tools/                  # Vendored helper layer (query builder base, HTML sanitizer, traits, exceptions)
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
│   ├── store/                  # Vuex store (index.js + timeline, account, settings, errors)
│   ├── views/                  # Route- and entry-level components
│   ├── components/             # UI components (`.vue`, plus MessageContent.js)
│   ├── services/               # eventBus, logger, notifications
│   ├── mixins/                 # accountMixins, currentUserMixin, popoverMenu, serverData
│   ├── directives/             # focusOnCreate
│   ├── utils/                  # sanitizeHtml (+ its unit test)
│   └── types/                  # JSDoc type definitions (ActivityPub, Mastodon)
├── templates/                  # PHP templates: main.php (SPA), oauth2.php
├── l10n/                       # Translations
└── docs/                       # Documentation
```

There is no `lib/bootstrap.php`; Composer's autoloader is pulled in by `lib/AppInfo/Application.php`.

---

## Database Schema

The tables are created by `lib/Migration/Version1000Date20221118000001.php`, all prefixed with `social_`:

| Table | Purpose |
|-------|---------|
| `social_action` | Like/Announce actions as ActivityPub objects (actor → object, with type) |
| `social_actor` | Local user actors (tied to NC accounts, holds the RSA key pair) |
| `social_cache_actor` | Cached remote federated actors (inbox/outbox URLs, public keys, counts) |
| `social_cache_doc` | Cached remote and local media attachments |
| `social_client` | OAuth 2.0 client registrations |
| `social_follow` | Follow relationships (actor → object, with accepted flag) |
| `social_hashtag` | Hashtag trend data (a JSON `trend` blob per hashtag) |
| `social_instance` | Known federated instances (version, metadata) |
| `social_req_queue` | Outbound ActivityPub delivery queue |
| `social_stream` | Core content table: posts, notes, activities |
| `social_stream_act` | Per-viewer stream flags (`liked`, `boosted`, `replied`, `bookmarked`, `values`) |
| `social_stream_dest` | Stream visibility targets (who sees what) |
| `social_stream_queue` | Inbound stream processing queue |
| `social_stream_tag` | Stream-to-hashtag mapping |
| `social_actor_relation` | Blocks and mutes: one row per (local actor, target actor, `block`/`mute`/`blocked_by`) |

`Version1000Date20260611000001` only drops the abandoned `social_3_*` tables from an earlier prototype. `Version1000Date20260907000001` adds the timeline indexes and the missing primary keys, `Version1000Date20260907000002` adds `social_actor_relation`, `Version1000Date20260907000003` adds the `bookmarked` flag to `social_stream_act`, `Version1000Date20260908000001` widens `social_client.app_client_secret` for its hashed value, `Version1000Date20260908000002` adds the `locked` flag to `social_actor`, and `Version1000Date20260908000003` adds `social_report` (moderation reports).

Note that `CoreRequestBuilder::TABLE_NOTIFICATION` (`social_notif`) is declared but no migration creates that table and no repository queries it; it is a leftover constant. In-app notifications are stored in `social_stream` as `SocialAppNotification` items.

---

## Key Services

The business logic lives in `lib/Service/`.

### Account & Identity

- **AccountService** — Creates local actors (generating an RSA key pair via `SignatureService`) and marks them deleted, refreshes the local actor cache (avatar, display name, follower/following/post counts), performs "blind key rotation", and reaps actors past their deletion retention
- **ActorService** — Saves/updates cached `Person` rows and resolves an actor's cached header image
- **CacheActorService** — Central actor cache/resolver. Looks up actors by ActivityPub id or `user@host`, fetches unknown remote actors over WebFinger + HTTP on a cache miss, and probes their followers/following/outbox counts
- **RelationshipService** — blocking and muting: stores the relation, severs follows in both directions on a block, and federates `Block`/`Undo{Block}` unless the `federate_blocks` app setting is `0`. Mutes are purely local and never federated.

### Content & Timelines

- **StreamService** — Core stream/timeline engine. Assigns ActivityPub ids, expands recipients (public/unlisted/followers/direct), resolves reply chains, detects stream types, deletes local items, and reads the timelines (home, local, global/federated, tag, account, liked, direct, notifications)
- **PostService** — Creates posts (text, attachments, reply-to, mentions, hashtags) and edits existing local posts, delegating federation to `ActivityService`
- **FollowService** — Follow/unfollow flows, follower/following collections, and relationship lookups
- **LikeService** — Creates and undoes Like activities
- **StreamPruneService** — Retention: deletes remote statuses older than `retention_days` (default 0 = disabled) that no local user interacted with, whose author nobody follows, that no local status replies to or boosts, and that are not DMs — together with their dest/action/tag rows and cached attachments. Runs bounded in the Cache cron and unbounded via `occ social:stream:prune`
- **BoostService** — Creates and undoes Announce (boost/reblog) activities
- **ActionService** — Dispatcher for the Mastodon-style status actions. favourite/unfavourite create and delete a Like, reblog/unreblog an Announce, and bookmark/unbookmark toggle the viewer's local `bookmarked` flag (never federated, served by `/api/v1/bookmarks`). `translate` returns the status unchanged, and the unimplemented `mute`, `unmute`, `pin` and `unpin` are refused with `InvalidActionException` instead of silently doing nothing
- **HashtagService** — Recomputes hashtag trends over 1h/12h/1d/3d/10d windows and searches hashtags
- **StreamActionService** — Writes the per-viewer flags in `social_stream_act`
- **SearchService** — Backing searches for accounts, hashtags and URIs; `searchStreamContent()` exists but no caller uses it
- Every timeline query filters actors the viewer has blocked or muted (and actors who blocked the viewer) through one anti-join, `SocialLimitsQueryBuilder::filterHiddenActors()` — blocks apply everywhere, mutes to aggregated timelines and threads but not to a muted account's own profile or a directly opened post, and muted-with-notifications to the notification stream.

### Federation

- **ActivityService** — Wraps items in Create/Update/Delete activities, LD-signs them, resolves target inboxes, and drives the delivery queue. `request()` sends the single highest-priority entry synchronously and kicks off an async request for the rest
- **ImportService** — Parses incoming ActivityPub JSON into typed model objects (`AP::getItemFromData()`) and dispatches to the matching handler in `lib/Interfaces/`
- **SignatureService** — RSA-2048 key generation, HTTP Signature signing and verification (checking `date` freshness, `content-length` and `digest` before the signature itself), and RsaSignature2017 Linked Data signatures
- **RequestQueueService** — Manages `social_req_queue`: creates entries, hands out the priority entry, and re-offers standby entries after a `floor(tries^4 / 3)` second backoff
- **StreamQueueService** — Manages `social_stream_queue`, the inbound side. Its only implemented queue type is `Cache`: for each Note a received stream references (a reply parent, a boosted post), it fetches that Note, caches its author, stores it, and embeds it in the referencing stream's cache. Anything that is not a Note, or whose id does not match the URL it was fetched from, is rejected
- **CurlService** — HTTP client for ActivityPub fetches, WebFinger and host-meta lookups, and the async self-call that drains a delivery token
- **FediverseService** — Instance-level access control; see [Security](#security)
- **InstanceService** — Builds and returns the local instance's NodeInfo-style metadata

### Media

- **DocumentService** — Owns the cached document lifecycle: caching a remote document by id, serving originals and resized copies out of app storage, and caching the local actor's avatar and header
- **CacheDocumentService** — Writes uploads, remote downloads and temp files into app storage, filters MIME types against an allow-list, and reads content back out
- **BlurService** — Generates a blurhash string from a GD image

### System

- **ConfigService** — App/user configuration and the derived URLs (cloud URL, social URL, social address, max download size, self-signed toggle), plus ActivityPub id generation
- **CheckService** — Installation checks (is `/.well-known/webfinger` reachable) and repair of invalid follow and note rows
- **ClientService** — OAuth 2.0 client registration, authorization and token issuing
- **DetailsService** — Computes a `StreamDetails` object describing which local viewers a stream reaches
- **MiscService** — Logging helper and the running Nextcloud major version
- **TestService** — Backs the WebFinger probe of `occ social:check:install`
- **PushService** — on a new stream, resolves the local audience through `DetailsService` (home + direct viewers) and pushes a `social_timeline` custom event per user through the notify_push app when it is installed; without notify_push every call is a cheap no-op and web clients keep polling. The web client listens via `@nextcloud/notify_push` and drops its 30-second poll to a 5-minute safety net when push is available
- **UpdateService** — Builds admin notifications about the app's state, but nothing in `lib/` calls it; it is currently dead code

---

## ActivityPub Federation

The app is an ActivityPub **Server** that both produces and consumes ActivityPub messages.

### Actor Identity

Each Nextcloud user that has been given a Social account gets a Person actor:

- **ID:** the configured social URL plus `@username`, e.g. `https://cloud.tld/apps/social/@username`
- **Keys:** RSA 2048-bit key pair, generated per actor by `SignatureService::generateKeys()`
- **Endpoints:** `/@{user}/inbox`, the shared `/inbox`, `/@{user}/outbox`, `/@{user}/followers`, `/@{user}/following`

### Outgoing Flow

1. A user action (post, edit, delete, follow, unfollow, like, boost) has a service build the activity
2. `SignatureService::signObject()` adds a Linked Data Signature — for Create, Update, Delete, Like, Announce and their Undos. Follow and Accept are **not** LD-signed; they travel with the HTTP signature only
3. `ActivityService::request()` expands the activity's instance paths into concrete target inboxes
4. `RequestQueueService::generateRequestQueue()` writes one `social_req_queue` row per target
5. At most one row is delivered inline: `RequestQueueService::getPriorityRequest()` hands back the first row only when its priority is `TOP`, or `HIGH`/`MEDIUM` under narrow conditions, and otherwise throws `NoHighPriorityRequestException` so nothing is sent synchronously. If rows remain on standby, `CurlService::asyncWithToken()` fires a request at the app's own `/async/request/{token}` route to drain them
6. `Cron\Queue` (12-minute interval) retries whatever is still on standby, with the backoff above
7. Every delivery is an HTTP POST signed by `SignatureService::signRequest()`

**Activities the app emits:** Create, Update, Delete, Follow, Accept, Like, Announce, Undo.

The app never emits Reject, Add, Remove, Move or Block. It can parse all of them — `AP::getItemFromType()` constructs each one for an incoming document — but no service ever builds one to send.

### Incoming Flow

1. A remote instance POSTs to `/@{username}/inbox` or the shared `/inbox`
2. `SignatureService::checkRequest()` checks the `date` header for freshness, that `content-length` matches the body, that `digest` matches the body, and then the HTTP signature. It returns the verified origin host, or an empty string if the signature does not verify
3. `FediverseService::authorized()` is called with that origin. An empty origin is rejected, so a request with an unverifiable signature is refused here
4. `ImportService::importFromJson()` parses the body into a typed object
5. If the body carries a valid Linked Data Signature the origin is taken from it, otherwise the HTTP-signature origin is used
6. `ImportService::parseIncomingRequest()` looks the handler up with `AP::getInterfaceForItem()` and calls `processIncomingRequest()`. Every exception from the handler is logged and swallowed
7. The controller answers 200 and then drains the inbound stream queue for that request token

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
| `Announce` | Stored as a boost, notification generated |
| `Move` | Actions, follows, streams and cached documents are repointed to the target actor — but only after the target actor (refreshed from its server) lists the moving actor in its `alsoKnownAs`; a Move whose target does not acknowledge the actor is refused |

**Incoming activities that are accepted but do nothing:** `Add`, `Remove` and `Block` are dispatched to a handler that forwards to the wrapped object's `activity()` method, and no object handler recognises those activity types. `BlockInterface` in particular means Block is a no-op — the app has no blocking implementation.

**Object types that are dropped:** `AP::getItemFromType()` can build `Group`, `Organization`, `Application`, `OrderedCollection` and `Stream`, but `AP::getInterfaceFromType()` has no case for any of them. An incoming activity whose top-level type is one of these raises `ItemUnknownException`, which the inbox controller catches and ignores, so the message is silently discarded. In practice only Person and Service actors federate; Group, Organization and Application actors are not processed.

`Tombstone` has no interface either, and deliberately so: it names a deleted object rather than being one. `DeleteInterface` handles it by id — when an embedded object has no handler it looks the id up as a note, then as an actor, the same path a `Delete` carrying a bare id string takes. This is how a deletion from Mastodon, which sends `Delete` with an embedded `Tombstone`, is applied.

An incoming `Block` targeting a local user is remembered as a `blocked_by` relation and severs the follow relationship in both directions; `Undo{Block}` lifts it. A `Follow` from an actor the target has blocked is answered with a `Reject`.

### Discovery

`WellKnown/WebfingerHandler` is registered as a Nextcloud well-known handler and serves three services at the server root:

- **WebFinger:** `/.well-known/webfinger?resource=acct:user@domain` — returns the `self` link to the actor
- **NodeInfo:** `/.well-known/nodeinfo` — returns the discovery document pointing at the app's own `/apps/social/.well-known/nodeinfo/2.0` route (`OAuthController::nodeinfo2()`), which carries the actual server metadata
- **host-meta:** `/.well-known/host-meta`

All three return the previous handler's response untouched when `FediverseService::jailed()` says the instance is in allow-list mode with an empty list.

---

## Frontend Architecture

The user interface is a **Vue 3** front end using Vue Router, Vuex, `@nextcloud/vue` components, `@nextcloud/axios`, DOMPurify (via `src/utils/sanitizeHtml.js`), linkifyjs, and twemoji.

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

`src/store/index.js` registers four Vuex modules: `timeline`, `account`, `settings` and `errors`. Server-side state is not a store module — it is passed through Nextcloud's initial state as `serverData` and read by the `serverData` mixin.

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

`src/components/` holds the timeline and profile UI: `TimelineList`, `TimelineEntry`, `TimelinePost`, `TimelineAvatar`, `ActorAvatar`, `ProfileInfo`, `FollowButton`, `UserEntry`, `Navigation`, `Search`, `MediaAttachment`, `PostAttachment`, `Emoji`, `EmptyContent`, the `Composer/` group (`Composer`, `PreviewGrid`, `PreviewGridItem`, `SubmitStatusButton`), the `Visibility/` group (`VisibilitySelect`, `VisibilityIcon`), and `MessageContent.js`, a render-function component that parses a post body and rebuilds it as Vue nodes (turning mentions and hashtags into `router-link`s and emoji into `Emoji` components).

`Composer.vue` carries a full `tributeOptions` config for `@` account and `#` hashtag completion. `tributejs` is a plain DOM library rather than a component: it is attached to the contenteditable in `mounted()` and detached in `unmounted()`, and it appends its menu to the body, which the unscoped `.tribute-container` rule at the end of the file styles. The account collection searches `/api/v1/global/accounts/search` and the hashtag collection `/api/v1/global/tags/search`, both debounced. The emoji picker is a separate `NcEmojiPicker`.

---

## Integration Points

| Integration | Class | Registered in | Description |
|-------------|-------|---------------|-------------|
| Dashboard | `SocialWidget` | `Application::register()` | Recent Social notifications; loads `social-dashboard` |
| Dashboard | `SocialTimelineWidget` | `Application::register()` | Home timeline as an API widget, 300-second reload interval |
| Unified Search | `UnifiedSearchProvider` | `Application::register()` | Searches URIs, accounts, hashtags and **status content** (case-insensitive substring over the statuses the viewer may see: own posts, public/unlisted, and what is addressed to them — the timeline viewer bound). Local hits link to the post page, remote hits to their origin |
| Notifications | `Notifier` | `Application::register()` | Prepares Social notifications for the NC notification system |
| Profile Page | `ProfileSectionListener` | `Application::register()` (on `BeforeTemplateRenderedEvent`) | Adds the `social-profilePage` script to the user profile page |
| User Events | `UserAccountListener` | `Application::register()` (on `UserUpdatedEvent`) | Re-caches the local actor when the NC account changes |
| WebFinger / NodeInfo / host-meta | `WebfingerHandler` | `Application::register()` | ActivityPub discovery at the server root |
| Contacts Menu | `ContactsMenuProvider` | `appinfo/info.xml` | "Follow %s on Social" entry linking to the actor page |
| Background Jobs | `Cron\Cache` | `appinfo/info.xml` | 12-minute interval: reaps deleted actors, refreshes local and remote actor caches, caches documents, recomputes hashtag trends, prunes remote statuses past retention (bounded to 5000 per run), syncs remote timelines |
| Background Jobs | `Cron\Queue` | `appinfo/info.xml` | 12-minute interval: drains the outbound request queue and the inbound stream queue |
| Repair step | `Migration\RenameDocumentLocalCopy` | `appinfo/info.xml` | Post-migration repair of cached document paths |
| Repair step | `Migration\EncryptPrivateKeys` | `appinfo/info.xml` | Seals legacy plaintext actor private keys with ICrypto, once |
| Repair step | `Migration\HashClientSecrets` | `appinfo/info.xml` | Rewrites legacy plaintext client secrets/codes/tokens as sha256 digests, once |
| Repair step | `Migration\BackfillRemoteVisibility` | `appinfo/info.xml` | Backfills the empty visibility of remote statuses stored before estimation landed (public/unlisted set-based, followers/direct per author), idempotent |

Fifteen occ commands are registered in `appinfo/info.xml`. `lib/Command/` also holds `ExtendedBase.php`, which is the abstract base the others extend and is not itself a command. See `docs/OCC-Commands.md`.

---

## Security

**What is enforced**

- **HTTP Signatures on outbound requests** — every queued delivery is signed with the sending actor's RSA private key over `(request-target)`, `content-length`, `date`, `host` and `digest`
- **HTTP Signature verification on inbound requests** — `SignatureService::checkRequest()` requires `(request-target)`, `host`, `date` and `digest` to all be within the signed header set, so the signature binds the body and cannot be replayed against another host; it rejects a missing, stale or future `date` (±`DATE_DELAY`, 300 s), a `content-length` that disagrees with the body, and a `digest` that does not match. A signature that does not verify, or whose key cannot be retrieved, is refused by `checkRequest()` itself (it throws), rather than returning an empty origin for a later check to catch
- **Inbound inbox deliveries are rate-limited** — `InboxLimiter` caps deliveries per (claimed keyId host, source address) bucket per minute (`inbox_throttle` app setting, default 300, 0 disables) before any signature work, so a valid-but-hostile peer cannot fill the stream queue and a claimed-host lie only moves the flood into the liar's own bucket
- **LD signatures are bounded in time and replay-checked** — `checkObject()` refuses a signature whose `created` lies more than `LD_WINDOW` (24 h) from now, and remembers accepted signatures in a distributed cache for twice the window, so a captured activity cannot be re-POSTed indefinitely by an instance that once saw it
- **Linked Data Signatures** — outgoing Create, Update, Delete, Like, Announce and Undo carry an RsaSignature2017 signature; incoming ones are verified by `SignatureService::checkObject()`, which also retries against a refreshed public key. Follow and Accept are not LD-signed
- **Instance access control** — `FediverseService::authorized()` is checked on both inbox routes and on every outgoing `CurlService` request. It reads one app config value, `access_type`, which is either `all_but` (the default: everything is allowed unless the host is in the list) or `none_but` (only listed hosts, plus the local host, are allowed), together with a single host list in `access_list`; hosts are compared case-insensitively. `occ social:fediverse` manages both
- **Outbound requests cannot be steered at the local network.** Every request `CurlService` makes is restricted to `http`/`https` on the initial request and on redirects, and — unless the instance has set `allow_local_remote_servers` — a host that is or resolves to a private, loopback, link-local, multicast or otherwise reserved address is refused (`lib/Tools/RemoteAddress.php`). The banner-by-URL endpoint applies the same rules and a size ceiling before fetching
- **JSON-LD contexts are served only from the copies shipped in `context/`.** Signature normalisation never resolves a document's `@context` over the network, so a remote activity cannot make the server open an arbitrary URL and cannot substitute the bytes a signature is computed over; an unrecognised context makes the LD signature unverifiable rather than triggering a fetch
- **HTML sanitisation** — remote HTML reaches local timelines, so `ACore` runs `lib/Tools/HtmlSanitizer.php` over every `AS_CONTENT` field it imports, and strips tags from string, username and account fields. The frontend sanitises again with DOMPurify in `src/utils/sanitizeHtml.js`
- **Self-signed certificates** — TLS peer verification is skipped only when the `allow_self_signed` app config value is `1`

**Known gaps — these are real and deliberate to record**

- **Actor private keys are encrypted at rest.** `social_actor.private_key` holds the PEM encrypted with the instance secret (`ICrypto`, via `PrivateKeyCipher`), so a database dump alone is not enough to impersonate a local actor — it also takes the `secret` from `config.php`. Rows written before encryption existed (recognisable by their `-----BEGIN` prefix) are still readable and are rewritten once by the `EncryptPrivateKeys` repair step on upgrade
- **The federation endpoints are unauthenticated.** `ActivityPubController::actor()`, `actorAlias()`, `outbox()`, `followers()`, `following()` and `displayPost()` all carry `#[PublicPage]` with `#[NoCSRFRequired]`, and there is no signed-fetch (authorized-fetch) requirement. Any anonymous caller can read a local actor's profile, outbox, follower and following collections and individual posts
- **There is no blocking.** `BlockInterface` accepts an incoming Block and forwards it to a handler that ignores it, and the app never sends one. Per-actor blocks and mutes do not exist; the `mute`/`unmute` status actions are accepted by the API and discarded
- **The older dual blacklist/whitelist implementation in `FediverseService` is commented out**. What remains is the single-list `access_type`/`access_list` mechanism described above
- **`FediverseService::getKnownAddresses()` returns an empty array** unconditionally
- **The base URL is set once.** `ConfigService::setCloudUrl()` will overwrite it, but stored actor and stream ids embed the old URL, so changing it in practice requires `occ social:reset`

---

## Keeping this document in sync

This file, `docs/API.md` and `docs/OCC-Commands.md` describe the current implementation. They must be updated in the same change as the code they describe.

`tests/DocumentationTest.php` mechanically enforces the parts that can be checked — the registered occ commands, the HTTP routes, and the supported Nextcloud and PHP version ranges. Everything else is on the author of the change.
