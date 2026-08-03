# Nextcloud Social — Architecture Overview

## Introduction

Nextcloud Social is a federated social networking app built on the W3C ActivityPub standard. It integrates into Nextcloud as an app, providing each user with an ActivityPub identity (Person actor) that can interact with Mastodon, Friendica, and other Fediverse platforms.

**App ID:** `social`  
**Namespace:** `OCA\Social`  
**License:** AGPL-3.0-or-later  
**Minimum NC version:** 28  
**Maximum NC version:** 34  

---

## Directory Structure

```
social/
├── appinfo/                    # App metadata, routes, navigation, commands
├── lib/
│   ├── AP.php                  # ActivityPub type registry (factory)
│   ├── AppInfo/
│   │   └── Application.php     # Bootstrap, integration registration
│   ├── Command/                # 15 occ CLI commands
│   ├── Controller/             # 10 API/UI controllers
│   ├── Cron/                   # Background jobs (Cache, Queue)
│   ├── Dashboard/              # Nextcloud Dashboard widgets
│   ├── Db/                     # 34 database repository classes
│   ├── Exceptions/             # Custom exceptions
│   ├── Interfaces/             # ActivityPub typed interfaces
│   ├── Listeners/              # Event listeners (profile, user updates)
│   ├── Migration/              # Database schema migrations
│   ├── Model/                  # ActivityPub model objects + support models
│   ├── Notification/           # Nextcloud notification integration
│   ├── Providers/              # Contacts menu integration
│   ├── Search/                 # Unified search integration
│   ├── Service/                # 31 business logic services
│   ├── WellKnown/              # WebFinger handler
│   └── bootstrap.php           # Autoloader
├── src/                        # Vue 3 frontend (SPA)
│   ├── main.js                 # Entry point
│   ├── App.vue                 # Root component
│   ├── router.js               # 8 routes
│   ├── store/                  # 5 Vuex modules
│   ├── views/                  # 9 view components
│   ├── components/             # 17+ UI components
│   ├── services/               # JS services
│   ├── mixins/                 # Vue mixins
│   └── types/                  # JS type definitions
├── templates/                  # PHP templates (main SPA, OAuth)
├── l10n/                       # Translations (97 locales)
└── docs/                       # Documentation
```

---

## Database Schema

The app uses 11 database tables, all prefixed with `social_`:

| Table | Purpose |
|-------|---------|
| `social_actor` | Local user actors (tied to NC accounts, holds RSA key pairs) |
| `social_cache_actor` | Cached remote federated actors (inbox/outbox URLs, public keys) |
| `social_cache_doc` | Cached remote media attachments |
| `social_client` | OAuth 2.0 client registrations |
| `social_follow` | Follow relationships (actor → object) |
| `social_hashtag` | Hashtag trend data (counts per time window) |
| `social_instance` | Known federated instances (version, metadata) |
| `social_req_queue` | Outbound ActivityPub delivery queue |
| `social_stream` | Core content table: posts, notes, activities |
| `social_stream_act` | Per-user stream action state (liked, boosted) |
| `social_stream_dest` | Stream visibility targets (who sees what) |
| `social_stream_queue` | Inbound stream processing queue |
| `social_stream_tag` | Stream-to-hashtag mapping |

---

## Key Services

The business logic is organized into 31 service classes in `lib/Service/`:

### Account & Identity

- **AccountService** — Creates/deletes local ActivityPub actors with RSA key pairs, manages "blind key rotation", caches local actor data (avatar, display name, follower/following/post counts)
- **ActorService** — Syncs local actor avatar/header images from Nextcloud user settings
- **CacheActorService** — Central actor cache/resolver. Looks up actors by ID or `user@host` format. Fetches remote actors via WebFinger on cache-miss. Probes followers/following/outbox counts

### Content & Timelines

- **StreamService** — Core stream/timeline engine. Assigns ActivityPub IDs, manages recipients (public/unlisted/followers/direct), handles reply chains, detects stream types, fetches timelines (home, local, global, tag, account, liked, direct)
- **PostService** — Handles post creation and editing (text, attachments, reply-to, mentions, hashtags). Calls ActivityService to federate
- **FollowService** — Manages follow/unfollow flows with ActivityPub Federation
- **LikeService** — Creates/deletes Like/Favourite activities
- **BoostService** — Creates/deletes Announce (boost/reblog) activities
- **ActionService** — Dispatcher for status actions (favourite, unfavourite, boost, unboost, bookmark, pin)
- **HashtagService** — Computes hashtag trends over 1h/12h/1d/3d/10d windows

### Federation

- **ActivityService** — Creates, signs, and dispatches ActivityPub activities (Create, Update, Delete, Follow, Like, Announce, Undo). Signs outgoing requests with HTTP Signatures
- **ImportService** — Parses incoming ActivityPub JSON into typed model objects and dispatches to interface handlers
- **SignatureService** — RSA key generation, HTTP Signature signing/verification, LD-signature (RsaSignature2017) for JSON-LD objects
- **RequestQueueService** — Manages outbound delivery queue with priority levels and exponential backoff
- **CurlService** — Low-level HTTP client for ActivityPub requests and WebFinger lookups
- **FediverseService** — Federation access control (blacklist/whitelist per instance domain)
- **InstanceService** — Manages known instances metadata

### Media

- **DocumentService** — Manages cached document/media files
- **CacheDocumentService** — Downloads and caches remote media with blurhash generation

### System

- **ConfigService** — App configuration (cloud URL, social URL, download limits)
- **CheckService** — Installation health checks (WebFinger, URL configuration)
- **DetailsService** — Computes stream visibility (who sees a post)

---

## ActivityPub Federation

The app is a full ActivityPub **Server** that both produces and consumes ActivityPub protocol messages.

### Actor Identity

Each Nextcloud user gets a Person actor:
- **ID:** `https://cloud.tld/apps/social/@username`
- **Keys:** RSA 2048-bit key pair (generated per-actor)
- **Endpoints:** Inbox, shared inbox, outbox, followers, following

### Outgoing Flow

1. User action (post, like, follow, boost) → Service creates an Activity wrapper
2. `SignatureService::signObject()` adds Linked Data Signature
3. `ActivityService::request()` determines target inboxes based on recipients
4. `RequestQueueService` creates queue entries with priority levels
5. High-priority requests are sent synchronously; others are queued
6. `Cron\Queue` processes remaining entries with retry and backoff
7. `CurlService` makes signed HTTP POST requests to remote inboxes

**Supported outgoing activities:** Create, Update, Delete, Follow, Accept, Reject, Like, Announce, Undo, Add, Remove, Move, Block

### Incoming Flow

1. Remote instance POSTs to `/@{username}/inbox` or `/inbox`
2. HTTP Signature is verified (digest, date, content-length headers)
3. LD-signature on JSON body is verified
4. ImportService parses JSON into typed ActivityPub objects
5. Appropriate interface handler processes the activity
6. Content is stored in `social_stream`, federated to local recipients

**Supported incoming activities:** Create, Update, Delete, Follow, Accept, Reject, Like, Announce, Undo, Move, Block

### Discovery

- **WebFinger:** `/.well-known/webfinger?resource=acct:user@domain` returns `self` link to actor profile
- **NodeInfo:** `/.well-known/nodeinfo/2.0` returns server metadata

---

## Frontend Architecture

The user interface is a **Vue 3 Single Page Application (SPA)** using:

- **Vue Router** — 8 named routes for timelines, profiles, posts
- **Vuex** — 5 store modules (timeline, account, settings, serverData, errors)
- **Nextcloud Vue Components** — NcButton, NcAppNavigation, NcAppNavigationItem, NcActions, etc.
- **Axios** — HTTP client for API calls
- **Tribute.js** — @mention autocomplete in the composer
- **Custom components** — Composer, TimelinePost, TimelineEntry, ProfileInfo, MediaAttachment, Emoji picker, Visibility selector

The frontend is divided into separate entry bundles:
- `social-main.js` — Main SPA (timeline, profile, search)
- `social-dashboard.js` — Dashboard widgets
- `social-oauth.js` — OAuth authorization page
- `social-ostatus.js` — OStatus follow page
- `social-profilePage.js` — Public profile embedding

### Views

| View | Route | Purpose |
|------|-------|---------|
| Timeline | `/timeline/:type` | Timeline (home, local, global, direct, notifications, liked, hashtag) |
| TimelineSinglePost | `/@{user}/{id}` | Single post with reply context |
| Profile | `/@{user}` | User profile with posts tab |
| Profile | `/@{user}/followers` | User profile with followers tab |
| Profile | `/@{user}/following` | User profile with following tab |
| Dashboard | (widget) | Dashboard notification widget |
| OAuth2Authorize | `/oauth/authorize` | OAuth app authorization |

---

## Integration Points

| Integration | Class | Description |
|-------------|-------|-------------|
| Dashboard | `SocialWidget` | Shows recent social notifications |
| Dashboard | `SocialTimelineWidget` | Shows home timeline posts (5-minute refresh) |
| Unified Search | `UnifiedSearchProvider` | Searches accounts, hashtags, URIs |
| Notifications | `Notifier` | Prepares Social notifications for NC notification system |
| Contacts Menu | `ContactsMenuProvider` | "Follow on Social" button in contacts menu |
| Profile Page | `ProfileSectionListener` | Injects Social data into user profile |
| WebFinger | `WebfingerHandler` | ActivityPub discovery endpoint |
| User Events | `UserAccountListener` | Syncs NC display name changes to Social actor |
| Background Jobs | `Cron\Cache` | Periodic cache refresh and hashtag trends |
| Background Jobs | `Cron\Queue` | Processes outbound federation queue |

---

## Security

- **HTTP Signatures** — All outbound ActivityPub requests are signed with the sending actor's RSA private key
- **LD-Signatures** — JSON-LD activity objects are signed with RsaSignature2017
- **Signature Verification** — Incoming requests are verified before processing
- **Federation Access Control** — Instance-level blacklist/whitelist
- **Configuration** — Cloud base URL is fixed at setup and cannot be changed without resetting
- **Self-signed certificates** — Configurable support for development environments
