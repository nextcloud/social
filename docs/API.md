# Nextcloud Social API Reference

> This document is written by hand but mechanically checked: `tests/DocumentationTest.php` asserts that the set of routes documented here matches `appinfo/routes.php`. Every route below appears in a table row with its URL exactly as written in `appinfo/routes.php`. Paths that are *not* routes of this app (the `.well-known` discovery documents handled by the Nextcloud WellKnown API) are deliberately written without code spans so that check stays exact.

## Overview

The Social app exposes four groups of endpoints, all registered in `appinfo/routes.php`:

- **Mastodon-compatible REST API** (`ApiController`, `TagController`, `OAuthController`) — a partial implementation of the Mastodon client API. Several endpoints are stubs; each is marked below.
- **Custom Local API** (`LocalController`, `ConfigController`) — the endpoints the app's own Vue frontend calls. They are not Mastodon-compatible and their response envelope differs (see Error Responses).
- **ActivityPub Federation API** (`ActivityPubController`, `SocialPubController`) — server-to-server ActivityPub, plus the HTML profile/post pages served on the same URLs.
- **Frontend, document, OStatus and queue endpoints** (`NavigationController`, `OStatusController`, `QueueController`) — HTML pages and internal plumbing.

All URLs are relative to the app's route base, i.e. index.php/apps/social + the URL from the route table (for example, index.php/apps/social/api/v1/statuses).

### Deprecated: the superseded half of the Custom Local API

The Custom Local API predates the Mastodon-compatible one, and the frontend has
moved off most of it. Eighteen of `LocalController`'s thirty-one routes now have
no caller anywhere in `src/`:

| Deprecated | Use instead |
|---|---|
| `GET /api/v1/stream/home` | `GET /api/v1/timelines/home` |
| `GET /api/v1/stream/timeline` | `GET /api/v1/timelines/public?local=true` |
| `GET /api/v1/stream/federated` | `GET /api/v1/timelines/public` |
| `GET /api/v1/stream/tag/{hashtag}/` | `GET /api/v1/timelines/tag/{hashtag}` |
| `GET /api/v1/stream/direct` | `GET /api/v1/conversations` |
| `GET /api/v1/stream/liked` | `GET /api/v1/favourites` |
| `GET /api/v1/stream/notifications` | `GET /api/v1/notifications` |
| `GET /local/v1/post`, `GET /local/v1/post/replies` | `GET /api/v1/statuses/{nid}`, `…/context` |
| `POST`/`DELETE /api/v1/post/like` | `POST /api/v1/statuses/{nid}/favourite`, `…/unfavourite` |
| `GET /api/v1/current/info` | `GET /api/v1/accounts/verify_credentials` |
| `GET /api/v1/current/followers`, `…/following` | `GET /api/v1/accounts/{account}/followers`, `…/following` |
| `GET /api/v1/global/actor/info` | `GET /api/v1/accounts/{account}` |
| `GET /api/v1/global/actor/header` | the `header` field of the Account entity |
| `PUT /api/v1/account/summary` | `PATCH /api/v1/accounts/update_credentials` |
| `GET /local/v1/search` | `GET /api/v2/search` |

They still work and are still listed in the tables below. They are documented as
deprecated rather than removed because they are a published surface; nothing in
this app calls them, so the replacement column is what a caller should move to.

The thirteen that the frontend still uses -- the banner uploads, the
`global/account` and `global/tags` searches, the profile field and avatar
endpoints, `POST`/`DELETE /api/v1/post`, `/api/v1/current/follow` and
`/api/v1/account/{username}/stream` -- have no Mastodon equivalent yet and are
not deprecated.


---

## Authentication

Two mechanisms exist, and which one applies depends on the controller:

1. **OAuth Bearer token** — `ApiController` and `TagController` read it. The constructor parses the `Authorization` header, accepts a `bearer` auth type, and resolves the token through `ClientService::getFromToken()`. If no bearer token is present it falls back to the logged-in Nextcloud session user. Both declare their routes `#[PublicPage]` with `#[NoCSRFRequired]` and then require a viewer inside the handler: a Mastodon client has no Nextcloud session and no CSRF token, so `#[NoAdminRequired]` would refuse every real caller before the handler ran.
2. **Nextcloud session** — `LocalController`, `ConfigController`, `NavigationController`, `OAuthController` (authorize/authorizing) and `OStatusController` use the session `userId` only. They do **not** honour bearer tokens, so the Custom Local API is effectively usable only from the app's own frontend (or with a Nextcloud session cookie / app password + `OCS-APIRequest`).

Access control is declared with **PHP attributes** (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[NoAdminRequired]`, and `#[BruteForceProtection]` on the OAuth token/revoke endpoints); the legacy PHPDoc annotations are gone. The "Auth" column in the tables below records these attributes:

- `public` — `#[PublicPage]`: reachable without a Nextcloud login (an endpoint may still fail later if it needs a viewer).
- `user` — `#[NoAdminRequired]`: any logged-in user.
- `admin` — no attribute at all: Nextcloud requires an admin session.
- `no-csrf` — `#[NoCSRFRequired]`: no CSRF token needed, which matters for non-browser clients.

Note that many `ApiController` endpoints are annotated `@PublicPage` but call `initViewer(true)` internally, which throws when there is neither a session nor a valid bearer token; those return HTTP 401 with `{"error": "the access_token was revoked"}`.

---

## Mastodon-compatible API

### Instance and app metadata

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/instance/` | public, no-csrf | — | Mastodon's `V1::Instance` entity, built by `InstanceService::getLocal()` from the live configuration on every request (the stored row is written once at install and only marks that the instance exists). Carries `uri`, `title`, `version`, `short_description`, `description`, `email`, `urls`, `stats`, `thumbnail`, `languages`, `registrations` (always `false` — the client API cannot create an account), `approval_required`, `invites_enabled`, `configuration`, `rules` and `contact_account`. `urls`, `stats` and `configuration` are always JSON **objects**, never `[]`. `configuration` reports `statuses` (`max_characters` 5000, `max_media_attachments`, `characters_reserved_per_url`), `media_attachments` (`supported_mime_types`, asked of `CacheDocumentService::filterMimeTypes()` so it cannot drift from what an upload is actually allowed to be, plus `image_size_limit` and `video_size_limit` — the app's `max_size`, in bytes — and the pixel/frame-rate ceilings), `polls` (`max_options`, `max_characters_per_option`, the expiry bounds) and `accounts.max_featured_tags`. `urls` is an empty object: there is no streaming API, and a client handed a URL that never upgrades to a websocket falls back to polling only after a timeout. `stats.status_count` and `stats.domain_count` are counted from the database and memoised for a few minutes (`InstanceService::countedStats()`); `user_count` is the number of Nextcloud users, since an actor is created for a user the first time they open the app. Nothing is read back from the stored instance row, which holds whatever was true on the day the instance was set up. `rules` comes from one line per rule in the `rules` app value. The `version` field is Pleroma-style — `4.2.0 (compatible; Nextcloud Social <app version>)` — because clients gate features on it; NodeInfo keeps reporting the real app version. It said `3.5.0` for a long time and that was hiding finished work: editing with `/api/v1/statuses/{nid}/source` and `/api/v1/statuses/{nid}/history`, v2 filters, `/api/v2/instance` and `/api/v1/notifications/unread_count` are all 4.x features a client never asks for below that version. What is *not* here is announced rather than left to fail — `configuration.translation.enabled` is `false`, `urls` is empty (no streaming), and there is no Web Push, so a client falls back to polling. |
| GET | `/api/v2/instance` | public, no-csrf | — | The same facts as Mastodon's `V2::Instance`: `uri` becomes `domain`, `thumbnail` an object, and the flat contact/registration fields move under `contact` and `registrations`. It also adds `source_url` and `usage`, and folds `urls` and a `translation` block into `configuration`. Newer clients ask for this one first and only fall back to v1 on a 404. |
| GET | `/api/v1/instance/peers` | public, no-csrf | — | The hostnames of every instance this one has heard of, as a bare JSON array — what instance browsers and "about this server" pages read. Derived from the accounts of the cached remote actors, and from the same walk `stats.domain_count` uses, so the list and its size cannot disagree. Public, as Mastodon's is: it says who this instance federates with, not who its users are. Rate-limited per anonymous caller. |
| GET | `/api/v1/instance/activity` | public, no-csrf | — | Twelve weeks of activity, newest first, in Mastodon's shape — every value a string, each week keyed by the unix time its Monday began. `statuses` is counted from the database. `logins` and `registrations` are always `"0"` and cannot be otherwise: an account here is a Nextcloud user, so both belong to the server rather than to this app. Rate-limited per anonymous caller. |
| GET | `/api/v1/preferences` | public, no-csrf (viewer required) | — | The viewer's posting defaults, as Mastodon's Preferences entity: `posting:default:visibility` (the account's `source[privacy]`), `posting:default:sensitive`, `posting:default:language` (null when unset), and the two reading preferences at Mastodon's defaults (`reading:expand:media` `default`, `reading:expand:spoilers` `false`) — this app keeps no per-account reading preferences, and sending the defaults is what stops a client assuming something else. A client that cannot read this guesses, which is how it ends up posting publicly for somebody whose default is followers-only. |
| GET | `/api/v1/apps/verify_credentials` | public, no-csrf | — | `{"name", "website", "vapid_key"}` of the client behind the bearer token; falls back to `{"name": "Nextcloud Social", "website": "https://github.com/nextcloud/social/"}` when the caller is the app's own session. `vapid_key` is always present and always empty: there is no Web Push endpoint, and that is the answer that makes a client stop asking. A missing or revoked token is a 401. |
| GET | `/api/v1/custom_emojis` | public, no-csrf | — | The instance's own custom emoji — always `[]` (none can be created). *Remote* custom emoji are supported: `Emoji` tags on incoming statuses/actors are served in the `emojis` field of the status and account entities. |
| GET | `/api/v1/trends/tags` | public, no-csrf | `limit` (10, capped at 20), `period` (`1h`, `12h`, `1d` — the default —, `3d`, `10d`; anything else falls back to the default) | The hashtags used most on this instance in that window, as Mastodon `Tag` entities. The counts are the ones the cron job already keeps for every hashtag (`HashtagService::manageHashtags()`), so this is a read of stored data rather than a query over the stream — specifically the sortable `trend_*` columns, which `Version1000Date20260910000003` backfills on upgrade because the cron alone would never have filled them for hashtags whose counts had stopped moving. `history` carries a single bucket for the window that was asked for, and `accounts` in it is always `0`: this instance counts uses, not distinct accounts. Hashtags unused in the window are left out. |
| GET | `/api/saved_searches/list.json` | public, no-csrf | — | **Not implemented.** Initialises the viewer, then always returns `[]`. |
| GET | `/.well-known/nodeinfo/2.0` | public, no-csrf | — | NodeInfo 2.0 document: `version`, `software.name` (instance title), `software.version`, `protocols: ["activitypub"]`, `rootUrl`, `usage`, `openRegistrations`. Falls back to name `Nextcloud Social` and the installed app version if no local instance row exists. |

### Accounts

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/accounts/verify_credentials` | public, no-csrf | — | The viewer's `Person` actor serialised in local format. 401 `{"error": ...}` when unauthenticated. |
| PATCH | `/api/v1/accounts/update_credentials` | public, no-csrf (viewer required, `write` scope) | JSON/form body; `display_name`, `note`, `locked`, `discoverable`, `indexable`, `bot`, `source[privacy]`, `fields_attributes`, and `avatar` / `header` (multipart) | Sets the display name, the bio (`note`, at most 500 characters, stored as plain text and rendered to HTML on the way out — an absent `note` leaves the stored one alone rather than clearing it), the profile picture and banner, whether new followers need manual approval (`manuallyApprovesFollowers`), whether the account may be listed in directories and indexed for search, whether it is automated (`bot`, which also decides whether the actor document says `Service` or `Person`), the default audience for new posts, and the profile metadata fields (at most four name/value pairs, both halves required; a list or an object keyed by index). Every field is optional and only what was sent is written, so a client that edits one thing leaves the rest alone. **`display_name`, `avatar` and `bot` used to be accepted and dropped**, which meant a profile editor — which sends the whole form in one PATCH — got a 200 and showed the old name and picture. The name and picture belong to the Nextcloud account, so they are written there and the actor cache is refreshed; a backend that owns either (LDAP, SAML, anything provisioned elsewhere) makes the request a **422** rather than a silent success. Other Mastodon profile fields are still ignored. Returns the refreshed account entity, which is the one place besides `verify_credentials` that carries `source`. |
| GET | `/api/v1/follow_requests` | public, no-csrf (viewer required) | — | Accounts with a pending follow request towards the viewer, serialised in local format. |
| POST | `/api/v1/follow_requests/{id}/authorize` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Accepts the pending follow request from account `{id}` (numeric id or full actor id; accepts slashes): federates the `Accept` and marks the follow accepted. Returns the updated relationship entity; 404 when no request is pending. |
| POST | `/api/v1/follow_requests/{id}/reject` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Rejects the pending follow request from account `{id}`: federates a `Reject` and deletes the follow row. Returns the updated relationship entity; 404 when no request is pending. |
| POST | `/api/v1/accounts/{id}/remove_from_followers` | public, no-csrf (viewer required, `write:follows` or `follow` scope) | — | Removes account `{id}` from the viewer's followers (numeric id, actor id or handle; accepts slashes): federates a `Reject` of their follow and drops the row, which is the same activity a refused follow request sends — to the other server a follow rejected and a follow withdrawn after being accepted are one statement. This is **not** a block and **not** an unfollow: whether the viewer follows them, and every block and mute either way, are left as they were. An account that does not follow the viewer is not an error, so a client that lost the answer and retried gets the same one. Returns the updated relationship entity. |
| GET | `/api/v1/blocks` | public, no-csrf (viewer required) | `limit` (40, capped at 50) | Accounts the viewer has blocked. One page, and **no `Link` header**: neither route takes a cursor, so the "next" page a header advertised was the page just sent, and a client paging on it scrolled the same block of accounts for ever. |
| GET | `/api/v1/mutes` | public, no-csrf (viewer required) | `limit` (40, capped at 50) | Accounts the viewer has muted. Same shape, and no `Link` header, for the same reason. |
| GET | `/api/v1/accounts/relationships` | public, no-csrf | `id` (array, default `[]`) | Relationship entries from `FollowService::getRelationships()`. Sent as `id[]=…` on the wire. The parameter carries a default because the dispatcher fills it in before the handler's own error handling can run: a request with no `id[]` at all used to produce a Nextcloud HTML page instead of `{"error": …}`. With none given the answer is an empty list. |
| POST | `/api/v1/accounts/{id}/follow` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Follows the account (`{id}` is the numeric id or a full actor id; accepts slashes). A locked target leaves the relationship in `requested` until they decide. Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/unfollow` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Unfollows (federates `Undo{Follow}`). Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/block` | public, no-csrf (viewer required) | — | Blocks the account (`{id}` is the numeric id or a full actor id; accepts slashes). Severs the follow relationship in both directions and, unless `federate_blocks` is `0`, sends a `Block` activity to the account's server. Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/unblock` | public, no-csrf (viewer required) | — | Lifts a block (federates `Undo{Block}` under the same setting). Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/mute` | public, no-csrf (viewer required) | `notifications` (true) | Mutes the account — purely local, never federated. With `notifications=true` (default) the account's notifications are hidden too. Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/unmute` | public, no-csrf (viewer required) | — | Lifts a mute. Returns the updated relationship entity. |
| GET | `/api/v1/accounts/familiar_followers` | public, no-csrf (viewer required) | `id` (one or more account ids) | For each named account, which of the people the viewer follows also follow it — the "followed by X and 3 others you know" line on a profile. Answers `[{id, accounts}]` with at most ten accounts each, and at most twenty ids per call (Mastodon's caps). The overlap is found by one self-join in the database rather than by intersecting two follower lists in PHP: either list can be tens of thousands of rows. The viewer's own profile answers an empty list, as Mastodon's does. |
| GET | `/api/v1/accounts/search` | public, no-csrf (viewer required) | `q` (required), `limit` (40, capped at 80), `resolve` (false), `following` (false) | Mastodon's account search — what a composer calls to complete a `@handle` as somebody types. `/api/v2/search` answers accounts too, but no client uses it for autocomplete, so mention completion failed in every client that offers it. Searches the cached actors; with `resolve` it also asks the address or handle's own server, which is what makes completing a handle from another instance work at all. `following=true` narrows the answer to accounts the viewer follows, which is what a client asks when completing a reply rather than searching. An empty `q` is an empty list, not an error. Registered **before** `/api/v1/accounts/{id}`, which accepts slashes and would otherwise swallow it. Rate-limited per user and per anonymous caller. |
| GET | `/api/v1/accounts/{id}` | public, no-csrf | — | One account, by the numeric id every API entity emits, an `@user` / `user@host` handle, a bare local username, or the actor's ActivityPub id — all resolved through `resolveTargetAccount()`. Returns the account entity in local format; an unknown reference is a 404. `{id}` accepts slashes (`requirements: .+`), so this route is registered **last** of the /api/v1/accounts routes and must stay there — otherwise it swallows `lookup`, `relationships`, `verify_credentials` and the `{account}` sub-routes. Rate-limited per user and per anonymous caller. |
| GET | `/api/v1/accounts/lookup` | public, no-csrf | `acct` (required) | The account behind a handle, from what is already cached — this route never fetches from another server. A leading `@` is stripped. 404 when the handle is unknown here, 422 when `acct` is missing. Rate-limited per user and per anonymous caller. |
| GET | `/api/v1/accounts/{account}/statuses` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `pinned` (false) | Statuses of `{account}`, which may be the numeric id or a handle; syncs the remote timeline first. `{account}` accepts slashes (`requirements: .+`). With `pinned=true` it returns that account's pinned posts, newest pin first, and skips the remote sync and the paging parameters. Pinned posts are read through the same visibility filter as any other status, so an anonymous caller sees only the public ones — this route is a `#[PublicPage]`, and reading them unfiltered served a pinned followers-only post in full to the internet. Sends a `Link` header. |
| GET | `/api/v1/accounts/{account}/followers` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since` (0) | Followers of `{account}` (numeric id or handle). For a remote domain the actor's `followers` collection is fetched over HTTP, each entry is resolved through the actor cache, and any actor with no numeric id is left out — a page of accounts all sharing id `"0"` is one a client cannot act on. Otherwise the local cache is probed and a `Link` header is sent. Note the fourth parameter is `since`, not `since_id`. |
| GET | `/api/v1/accounts/{account}/following` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since` (0) | Same as above for the `following` collection. |

A `Relationship` carries `id` as a **string**, like every other id on the wire: a client that declares `id: String` (Ivory, Mona, anything built on Swift's Codable) could not decode the integer this used to be, so follow/block/mute button state broke after every action that returns one. `note`, `languages` (always `null` — there is no per-account language filter) and `requested_by` are also present; `requested_by` reports a pending incoming follow — the same rows `/api/v1/follow_requests` lists — and `showing_reblogs` is `true`, which is what the home timeline actually does. `note` carries the viewer's private note about the account, written through `POST /api/v1/accounts/{id}/note` (see below) and readable by nobody else. Not to be confused with `note` on an *Account*, which is the bio and is implemented (see `update_credentials` above).

### Search (Mastodon v2)

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/search` | public, no-csrf (viewer required) | `q` (required), `type`, `limit` (20, capped at 40), `resolve` | Mastodon's v1 search; identical to `/api/v2/search`, which it delegates to. This path used to be the app's own web-UI search, which answered a client with a Nextcloud envelope and a `content` key — a 200 a client could make nothing of. That search moved to `/local/v1/search`. |
| GET | `/api/v2/search` | public, no-csrf (viewer required) | `q` (required), `type` (`accounts`/`statuses`/`hashtags`, empty = all), `limit` (20, capped at 40), `resolve` | Mastodon's search entity: `accounts` (URI + name search over cached actors), `statuses` (the viewer-bounded full-text search), `hashtags` (Tag entities with an empty `history`). `resolve` asks the server to go and get something it has never seen, which is how a reader who pasted a link to a remote post can reply to it or boost it here at all. It only ever fires for a `q` that is an `http(s)` address and only when the local search found nothing; the document fetched has to claim the very address it was fetched from (a document is evidence about itself and nothing else) and has to be a `Note` or a `Question`, or it is refused and the answer is empty. The author is cached before the post is stored, so it renders as theirs. An account named by its actor URL is resolved whether or not `resolve` is set — that predates this parameter. The route requires a viewer, so no anonymous caller can use it to make this instance fetch for them, and it is rate-limited both per user and per anonymous caller. Pagination offsets are accepted but ignored. Rate-limited per user and per anonymous caller. |

### Statuses

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/statuses` | public, no-csrf (viewer required, `write` scope) | Body (JSON or form-encoded): `status`, `visibility`, `media_ids` (array), `in_reply_to_id`, `quote_id`, `spoiler_text`, `sensitive`. Header: `Idempotency-Key` | Creates a post. The body is read from `php://input` and parsed by `Content-Type`; a body that declares itself JSON and is not valid JSON is a **422**, not the HTML error page a `TypeError` used to produce. Only `status`, `visibility`, `media_ids`, `in_reply_to_id`, `quote_id`, `poll`, `spoiler_text` and `sensitive` affect the created post — `sensitive` is stored in its own column and read back (a non-empty `spoiler_text` also reads as sensitive); `spoiler_text` is stored as plain text, so a warning containing an apostrophe stays an apostrophe on the wire. `visibility` maps to the stream type (`public`, `unlisted`, `followers`/`private`, `direct`); **omitting it takes the account's default** (`source[privacy]`, public unless the account set otherwise), and a value this app does not know is a **422** rather than a post silently addressed to nobody. `quote_id` quotes another post: the id is resolved the same way `in_reply_to_id` is, and the post is published immediately while the quoted author's server is asked for permission in the background (see **Quote posts** in `docs/Architecture.md`) — so a quote starts out `pending` and becomes `accepted` only when the approval arrives. A status whose text and content warning together run past the instance's `max_characters` is a **422**, counted in characters rather than bytes. An `Idempotency-Key` is remembered against the created status for an hour, scoped to the presenting token, and a repeat of the same key returns the same status instead of posting again. Attaching media also decides whether those attachments are world-readable: an attachment only becomes public when the post is `public` or `unlisted`. `scheduled_at` (ISO 8601) publishes the post later instead of now: the answer is a **ScheduledStatus** entity — `id`, `scheduled_at`, `params`, `media_attachments`, and no `content`, `account` or `created_at` — and nothing is posted until `Cron\ScheduledPosts` picks it up. It must be at least **5 minutes** in the future; sooner is a **422**, as is a value that is not a date (it used to be accepted and the post published at once). An account may have 300 scheduled statuses in total and 25 for any one day; past either is a **422**. The visibility is resolved when the post is scheduled and stored resolved, so changing `source[privacy]` afterwards cannot move the audience of a post that is already waiting. `Idempotency-Key` does not apply — a scheduled post creates no status to remember. Rate-limited per user. |
| GET | `/api/v1/statuses/{nid}` | public, no-csrf | — | One status by numeric id, local export format. |
| PUT | `/api/v1/statuses/{nid}` | public, no-csrf (viewer required, `write` scope) | Body: `status`, `spoiler_text`, `sensitive` | Edits an own post via `PostService::editPost()`. An empty `spoiler_text` is sent as `null`, meaning "leave it alone"; a given one is stored as plain text, the same as on create. `sensitive` is *not* treated that way — it is read as a boolean, so an edit that omits it clears the flag. Editing stamps `published` with the moment of the edit while `created_at` keeps the original, which is what the status entity's `edited_at` is derived from. |
| DELETE | `/api/v1/statuses/{nid}` | public, no-csrf (viewer required, `write` scope) | — | Deletes one of the viewer's own statuses and answers with the status that was removed, which is what a client's "delete & redraft" puts back in the composer. Somebody else's status is a 404, the same answer an unknown id gets. |
| GET | `/api/v1/statuses/{nid}/source` | public, no-csrf (viewer required) | — | Mastodon's `StatusSource`: `{"id", "text", "spoiler_text"}` for one of the viewer's own statuses. The stored content is the HTML rendered from the original text, so it is turned back — `<br>` becomes a newline, the remaining tags are stripped and entities are decoded. Tusky and Ivory will not offer their edit button without this route, even though `PUT /api/v1/statuses/{nid}` has always worked. |
| GET | `/api/v1/statuses/{nid}/context` | public, no-csrf | — | Ancestors/descendants of the status. |
| GET | `/api/v1/statuses/{nid}/favourited_by` | public, no-csrf (viewer optional) | `limit` (40, capped at 80) | The accounts that favourited the status, newest first, as `Account` entities. The status is resolved through the visibility filter first, so one the caller may not read is a **404** and no reaction of it is looked at — who liked a post is as private as the post. An account this instance has never cached is left out rather than sent without a handle or an avatar. No `Link` header. |
| GET | `/api/v1/statuses/{nid}/reblogged_by` | public, no-csrf (viewer optional) | `limit` (40, capped at 80) | The accounts that boosted the status, newest first. Same rules as `favourited_by`. |
| POST | `/api/v1/statuses/{nid}/{act}` | public, no-csrf | `act` (path) | Performs an action on a status — see the action table below. |

`{act}` is validated against `ActionService::$availableStatusAction`. Accepted values are `translate`, `favourite`, `unfavourite`, `reblog`, `unreblog`, `bookmark`, `unbookmark`, `mute`, `unmute`, `pin`, `unpin`; anything else throws `InvalidActionException`:

| `act` value | Effect |
|-------------|--------|
| `favourite`, `unfavourite` | Creates/deletes a Like (`LikeService`). |
| `reblog`, `unreblog` | Creates/deletes an Announce (`BoostService`). The Mastodon-ish names `boost` and `unboost` are **not** accepted. |
| `bookmark`, `unbookmark` | Toggles the viewer's local bookmark flag (`social_stream_act.bookmarked`). Purely local, never federated; the bookmarked posts are served by `/api/v1/bookmarks`. |
| `translate` | Returns the status unchanged (translation is a TODO). |
| `pin`, `unpin` | Pins/unpins one of **your own local, public or unlisted posts** to your profile (`PinService`), and tells the followers with an `Add` or `Remove` naming the actor's `featured` collection — a peer has nothing else to prompt it to re-read that collection. At most 5 pins; pinning somebody else's post, a remote post, a followers-only or direct post, or exceeding the limit raises `InvalidActionException` (a 422). The visibility rule is Mastodon's pinnable set, and it exists because the `featured` collection is served to anonymous callers. A pin is stored as a `Pin` row in `social_action` and published in the actor's `featured` collection — it is never federated as an activity of its own. |
| `mute`, `unmute` | **Not implemented** — refused with `InvalidActionException` rather than silently accepted, so a client never displays a state that was not stored. |

The response is the status itself in local format.

In that format, `in_reply_to_id` and `in_reply_to_account_id` carry the parent status's numeric id and its author's, resolved from the stored ActivityPub id (memoised per request, so a thread's replies are one lookup). Both are `null` for a post that is not a reply and for a parent this instance has never seen. `tags` is Mastodon's `[{name, url}]`; `Note::jsonSerialize()` still emits the app's own `hashtags: ["foo"]` alongside it when the status is exported with complete details. `edited_at` is when the post was last edited, or `null`. `quote` is Mastodon 4.5's Quote entity — `{state, quoted_status}` — or `null` for a post that quotes nothing. `state` comes from the quoted author's approval, not from whether this instance holds the post: `pending` until they answer, then `accepted`, `rejected`, or `revoked` if they take it back. `quoted_status` carries the quoted post inline only when the quote is accepted **and** the viewer may read it — an accepted quote of a post this reader may not see is `accepted` with a null `quoted_status`, so a quote never becomes a way of reading somebody's followers-only post. Mention ids are strings even when the handle could not be resolved. `attachment` — an ActivityPub-named duplicate of `media_attachments` — is no longer part of the client format.

### Scheduled statuses

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/scheduled_statuses` | public, no-csrf (viewer required) | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's waiting posts, soonest first, as ScheduledStatus entities with their attachments already expanded. Sends **no `Link` header** — a ScheduledStatus carries no status nid to page on — but the three cursors are honoured; they page on the scheduled-status row id. |
| GET | `/api/v1/scheduled_statuses/{id}` | public, no-csrf (viewer required) | — | One waiting post. One that is not the viewer's is a **404**, which is also the answer for one that does not exist. |
| PUT | `/api/v1/scheduled_statuses/{id}` | public, no-csrf (viewer required, `write` scope) | Body (JSON or form-encoded): `scheduled_at` (required) | Moves a waiting post to another time. The five-minute minimum applies again; a missing or unparseable `scheduled_at` is a **422**, somebody else's post a **404**. Moving a post inside the day it is already in is not counted against that day's cap. |
| DELETE | `/api/v1/scheduled_statuses/{id}` | public, no-csrf (viewer required, `write` scope) | — | Cancels a waiting post and answers `{}`. Cancelling one that is gone, or one that is not the viewer's, is a **404**. |

A due post is published by `Cron\ScheduledPosts` down the same path an immediate post takes (`PostService::createPost()`), with the request replayed from `params`. The row is deleted before the post is attempted, so exactly one worker publishes it; a post that cannot be published is logged at `warning` and not retried, because the alternative federates it twice.

### Lists

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/lists` | public, no-csrf (viewer required, `read:lists` scope) | — | Every list the viewer owns, oldest first, as Mastodon `List` entities. Unpaged, as Mastodon's is: a client draws the whole sidebar from one call. |
| POST | `/api/v1/lists` | public, no-csrf (viewer required, `write:lists` scope) | `title` (required), `replies_policy` (`list`), `exclusive` (false) | Creates a list and returns it. A blank or whitespace-only title is a **422**, and a `replies_policy` outside `followed`/`list`/`none` is a **422** rather than quietly stored as the default. Titles are not unique: two lists called "Friends" are two lists, as on Mastodon. |
| GET | `/api/v1/lists/{id}` | public, no-csrf (viewer required, `read:lists` scope) | — | One `List`. A list that is not there and a list that is somebody else's are the same **404**: telling them apart would say whether an id exists. |
| PUT | `/api/v1/lists/{id}` | public, no-csrf (viewer required, `write:lists` scope) | `title` (required), `replies_policy`, `exclusive` | Updates the list and returns it. `title` is required on an update as on a create, so an absent one is a 422 rather than "keep what is there". Omitted `replies_policy`/`exclusive` are left alone. |
| DELETE | `/api/v1/lists/{id}` | public, no-csrf (viewer required, `write:lists` scope) | — | Deletes the list and its memberships, and answers `{}`. |
| GET | `/api/v1/lists/{id}/accounts` | public, no-csrf (viewer required, `read:lists` scope) | `limit` (40, capped at 80; `0` means all, bounded at 500), `max_id` (0), `min_id` (0) | The list's members as `Account` entities, newest addition first. Sends a `Link` header whose cursor is the `social_list_member` row id, not the account: an account can be removed from a list and added again, so its own id does not move in one direction. A member whose actor is no longer cached is left out of the page rather than sent half-filled, but its row still decides the cursor, so paging does not stall on it. |
| POST | `/api/v1/lists/{id}/accounts` | public, no-csrf (viewer required, `write:lists` scope) | `account_ids[]` (required; a bare `account_ids` is accepted too) | Adds accounts and answers `{}`. A list is a view of what the owner already follows, so an account they neither follow nor have a pending follow request to is a **404** — Mastodon's answer, because the follow the membership would attach to is what is missing. The owner may be in their own list without following themselves. Every id is resolved before anything is written, so a request naming one account that may not be added adds none of them. Adding an account already in the list is a no-op. |
| DELETE | `/api/v1/lists/{id}/accounts` | public, no-csrf (viewer required, `write:lists` scope) | `account_ids[]` (required) | Removes accounts and answers `{}`. No follow is required — unfollowing somebody must not leave them stuck in a list — and removing one that is not in the list is not an error. |
| GET | `/api/v1/accounts/{account}/lists` | public, no-csrf (viewer required, `read:lists` scope) | — | Which of **the viewer's own** lists `{account}` is in. Never anybody else's: which lists a stranger put somebody in is not a thing either of them may read. |

A list is private to the account that made it, and that is the whole of its access model — there is no sharing and no visibility flag. Every route above resolves its list through `ListsRequest::getOwnedById()`, which carries the owner as a predicate of the SQL statement rather than as a check made after the row was read.

`replies_policy` and `exclusive` are stored and handed back faithfully, and **neither yet changes which posts a timeline selects**: `exclusive` does not remove members from the home timeline, and `replies_policy` does not filter replies out of the list timeline.

### Conversations

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/conversations` | public, no-csrf (viewer required, `read:statuses` or `read` scope) | `limit` (20, capped at 40), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's direct messages grouped into threads, as Mastodon `Conversation` entities — `{id, unread, accounts, last_status}` — newest message first. This is the screen Tusky, Ivory, Ice Cubes and Phanpy read direct messages from. `accounts` are the other participants and never the viewer, so a note to self has an empty `accounts`; `last_status` is nullable, as Mastodon declares it. Sends a `Link` header whose cursor is a **message** nid, not a conversation id: conversations are ordered by their newest message, and a conversation id does not move when a message arrives, so it cannot page. |
| POST | `/api/v1/conversations/{id}/read` | public, no-csrf (viewer required, `write:conversations` or `write` scope) | — | Marks the conversation read up to its newest message and answers with the `Conversation`. The mark is a position, not a flag: a message arriving afterwards makes the conversation unread again. Marking read twice is a no-op, and the mark never moves backwards. |
| DELETE | `/api/v1/conversations/{id}` | public, no-csrf (viewer required, `write:conversations` or `write` scope) | — | Removes the conversation from the list and answers `{}`. No message is deleted — Mastodon deletes its conversation row and leaves the statuses — and a later message in the same thread brings the conversation back. |

A conversation here is **derived**, not stored. Mastodon keeps a conversation row and takes the id from it; this app keeps messages, and the only thing the messages of one exchange share is their chain of `in_reply_to`. A conversation is therefore the thread, and its id is the nid of the thread root — the message with no parent, or the topmost parent this instance stores. That is what makes the id survive the round trip: a client reads the list, the user taps a row minutes later, and the id they send back names the same thread.

Two consequences a client can see. A thread whose root this instance never received groups under the topmost message it does have, so its id changes if that parent arrives later. And because paging is by message, a thread with messages on both sides of the cursor can appear on two pages, the second time with an older `last_status`; a client keys on `id` and updates the row rather than growing a second one.

What an account has *done* with a thread is stored, since it cannot be derived: `social_convo_state` holds how far the account has read it and how far it has dismissed it, both as the nid of the newest message the action covered. A thread with no row has been neither read nor dismissed, which is what an instance upgrading into the table starts with — nothing to backfill. `unread` is false for a message the viewer sent themselves: writing a message is having read the conversation, as on Mastodon.

A conversation the viewer is no part of, and one that does not exist, are the same **404**: membership is decided by the `dm` rows in `social_stream_dest` — the same predicate that put the message in the direct timeline — so telling the two apart would say whether a thread exists and who is in it.

### Keyword filters

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v2/filters` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | Every keyword filter of the viewer, newest first, as Mastodon v2 `Filter` entities. Not paged, as Mastodon does not page it: an account has a handful of filters and a client needs all of them to decide what to blur. `statuses` is always `[]` — this app has no per-status filters — and the key is sent because a client that declares it non-optional cannot decode the entity without it. |
| POST | `/api/v2/filters` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `title`, `context[]` (`home`, `notifications`, `public`, `thread`, `account`), `filter_action` (`warn` default, or `hide`), `expires_in` (seconds; absent means never), `keywords_attributes[][keyword]`, `[][whole_word]` | Creates a filter and answers with it. A missing `title`, a `context` naming none of the five, an unknown `filter_action` and an empty keyword are each a **422**: a filter with no context applies nowhere, and one with an empty keyword would match every status there is. |
| GET | `/api/v2/filters/{id}` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | One filter of the viewer. Somebody else's is a **404**, not a 403: whether another account has a filter is not this route's to tell. |
| PUT | `/api/v2/filters/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `title`, `context[]`, `filter_action`, `expires_in`, `keywords_attributes[][id]`, `[][keyword]`, `[][whole_word]`, `[][_destroy]` | Changes a filter. What is not named is left as it is, so a client sending only `title` does not thereby clear the contexts, the expiry or the keywords. `keywords_attributes` edits in place: an entry with an `id` changes that keyword, one with `_destroy` removes it, one without an id adds it. A keyword id belonging to another filter — the viewer's own included — is a 404. |
| DELETE | `/api/v2/filters/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | — | Deletes the filter and its keywords, and answers `{}`. |
| GET | `/api/v2/filters/{id}/keywords` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | The `FilterKeyword` entities of that filter. |
| POST | `/api/v2/filters/{id}/keywords` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `keyword`, `whole_word` | Adds one keyword and answers with it. |
| GET | `/api/v2/filters/keywords/{id}` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | One keyword of one of the viewer's filters; anybody else's is a 404. |
| PUT | `/api/v2/filters/keywords/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `keyword`, `whole_word` | Changes it. What is not named is left as it is. |
| DELETE | `/api/v2/filters/keywords/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | — | Removes the keyword; the filter stays. Answers `{}`. |
| GET | `/api/v1/filters` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | Mastodon's **v1** filters, served over the v2 ones. v1 has no notion of a filter with several keywords — a filter *is* a phrase — so a v1 filter here is a v2 **keyword**, carrying its parent's contexts, expiry and action. That is the mapping Mastodon serves for clients that have not moved, and the reason the ids in the two APIs are different things. The entity is `{id, phrase, context, whole_word, expires_at, irreversible}`, where `irreversible` is v1's name for `filter_action: hide`. |
| POST | `/api/v1/filters` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `phrase`, `context[]`, `irreversible`, `whole_word`, `expires_in` | Creates a v2 filter whose title is the phrase, holding that one keyword, and answers with the v1 entity. |
| GET | `/api/v1/filters/{id}` | public, no-csrf (viewer required, `read:filters` or `read` scope) | — | One v1 filter — one keyword of one of the viewer's filters. Anybody else's is a **404**. |
| PUT | `/api/v1/filters/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | `phrase`, `context[]`, `irreversible`, `whole_word`, `expires_in` | Changes it; what is not named is left as it is. |
| DELETE | `/api/v1/filters/{id}` | public, no-csrf (viewer required, `write:filters` or `write` scope) | — | Removes the keyword, and the filter with it when that was its last one — a v2 filter with no keywords matches nothing, and leaving one behind would appear in the v2 list as an empty filter the user never made. Answers `{}`. |

A filter matches on everything of a status a reader reads: the content warning, the text with its markup taken out and its entities decoded, the descriptions of the attachments and the options of a poll. A boost is matched on **the status it boosts**, which is the only reading under which a filter cannot be escaped by boosting. `whole_word` puts a word boundary on each side of the keyword, but only on a side that starts or ends with a word character — `\b` before a `#` can never hold, and anchoring it blindly would make `#spoiler` match nothing at all. Comparison is case-insensitive and Unicode-aware, and a keyword is never run as a pattern.

A filter stops applying the moment its `expires_at` passes: the expiry is a predicate of every read, not a row something deletes, so an instance with no working cron behaves like one that has.

### Discovery, trends and relationships

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/trends/statuses` | public, no-csrf | `limit` (20, capped at 40), `offset` (0), `period` (`1h`, `12h`, `1d` — the default —, `3d`, `10d`) | The public statuses interacted with most in that window, most interactions first, with their link previews attached. Counted live from `social_action` — the rows a like and a boost already write — rather than from a stored counter, so the trend cannot drift from the counts a status reports. Public `Note`s only: this is an unauthenticated route, and an aggregate over followers-only posts would report on them to the whole internet even if it never showed one. A status nobody touched in the window is absent rather than a zero at the end. `period` is this app's own parameter, shared with `/api/v1/trends/tags` so an explore page sees one stretch of time; Mastodon has none and gets the default. |
| GET | `/api/v1/trends/links` | public, no-csrf | `limit` (20, capped at 40), `offset` (0), `period` (as above) | The links most often attached to a public status in that window, as Mastodon `Trends::Link` entities. Counted by url, not by card row: the same article posted by five accounts is one trending link. The card half is the stored preview, serialised by the same class the card on a status uses, so the two cannot disagree about a page. A url still being shared whose preview row went with the post it was fetched for is answered as a bare link rather than dropped. `history` carries a single bucket and `accounts` in it is always `0`: this instance counts uses, not distinct accounts. |
| GET | `/api/v1/directory` | public, no-csrf | `offset` (0), `limit` (40, capped at 80), `order` (`active` default, or `new`), `local` (accepted, no effect) | The local profile directory: the accounts that set `discoverable`. **Opt-in, and that is the whole access rule** — the flag has been stored and federated since `Version1000Date20260911000002` and was read by nothing, so turning it off changed nothing because there was no listing to be kept out of. It is a predicate of the deciding query, so an account that did not opt in is never read and then dropped. `active` orders by when the account last posted in public, accounts that never have at the end; `new` by when it was created. Silenced and suspended accounts are not listed — removing an account from the public timeline and leaving it in the shop window is the same mistake twice. Remote accounts are never listed, which is why `local` makes no difference. |
| GET | `/api/v2/suggestions` | public, no-csrf (viewer required, `read` scope) | `limit` (40, capped at 80) | Accounts to follow, as Mastodon `Suggestion` entities. Derived from two things the app already has: the accounts followed by the accounts the viewer follows, ranked by how many of them do (`friends_of_friends`), then — for a viewer whose graph has nothing to say — local accounts that opted in to the directory, most recently active first. No scoring model: both halves are facts that can be counted. The deprecated `source` field is sent beside `sources`, because clients in the wild read one or the other. Never the viewer, an account they already follow **or have a pending request to**, one they have blocked or muted, one that has blocked them, or one under a moderation decision — every exclusion applies to both halves, since they come from different queries. |
| GET | `/api/v1/suggestions` | public, no-csrf (viewer required, `read` scope) | `limit` (40, capped at 80) | The same list in Mastodon's v1 shape: bare `Account` entities without the source that explains them. Served rather than deprecated away, because a client that never moved to v2 would otherwise draw an empty "who to follow" panel. |
| GET | `/api/v1/featured_tags` | public, no-csrf (viewer required, `read:accounts` or `read` scope) | — | The viewer's own featured tags — the hashtags they pin to their profile. Unpaged, as Mastodon's is. `statuses_count` and `last_status_at` are counted at read time over the account's public and unlisted posts, not stored, so they cannot disagree with the tag timeline a visitor gets by clicking through; `last_status_at` is a date and is `null`, not `""`, for a tag nothing has been posted with. |
| POST | `/api/v1/featured_tags` | public, no-csrf (viewer required, `write:accounts` or `write` scope) | `name` (required) | Pins a hashtag and answers with it. The name is stored as posts are tagged — no leading `#`, lowercased, at most 127 characters — and a name that is not a hashtag at all is a **422** rather than a row nobody can post with. Featuring one that is already featured answers the existing entity. One past `configuration.accounts.max_featured_tags` is a **422**. |
| DELETE | `/api/v1/featured_tags/{id}` | public, no-csrf (viewer required, `write:accounts` or `write` scope) | — | Unpins a tag and answers `{}`. A tag that is not there and a tag that is somebody else's are the same **404**. |
| GET | `/api/v1/featured_tags/suggestions` | public, no-csrf (viewer required, `read:accounts` or `read` scope) | — | The hashtags the viewer posts with most and has not featured, built by the same helper the trends use so they cannot mean something different here. At most ten. |
| GET | `/api/v1/accounts/{account}/featured_tags` | public, no-csrf | — | Somebody's featured tags. Public, as the profile they are drawn on is, and the counts come from public and unlisted posts only. |
| GET | `/api/v1/statuses/{nid}/history` | public, no-csrf (viewer optional, `read:statuses` or `read` scope when a token is sent) | — | Every version the status has been through, oldest first, as Mastodon `StatusEdit` entities — the first being what was posted and the last what is showing now. A revision is written on every edit, so the first entry is never the current text. Answered to anybody, like `GET /api/v1/statuses/{nid}` itself: the status is resolved through the visibility filter first, so one the caller may not read is a **404** and no revision is looked at. A token that *is* presented still has its scope checked — too little is a **403**, not a silent downgrade to an anonymous read. `poll`, `media_attachments` and `emojis` are always `null`/`[]`: an edit here changes the text, the warning and the sensitivity flag and nothing else. A status edited before the revisions table existed is answered with the one version in the database; nothing is invented. |
| POST | `/api/v1/accounts/{id}/note` | public, no-csrf (viewer required, `write:accounts` scope) | `comment` (empty clears) | Keeps the viewer's private note about the account and returns the updated relationship, whose `note` carries it. At most 2000 characters, counted as characters. An empty or blank comment clears it rather than storing a blank. Never federated, and readable by nobody but its author — not by the account it is about. |
| POST | `/api/v1/accounts/{id}/pin` | public, no-csrf (viewer required, `write:accounts` scope) | — | Features the account on the viewer's profile; `endorsed` becomes true. An account the viewer does not follow is a **422**, and so is a follow the other side has not answered — featuring somebody who may still refuse would publish a claim the viewer has not earned. Your own account is refused. Featuring twice features once. |
| POST | `/api/v1/accounts/{id}/unpin` | public, no-csrf (viewer required, `write:accounts` scope) | — | Stops featuring it. Unfeaturing one that was not featured is not an error. |
| GET | `/api/v1/endorsements` | public, no-csrf (viewer required, `read:accounts` scope) | `limit` (40, capped at 80) | The accounts the viewer features, newest first. The viewer's own and nobody else's. |
| GET | `/api/v1/domain_blocks` | public, no-csrf (viewer required, `read:blocks` scope) | `limit` (100, capped at 200) | The instances the viewer has blocked **for themselves**, newest first, as a flat list of domain strings — which is what Mastodon answers here, not entities. Not the admin's instance-wide access list (`occ social:fediverse`), which applies to everybody at once. |
| POST | `/api/v1/domain_blocks` | public, no-csrf (viewer required, `write:blocks` scope) | `domain` (required) | Blocks every account on that instance for the viewer. What is sent is normalised to the host it names — `Example.COM`, `@user@example.com` and `https://example.com/@user` are one instance. Anything outside `a-z0-9.-` is a **422** rather than a stored pattern, because the comparison is a `LIKE` and a stored `%` would be a block that quietly matched other instances; this instance's own domain is a 422 too. The instance's posts stop reaching every timeline, thread and notification list, matched on the host of the author's actor id — including posts boosted into view by somebody else. Nothing is federated and the instance is never told. |
| DELETE | `/api/v1/domain_blocks` | public, no-csrf (viewer required, `write:blocks` scope) | `domain` (required) | Lifts the block and answers `{}`. Unblocking one that was not blocked is not an error. Posts that arrived while it held come back: the block filtered the read, it did not delete anything. |

### Announcements

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/announcements` | public, no-csrf (viewer required, any token — no scope) | — | The announcements that apply right now, oldest effective first, as Mastodon `Announcement` entities, each carrying `read` for the viewer. Unpaged, as Mastodon's is, and no scope is asked for: what the instance is telling everybody is readable by any user token. An announcement with a start and an end is served **only between them**, and the window is a predicate of the query rather than something a cron deletes — it starts and stops on time on an instance whose cron is broken. `mentions`, `statuses`, `tags`, `emojis` and `reactions` are always `[]` — an announcement is plain text and there is no reaction route — and all five keys are sent because a client that declares them non-optional cannot decode the entity otherwise. `content` is HTML built from what the admin typed, escaped, one paragraph per blank line. |
| POST | `/api/v1/announcements/{id}/dismiss` | public, no-csrf (viewer required, `write:accounts` or `write` scope) | — | Marks it read for the viewer and answers `{}`. Per account: another account's read state is neither changed nor readable. Dismissing twice is a no-op, and an announcement whose window has closed can still be dismissed — a client that was showing it must be able to put it away. An id that names nothing is a **404**. |

### Timelines and notifications

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/timelines/{timeline}/` | public, no-csrf | `local` (false), `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `only_media` (false) | One of `home`, `account`, `public`, `direct`, `favourites` (case-insensitive); anything else is a 422 (`UnknownProbeException`). `only_media` keeps just the posts that carry an attachment, which is what the **Photos** view in the sidebar asks of `home`. It had been parsed off the request since the hashtag timeline gained it and never put to a query, so it used to be accepted and ignored; "no media" has three spellings in the stored column (NULL, `''` and `'[]'`) and all three are excluded. `public` is readable without a token, as Mastodon's is — a client asks for it before it has one — though a token that *was* presented still has to be a good one. Every other timeline needs a viewer. Sends a `Link` header. Rate-limited per user and per anonymous caller. |
| GET | `/api/v1/timelines/tag/{hashtag}` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `local` (false), `only_media` (false) | Posts carrying `{hashtag}`. |
| GET | `/api/v1/timelines/list/{id}` | public, no-csrf (viewer required, `read:lists` scope) | `limit` (20, capped at 50), `max_id` (0), `min_id` (0), `since_id` (0), `only_media` (false) | The list's timeline: the home timeline narrowed to the list's members. A route of its own rather than a name in `/api/v1/timelines/{timeline}/`, because a list timeline is a name *and an id*. Narrowed, not widened — every visibility, block, mute and duplicate-boost filter the home timeline applies applies here unchanged, and the membership join can only take posts away, so a list never shows its owner a post their home timeline would not have. A list that is not theirs is a **404**. Sends a `Link` header. |
| GET | `/api/v1/favourites/` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's favourited posts. |
| GET | `/api/v1/bookmarks` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's bookmarked posts. |
| GET | `/api/v1/notifications` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `types` (array), `exclude_types` (array), `accountId` (string) | Notification stream for the viewer. `types`/`exclude_types` keep or drop notification kinds: `mention`, `reblog`, `favourite`, `follow`, `follow_request`. A `types` naming only kinds this app has no notification for returns nothing, and a stored notification whose sub-type has no Mastodon name is left out of the page rather than sent with an empty `type`. Sends a `Link` header. |
| GET | `/api/v1/notifications/unread_count` | public, no-csrf (viewer required) | — | `{"count": n}` — notifications newer than the viewer's `notifications` marker. Counted up to 99; past that the answer stays 99, which is all a badge shows. |
| GET | `/api/v1/notifications/{id}` | public, no-csrf (viewer required, `read:notifications` or `read` scope) | — | One notification, as `/api/v1/notifications` serves it. Read through the notification timeline itself, so one that is not the viewer's is a **404** rather than a refusal that would say it exists — as is one whose sub-type has no Mastodon name, which the list leaves out too. |
| POST | `/api/v1/notifications/{id}/dismiss` | public, no-csrf (viewer required, `write:notifications` or `write` scope) | — | Dismisses one notification and answers `{}`. The row is deleted, not flagged: a dismissal is final in Mastodon and the list is built straight off these rows. The post and the Like or Announce behind it are untouched, so no counter moves. Dismissing one that is already gone is a **200** — the client is asking for a state that holds. The Nextcloud notification raised from the same row is withdrawn with it. |
| POST | `/api/v1/notifications/clear` | public, no-csrf (viewer required, `write:notifications` or `write` scope) | — | Dismisses every notification the viewer has and answers `{}`. |
| GET | `/api/v1/markers` | public, no-csrf (viewer required) | `timeline` (array of `home`, `notifications`; all of them when omitted) | How far through each timeline the viewer has read: `{"notifications": {"last_read_id": "42", "version": 3, "updated_at": "…"}}`. Always a JSON object, `{}` for an account with no markers yet — never `[]`. Absent timelines have no marker yet. |
| POST | `/api/v1/markers` | public, no-csrf (viewer required, `write` scope) | Body (JSON or form-encoded): `home[last_read_id]`, `notifications[last_read_id]` | Moves markers forward and returns the ones it changed. A marker never moves backwards: two clients reading the same account report their own positions, and the one further behind must not un-read what the other has seen. |

All four return a bare JSON array of statuses (no envelope) together with a `Link` header — see Pagination.

The home timeline is two pages, not one query. A post belongs there if the viewer follows its **author** or follows one of its **hashtags** and the post is public; each half is a query over `social_stream.nid`, and they are merged, deduplicated and cut to `limit` before the rows are read. Written as one query the two halves would be an OR across two different joins, which no index can serve. Merging is exact rather than approximate: both halves are cut to the same `limit`, so anything belonging in the top `limit` of the union is in the top `limit` of its own half. A post that is both followed and tagged appears once, and the visibility, block, mute, silence and duplicate-boost filters apply to both halves — the hashtag half additionally reaches no further than a stranger can read, since a followed hashtag is not a relationship with the author.

### Followed hashtags

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/followed_tags` | public, no-csrf (viewer required) | `limit` (20, capped at 50), `max_id` (0), `min_id` (0) | The hashtags the viewer follows, as Mastodon `Tag` entities with `following: true`. Newest follow first. Sends a `Link` header whose cursor is the `social_followed_tag` row id, not the tag: a tag can be unfollowed and followed again, so its name does not move in one direction and cannot page. |
| GET | `/api/v1/tags/{hashtag}` | public, no-csrf (viewer required) | — | One `Tag` entity — `name`, `url`, `history`, `following` — for `{hashtag}`, with or without its leading `#`. A tag nobody has posted is not a 404: it is a real tag with an empty `history` and `following: false`. |
| POST | `/api/v1/tags/{hashtag}/follow` | public, no-csrf (viewer required, `write` or `follow` scope) | — | Follows the hashtag and returns the `Tag` with `following: true`. Following one that is already followed is not an error, so a client that lost the answer and retried gets the same tag back. |
| POST | `/api/v1/tags/{hashtag}/unfollow` | public, no-csrf (viewer required, `write` or `follow` scope) | — | Unfollows it and returns the `Tag` with `following: false`. Unfollowing what was never followed is not an error either. |

Following a hashtag is what puts its **public** posts into the viewer's home timeline, as if their authors were followed — that is the whole of the feature, and the rest of it is how a client says which tags.

A hashtag is stored and compared in one form: the tag with no leading `#`, trimmed, lowercased, and cut to the 127 characters `social_stream_tag.hashtag` holds (`FollowedTagsRequest::normalise()`). So `#NextCloud` and `nextcloud` are one tag to follow, one tag to look up and one tag to unfollow, matching the case-insensitive comparison `/api/v1/timelines/tag/{hashtag}` already makes. Something that normalises to nothing — `#`, or spaces — is a **422**, not a stored row that no post could ever match.

The `history` of a `Tag` from any of these routes is the one the trends endpoint sends: a single bucket for the default window (`1d`), `uses` from the counts the cron keeps, and `accounts` always `0` because this instance counts uses rather than distinct accounts. A hashtag nobody has posted has an empty `history` rather than a zeroed bucket, because a zero would be a claim about a day. `following` is present on every `Tag` these routes return and absent from `/api/v1/trends/tags`, which is a public route with no viewer to answer it for.

### Polls

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/polls/{nid}` | public, no-csrf (viewer required) | — | The Mastodon `Poll` entity of the status `{nid}`: options with vote counts, `expires_at`/`expired`, `multiple`, `voters_count`, and the viewer's `voted`/`own_votes`. 404 when the status is not a poll. |
| POST | `/api/v1/polls/{nid}/votes` | public, no-csrf (viewer required, `write` scope) | `choices` (array of option indices) | Votes on a *federated* poll: each choice is delivered to the poll's author as an ActivityPub vote note; the chosen indices are remembered locally so the poll renders as voted, and the authoritative counts arrive later as an `Update{Question}` from the origin. 422 on invalid or duplicate votes and on expired polls. A vote on a **local** poll is counted here instead of being delivered — this instance is the origin, so there is nobody to ask — and polls are created like any other post, by passing `poll` to `POST /api/v1/statuses`. |

Incoming federated polls (`Question` objects) are stored like notes, appear in every timeline, and carry the `poll` entity in their status export; a remote `Update{Question}` refreshes the counts.

### Link previews

Statuses carry Mastodon's `card` entity: `url`, `title`, `description`, `type` (always `link`), `provider_name`, `image`, and the fields this app cannot fill (`author_name`, `html`, `width`/`height`, `embed_url`, `blurhash`) as empty values so that clients reading them blindly keep working. It is `null` for a post that links nowhere.

There is no endpoint for cards — they are derived data, never federated, and every instance reads the linked page itself:

- The first plain link of a post is what gets previewed; mentions and hashtags are skipped, and only `http(s)` links count.
- The page is read by a background job (a `LinkPreview` item in the stream queue, drained by cron or `occ social:queue:process`), so posting and inbox delivery never wait for a stranger's web server. A post therefore gains its card shortly *after* it appears.
- The fetch goes through `CurlService`, which means: `http(s)` only on the request and on every redirect, no local addresses, a download size cap, a 5 s timeout, and **the instance access list** — with an allow-list configured, previews only come from listed hosts.
- The card is read from `OpenGraph`, then Twitter-card tags, then the plain `<title>` and `<meta name="description">`. Title and description are length-capped and stored as text, never as markup; a preview image must itself be an `http(s)` URL.
- Cards live in `social_stream_card`, keyed by the post, and are deleted with it (including by the retention job).

### Reports

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/reports` | public, no-csrf (viewer required, `write` scope) | `account_id` (required), `status_ids` (array), `comment`, `category` (`spam`, `legal`, `violation` or `other`; anything else becomes `other`), `forward` | Files a moderation report about the account (numeric id or full actor id) for the instance admins, who are notified and review it in the Social section of the administration settings. Reporting yourself is a 422, a missing `account_id` too. With `forward` set and the account on another instance, the report is also delivered there as a `Flag` — **anonymised**: the activity names this instance's own actor and is signed with its key, never the reporter, who is reporting an account on the very instance that would receive their handle. The comment travels as written. Returns the Mastodon `Report` entity (`action_taken`, `category`, `comment`, `status_ids`, `target_account`, …); `forwarded` is `true` only once the remote inbox has accepted the `Flag`, so a forward that could not be delivered reads `false` and the report still stands. |

Incoming federated reports (`Flag` activities from other instances) are stored the same way and land in the same admin panel. A report that arrived that way is never forwarded on: passing it along would put this instance's name on somebody else's complaint, and two instances doing that to each other is a loop.

### Admin API (Mastodon)

Mastodon's `/api/v1/admin/*`, over the moderation the admin panel has always
had. Every route requires a **moderator**: the user behind the bearer token (or
behind the session) is resolved first and asked of
`AdminApiService::isAdministrator()`, and anyone else is a **403** having had
nothing done on their behalf. A moderator is a Nextcloud administrator, or
somebody an administrator has handed the **Social** settings section to under
*Administration privileges* — Nextcloud's own settings delegation
(`IManager::getAllowedAdminSettings()`), which is the same gate the admin panel
and its buttons sit behind, so the two cannot disagree about who may act.
Moderating otherwise meant administering the whole server, which is a great
deal of power to hand somebody so they can act on a report. Nothing is
delegated by default, so out of the box only administrators pass. A scope is *not* that check and cannot be — this app's
OAuth registration stores whatever scope string a client asks for, so
`admin:write` on a token says only that some client asked for it. The scope is
required in addition, as Mastodon requires it: a bearer token needs
`admin:read` (or the broad `admin`) to read and `admin:write` to write, and
the `read`/`write` every timeline client holds satisfies neither. An
administrator's own browser session (with its CSRF token) needs no scope,
having no token to carry one.

Entities carry every key Mastodon documents. Where this app has nothing behind
one it is sent as the empty value of its type rather than omitted, because a
client that declares a field non-optional cannot decode the entity otherwise:
on `Admin::Account` that is `email` (`""` — the address belongs to the
Nextcloud account and is not republished here), `ip` (`null`), `ips` (`[]`),
`locale` (`""`), `invite_request` (`null`), `role` (`null` — this app has no
roles, and Mastodon also sends `null` for an account it holds no user of),
`disabled` and `sensitized` (always `false`, no state here corresponds to
either), `created_by_application_id` and `invited_by_account_id` (`null`).
`confirmed` and `approved` are `true` for a local account and `false` for a
remote one, as on Mastodon: both describe a user of *this* instance.

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/admin/accounts` | public, no-csrf (**admin required**, `admin:read` scope) | `origin` (`local` or `remote`), `status` (`active`, `silenced`, `suspended`, `pending`, `disabled`), `username`, `display_name`, `by_domain`, `email`, `ip`, `limit` (40, capped at 200), `max_id` (0), `min_id` (0) | A page of accounts as `Admin::Account`, newest first, with a `Link` header. `origin` outside `local` and `remote` is a **422** rather than an unfiltered page. `status=silenced` and `status=suspended` are read from the instance's decisions rather than from the account table — a suspension deletes the cached actor, so the accounts a moderator most needs to find are the ones the account table no longer holds; those two pages are capped by `limit`, are not paged further, and carry no `Link` header. `pending` and `disabled` are states this app does not have (nothing awaits approval, and a fediverse account has no login to disable) and answer with **no accounts**, as do `email` and `ip` — this instance holds neither for a fediverse account, and a filter that was ignored would have shown the whole instance as the answer to a question about one account. An account whose cached actor cannot be read is left out of the page, but its row still moves the cursor. |
| GET | `/api/v1/admin/accounts/{id}` | public, no-csrf (**admin required**, `admin:read` scope) | — | One `Admin::Account`. `{id}` is the numeric id, the account's ActivityPub id or a handle (it accepts slashes). The ActivityPub id is accepted because it is the only name left on an account whose cached actor a suspension purged — without it a suspension could be applied over this API and never lifted over it — and it is the `id` the entity itself reports for such an account. Nothing is ever fetched from another server to answer this. Unknown is a **404**. |
| POST | `/api/v1/admin/accounts/{id}/action` | public, no-csrf (**admin required**, `admin:write` scope) | `type` (`silence`, `suspend`, `none`), `text`, `report_id` | Applies the decision through `ModerationService`, which is the same call the admin panel makes: **silence** takes the account out of the public and global timelines and changes no data; **suspend** deletes what it has posted here, drops its cached actor and refuses what it sends afterwards. `none` is Mastodon's own "warn and take no action": it records a warning, tells the account if it is local, and leaves whatever stands standing — lifting is what the `unsilence` and `unsuspend` routes below are for. (It used to lift instead, because a warning is a strike in a history this app did not keep.) Every one of the three is recorded as a strike, with `text` and `report_id`, in a history a lift does not empty. `text` is kept as the comment on a decision that stands. `sensitive` and `disable` are **422**: no state here corresponds to either. With `report_id` the report is resolved in the same call, so the decision and the report it came from cannot disagree. Answers `{}`. |
| POST | `/api/v1/admin/accounts/{id}/enable` | public, no-csrf (**admin required**, `admin:write` scope) | — | Answers the `Admin::Account` and **changes nothing**: nothing here can disable a login (a fediverse account has none of its own, and the Nextcloud account behind a local one is enabled where Nextcloud keeps it), so there is nothing to undo. It exists because a moderation client calls it unconditionally when clearing a strike, and a 404 there reads as "no such account". |
| POST | `/api/v1/admin/accounts/{id}/unsilence` | public, no-csrf (**admin required**, `admin:write` scope) | — | Lifts a silence, and only a silence: a suspended account is left suspended, so a client that meant to unsilence cannot free one by accident. Returns the `Admin::Account`. |
| POST | `/api/v1/admin/accounts/{id}/unsuspend` | public, no-csrf (**admin required**, `admin:write` scope) | — | Lifts a suspension, and only a suspension. What the suspension deleted stays deleted — lifting stops the refusal of what the account sends from now on. Returns the `Admin::Account`. |
| GET | `/api/v1/admin/reports` | public, no-csrf (**admin required**, `admin:read` scope) | `resolved` (absent = the open queue), `account_id` (the reporter), `target_account_id`, `limit` (40, capped at 200), `max_id` (0), `min_id` (0) | A page of `Admin::Report`, newest first, with a `Link` header. The two account filters take the same references `{id}` does; one this instance has never heard of matches **nothing** rather than falling off the query. Each report carries the reporter and the reported account as `Admin::Account` — built from the id the report was filed against when the account itself is gone, which is what a suspension leaves behind — the reported posts as `Status` entities (one no longer here is left out, the commonest reason being that a moderator already took it down), `rules` always `[]` (a report here carries a category, never a rule id) and `forwarded` always `false` (nothing forwards a report to the reported account's own instance). |
| GET | `/api/v1/admin/reports/{id}` | public, no-csrf (**admin required**, `admin:read` scope) | — | One `Admin::Report`; unknown is a **404**. |
| POST | `/api/v1/admin/reports/{id}/resolve` | public, no-csrf (**admin required**, `admin:write` scope) | — | Marks the report handled through `ReportService` — the same write the admin panel makes — and records the administrator who did it and when, which is what `action_taken_by_account` and `action_taken_at` report. |
| POST | `/api/v1/admin/reports/{id}/reopen` | public, no-csrf (**admin required**, `admin:write` scope) | — | Puts the report back in the queue and clears the record of who acted on it: it described a decision that no longer stands. |
| POST | `/api/v1/admin/reports/{id}/assign_to_self` | public, no-csrf (**admin required**, `admin:write` scope) | — | Takes the report, so a second administrator can see it is being worked on — `assigned_account`. Stored on the report row by `Version1000Date20260911000013`; what is kept is the Nextcloud user id, because an administrator moderates as a user of this server and need not have a Social account at all (one who has none is reported as `null` rather than as a failure). |
| POST | `/api/v1/admin/reports/{id}/unassign` | public, no-csrf (**admin required**, `admin:write` scope) | — | Gives the report back to the queue. |
| GET | `/api/v1/admin/domain_blocks` | public, no-csrf (**admin required**, `admin:read` scope) | — | The **instance-wide** access list — the one `occ social:fediverse` and the admin panel manage — as `Admin::DomainBlock` entities. Not to be confused with `/api/v1/domain_blocks`, which is one user hiding a server from themselves. `severity` is always `suspend` and `reject_media` and `reject_reports` always `true`: an entry refuses the domain outright, there being no lesser setting to report. `private_comment` and `public_comment` are `null` (the list has no room for a reason), `obfuscate` is `false` (this instance publishes no list of what it blocks), and `created_at` is the epoch — the list stores no timestamps, and that is how this API says so rather than inventing a date. `id` is derived from the domain (the first eight hex digits of its md5, as a decimal string), so it is numeric like every other id here and stable for as long as the entry names the same domain; the routes below take the domain itself just as happily. While the instance federates by an **allow list** (`none_but`) every route in this group is a **422**: the same app value holds both lists, and served as blocks its entries would read as their own opposite — a client that then "blocked" a domain would have added it to the list of the allowed. |
| POST | `/api/v1/admin/domain_blocks` | public, no-csrf (**admin required**, `admin:write` scope) | `domain` (required), `severity` (`suspend`) | Blocks a domain and returns the entry. An address no hostname could be is a **422**, and a `severity` other than `suspend` is a **422** rather than a silently stronger block than was asked for. Blocking one already blocked adds nothing and is not an error, so a client that lost the answer may retry. `reject_media`, `reject_reports`, `obfuscate` and the two comments are accepted and ignored — the list has no room for any of them. |
| GET | `/api/v1/admin/domain_blocks/{id}` | public, no-csrf (**admin required**, `admin:read` scope) | — | One entry, by the derived id or by the domain; unknown is a **404**. |
| PUT | `/api/v1/admin/domain_blocks/{id}` | public, no-csrf (**admin required**, `admin:write` scope) | `severity` (`suspend`) | There is nothing on an entry here to change, so this confirms it and refuses any severity but the one it has with a **422** — a 200 that had quietly dropped the change would leave the moderator believing the domain was under a lesser block than it is. |
| DELETE | `/api/v1/admin/domain_blocks/{id}` | public, no-csrf (**admin required**, `admin:write` scope) | — | Lifts the block and answers with the entry that was lifted. |

### Media

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/media` | public, no-csrf (viewer required, `write` scope) | Multipart: `file` (required, read from `$_FILES['file']`), `description` (the alt text) | Stores an upload and returns the `MediaAttachment` (images get a resized preview and blurhash; video/audio are stored as-is with the media itself as `preview_url`). Refuses a file larger than the app's `max_size` (10 MB by default) with a 422, and a mime type outside `CacheDocumentService::filterMimeTypes()`. The stored row is **not** public: `public` is what lets the unauthenticated `/media/{uuid}` route serve the file, and it is only set later, when a post attaches the media and that post is `public` or `unlisted`. `focus` is accepted and not stored. Rate-limited per user. |
| POST | `/api/v2/media` | public, no-csrf (viewer required, `write` scope) | Same as v1 | The same upload; modern clients POST v2 and only fall back to v1 on a 404. |
| POST | `/api/v1/media/from-file` | public, no-csrf (viewer required, `write` scope) | Body (JSON or form-encoded): `path` (required, relative to the viewer's own files), `description` (the alt text) | **Nextcloud extension, not a Mastodon route.** Attaches a file the viewer already has in Nextcloud, so a picture that is already on the server does not have to be downloaded and uploaded back. The path is resolved inside the viewer's own user folder and nowhere else — a share they can read is fair game, a traversal is a 422 `no such file`, and so is a folder. The bytes are copied, not referenced: a post keeps the picture it was published with, so moving or deleting the original later cannot empty a post that has already federated. Everything after that is the upload path — the same size ceiling, the same mime filter, the same resizing — and the answer is the same `MediaAttachment`, equally not public until a post says so. Rate-limited per user. |
| GET | `/api/v1/media/{nid}` | public, no-csrf | `nid` (path), `preview` (default `''`, ignored) | One of the viewer's own attachments, by the id the upload returned. 404 for an unknown id or someone else's attachment. |
| PUT | `/api/v1/media/{nid}` | public, no-csrf | Body: `description` | Updates the alt text of the viewer's own attachment and returns it. 404 for an unknown id or someone else's attachment. |
| GET | `/media/{uuid}` | public, no-csrf | `uuid` (path, may carry a `.ext` suffix) | Streams a cached document by UUID. Either of a document's copies resolves it — the full one that `url` names and the resized one that `preview_url` names. The `Content-Type` is the media type sniffed from the content at ingest; the extension in the URL is ignored. Only public documents are served, since the route is unauthenticated. 404 when unknown or not public. |

`POST /api/v1/media` and `POST /api/v2/media` are both served by `ApiController::mediaNew()`, and it and `mediaFromFile()` share the storing half (`storeAttachment()`) so the two ways in cannot drift apart on the things that matter — the mime filter, the resizing, and the row not being public. On the wire an attachment's alt text travels as the ActivityPub `name`, both incoming and outgoing.

Every key of a `MediaAttachment` is always present, `null` when there is nothing to put in it (`preview_url`, `remote_url`, `meta`, `description`, `blurhash`). `meta` is an object, never a list.

### Search (Mastodon-adjacent, app-specific shapes)

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/local/v1/search` | user, no-csrf | `search` (required) | `{"result": {"accounts": [...], "hashtags": [...], "content": [...]}, "status": 1}` — the app's own web-UI search. It used to sit on `/api/v1/search`, where a Mastodon client got a 200 in a shape it could make nothing of; that path is now Mastodon's v1 search. The app's frontend does not call this route: `Search.vue` uses `/api/v2/search`. |
| GET | `/api/v1/global/accounts/search` | user | `search` (required) | `{"result": {"accounts": [...], "exact": <actor or null>}, "status": 1}`. A leading `@` is stripped; an empty query returns empty lists. |
| GET | `/api/v1/global/tags/search` | user | `search` (required) | `{"result": {"tags": [...], "exact": <tag or null>}, "status": 1}`. A leading `#` is stripped. |

### Pagination

`ApiController` list endpoints accept the cursor parameters shown per route above (`limit`, `max_id`, `min_id`, and either `since_id` or `since`), all integers defaulting to `0` except `limit` (20 — 40 on `/api/v1/blocks` and `/api/v1/mutes` — capped at 50).

Every paged `ApiController` route except `/api/v1/blocks`, `/api/v1/mutes` and `/api/v1/scheduled_statuses` also sends a `Link` header in Mastodon's form, which is the only cursor masto.js — and therefore Elk and Phanpy — reads:

```
Link: <https://cloud.example/index.php/apps/social/api/v1/timelines/home?limit=20&max_id=41>; rel="next",
      <https://cloud.example/index.php/apps/social/api/v1/timelines/home?limit=20&min_id=60>; rel="prev"
```

`next` points below the lowest id on the page and is sent only while a further page may exist (a page shorter than `limit` is the last one); `prev` points above the highest id and is sent whenever the page is not empty. Every other filter the caller sent survives into both links. The routes that send one are `/api/v1/timelines/{timeline}/`, `/api/v1/timelines/tag/{hashtag}`, `/api/v1/notifications`, `/api/v1/favourites/`, `/api/v1/bookmarks`, `/api/v1/followed_tags`, `/api/v1/accounts/{account}/statuses`, `/api/v1/accounts/{account}/followers` and `/api/v1/accounts/{account}/following`. `/api/v1/followed_tags` takes no `since_id` and pages on the followed-tag row id rather than a status id. A remote follower collection fetched over HTTP has no local ids to page by and carries no header, `/api/v1/blocks` and `/api/v1/mutes` send none because they take no cursor to page with, and `/api/v1/scheduled_statuses` sends none because a ScheduledStatus carries no status nid for the header to point at.

"A page shorter than `limit` is the last one" is decided on what the *query* returned, not on what survived any filtering the controller then did. `/api/v1/notifications` drops entries whose sub-type has no Mastodon name; counting those out would have made a filtered page look like the end of the list and stopped a paging client with the rest of it still in the database.

`LocalController` stream endpoints use a different pair: `since` (a numeric cursor, default `0`) and `limit` (default **5**, not 20), and send no `Link` header.

---

## Custom Local API

These endpoints exist to serve the app's own Vue frontend. They are session-authenticated only, mostly wrap their payload in the `{"result": …, "status": 1}` envelope, and are not part of any Mastodon client contract.

### Streams

All eight take `since` (int, 0) and `limit` (int, 5) and return `{"result": [statuses], "status": 1}`.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/api/v1/stream/home` | user, no-csrf | Posts from accounts the viewer follows. |
| GET | `/api/v1/stream/notifications` | user, no-csrf | Notification stream. |
| GET | `/api/v1/stream/timeline` | user, no-csrf | Local timeline. |
| GET | `/api/v1/stream/federated` | user | Global/federated timeline. |
| GET | `/api/v1/stream/direct` | user, no-csrf | Direct messages. |
| GET | `/api/v1/stream/liked` | user | Posts the viewer liked. |
| GET | `/api/v1/stream/tag/{hashtag}/` | user | Local posts for `{hashtag}`. |
| GET | `/api/v1/account/{username}/stream` | user, public | Posts of `{username}` (remote timeline synced first, at most 20 entries of the outbox page the remote server returns). `{username}` accepts slashes (`requirements: .+`). It also names the server that is fetched, and the route is public, so it is rate-limited: 30 per minute anonymously, 300 per minute per user. |

### Posts

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/local/v1/post` | user, public, no-csrf | `id` (required, ActivityPub id) | One post, returned **unwrapped** (`directSuccess()`). |
| GET | `/local/v1/post/replies` | user, no-csrf | `id` (required), `since` (0), `limit` (5) | Replies to a post, wrapped in `result`. |
| POST | `/api/v1/post` | user | `content` (`''`), `to` (array), `type` (default `public`), `replyTo` (`''`), `attachments` (mixed, default `[]`), `hashtags` (array), `poll` (object, optional), `spoilerText` (`''`, the content warning) | Creates a post. Returns `{"result": {"post": <object>, "token": "<request token>"}, "status": 1}`. |
| DELETE | `/api/v1/post` | user | `id` (required) | Deletes an own post; `{"result": [], "status": 1}`. Rejects posts not attributed to the caller. |
| POST | `/api/v1/post/like` | user | `postId` (required) | Likes a post; `{"result": {"like": <activity>, "token": "…"}, "status": 1}`. |
| DELETE | `/api/v1/post/like` | user | `postId` (required) | Removes the like; same shape. |

Boosting from a client goes through `POST /api/v1/statuses/{nid}/{act}` with `reblog`.

### Current user

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/current/info` | user | — | `{"result": {"account": <Person>}, "status": 1}`; refreshes the local actor cache first. |
| GET | `/api/v1/current/followers` | user | — | `{"result": [actors], "status": 1}`. At most 500 of them: the route takes no cursor, so there is nothing to page with, and an account with more followers than that would otherwise load every one of them into a single request. Superseded by `/api/v1/accounts/{account}/followers`, which pages. |
| GET | `/api/v1/current/following` | user | — | `{"result": [actors], "status": 1}`. |
| PUT | `/api/v1/account/fields` | user | `fields` (list of `{name, value}`) | Replaces the profile metadata fields (at most four name/value pairs; entries with an empty half are dropped, names capped at 255 and values at 500 characters). Federated as `PropertyValue` attachments on the actor. `{"result": {"account": <Person>}, "status": 1}`. |
| PUT | `/api/v1/account/summary` | user | `summary` (string, default `''`) | Replaces the bio. It is stored as **plain text, exactly as it was typed**, and cut to 500 characters (Mastodon's limit), counted in characters rather than bytes. Nothing is stripped on the way in: every path that renders it escapes it, so a stripping pass had nothing to protect and a great deal to break — `strip_tags()` reads a bare `<` as the start of a tag and eats the rest of the line, which turned `Maths: a<b and b>c` into `Maths: ac`. The actor document carries it as HTML in `summary`, the account entity as HTML in `note` and as the stored plain text in `source.note`, and an `Update{Person}` goes out to the followers. `{"result": {"account": <Person>}, "status": 1}`. |
| PUT | `/api/v1/current/follow` | user | `account` (required) | Follows an account; `{"result": [], "status": 1}`. |
| DELETE | `/api/v1/current/follow` | user | `account` (required) | Unfollows an account; `{"result": [], "status": 1}`. |

### Account info

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/account/{username}/info` | user, public | — | Local account with complete details, returned **unwrapped** as a `Person`; rebuilds the actor cache if it is missing. |
| GET | `/api/v1/global/account/info` | user, public | `account` (required, e.g. `user` or `user@domain`) | Local or remote account, returned **unwrapped**. A leading `@` is stripped; remote accounts get follower/following/post counts fetched. A local actor is created on demand only when the logged-in viewer asks about their **own** account — the route is public, so creating for anyone would let anonymous visitors force a Fediverse identity onto any Nextcloud user. Rate-limited: 10 per five minutes anonymously, 120 per minute per user, because a handle this instance has never seen costs a host-meta, a WebFinger and four signed actor fetches against a host the caller names. |
| GET | `/api/v1/global/actor/info` | user, public | `id` (required, ActivityPub actor id) | `{"result": {"actor": <Person>}, "status": 1}`. |
| GET | `/api/v1/global/actor/avatar` | user, public, no-csrf | `id` (required) | Streams the cached avatar with a 24 h cache header; 404 (envelope shape) when the actor has no icon. |
| GET | `/api/v1/global/actor/header` | user, public, no-csrf | `id` (required) | HTTP **redirect** to the actor's header URL, 24 h cache; 404 when unset. |

The followers and following lists of an arbitrary account are reachable through the Mastodon-compatible `/api/v1/accounts/{account}/followers` and `/api/v1/accounts/{account}/following`.

### Banner

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/banner` | user, no-csrf | `file` (multipart, `$_FILES['file']`) | Stores the upload as the current user's header image, updates the actor cache and federates an Update. Returns `{"result": {"url", "id"}, "status": 1}`. |
| POST | `/api/v1/banner/url` | user, no-csrf | `url` (string, default `''`, required in practice) | Downloads the image at the given `url` with cURL (follows up to 5 redirects, 30 s timeout, user agent `Nextcloud-Social/0.10`) and stores it as the current user's header image. Same response shape. An empty `url`, or a non-2xx response, fails. |

### Config and system

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/config/cloudAddress` | admin | `cloudAddress` (required) | Sets the app's cloud base URL. Returns a bare `[]`. |
| GET | `/local/` | public, no-csrf | — | `{"result": {"version": "<installed_version>", "setup": <bool>}, "status": 1}`. |
| GET | `/test/{account}/` | public, no-csrf | `account` (path) | WebFinger self-test. Only active when the `social.tests` system value is set — otherwise it returns exactly the same payload as `/local/`. On failure it returns the error envelope with HTTP **200** and a `result` key holding the test data. |

### Moderation (moderators only)

Backing routes of the Social section in the administration settings. All of them require a session **and** a CSRF token (they are deliberately not part of the bearer-token client API), and every one of them carries `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`: a Nextcloud administrator passes, and so does a group the administrator has handed the Social section to under *Administration privileges*. That is the same gate core puts on the page itself, so whoever can open it can use the buttons on it.

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/moderation/reports/{id}/resolve` | moderator, csrf | `resolved` (true) | Marks the report resolved (or reopens it with `resolved=false`). Returns the report entity; 404 for an unknown id. |
| POST | `/moderation/fediverse/add` | moderator, csrf | `address` (required) | Adds an instance to the Fediverse access list (the list `occ social:fediverse` manages). Invalid addresses are a 422. Returns `{"list": [...]}`. |
| POST | `/moderation/fediverse/remove` | moderator, csrf | `address` (required) | Removes an instance from the access list. Returns `{"list": [...]}`. |
| POST | `/moderation/fediverse/access` | moderator, csrf | `type` (required) | Switches the access mode: `all_but` (blocklist) or `none_but` (allowlist). Anything else is a 422. Returns `{"accessType": "..."}`. |
| GET | `/moderation/accounts` | moderator, csrf | `query`, `origin` (`local`, `remote`, or empty for both), `status` (`active`, `silenced`, `suspended`), `maxId` (0) | A page of up to 40 accounts for the browser on the settings page, as `{"accounts": [{"actor_id", "handle", "username", "domain", "local", "level", "strikes"}], "cursors": [nid, …]}`. `strikes` is how many decisions have ever been taken about the account, counted for the whole page in one query. `query` is tried as all three of the things a moderator types: `bob@instance.example` (and `@bob@instance.example`) is an account on an instance, `instance.example` is the instance, and a bare `bob` is a username anywhere. Pass the last cursor back as `maxId` for the next page. An `origin` that is neither `local` nor `remote` means both, and a `status` outside the three above is passed on to the same reader the Mastodon admin API uses — so `pending` and `disabled` answer with no accounts, as they do there. |
| GET | `/moderation/accounts/history` | moderator, csrf | `actorId` (required) | What has been decided about one account before now, newest first, as `{"strikes": [{"action", "text", "moderator", "report_id", "creation"}]}` — up to 50. `action` is `none` for a warning, otherwise `silence` or `suspend`. `moderator` is the Nextcloud user who took it, or `""` for one taken by a command or a job. A missing `actorId` is a **400**. |
| POST | `/moderation/accounts` | moderator, csrf | `actorId` (required), `level` (`silence`, `suspend`, or empty to lift), `comment` | The instance's own decision about an account, as opposed to one user's block. **Silence** keeps the account reachable for the people who follow it and takes it out of the public and global timelines; it changes no data and is undone by lifting. **Suspend** deletes what the account has posted here, drops its cached actor and refuses everything it sends afterwards — lifting stops the refusal but does not bring back what was deleted. Returns the decision, or `{"actor_id": …, "level": ""}` when lifted. |
| POST | `/moderation/statuses/remove` | moderator, csrf | `streamId` (required) | Deletes one post, whoever wrote it. Returns `{"stream_id": …}`. Reached from the **Take down** button beside each post a report names — the lightest thing a moderator can do about a report, and until that button existed, the one thing the panel could not do. |
| GET | `/admin/announcements` | moderator, csrf | — | Every announcement, newest first, as the administration page shows one: `id`, `text` (as typed, not HTML), `starts_at`, `ends_at`, `all_day`, `published_at` and `active` — whether it is being served at this moment. One that has not started and one that has run out are both listed, because those are the ones an admin has to act on. |
| POST | `/admin/announcements` | moderator, csrf | `text` (required), `starts_at`, `ends_at`, `all_day` (false) | Posts an announcement and answers with the whole list. Blank text, text past 10000 characters, a date the server cannot read, one bound without the other (Mastodon's own rule) and an end not after its start are each a **422**. `all_day` rounds the window out to whole days and is ignored without a window. There is no edit route: an announcement people have already read is replaced by a new one, not changed under them. |
| DELETE | `/admin/announcements/{id}` | moderator, csrf | — | Removes the announcement and every dismissal of it, for everybody, read or not, and answers with what is left. An unknown id is a **404**. |
| POST | `/moderation/retention` | moderator, csrf | `days` (required, 0–3650) | Sets the `retention_days` app setting: remote statuses older than this that no local user cares about are pruned (0 disables). Returns `{"retentionDays": n}`. |

---

## OAuth

A partial, Mastodon-shaped OAuth 2 flow (`OAuthController`, `ClientService`).

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/apps` | public, no-csrf | `client_name` (`''`), `redirect_uris` (string or array, `''`), `website` (`''`), `scopes` (`'read'`) | Registers a client. Returns `{"id", "name", "website", "scopes", "client_id", "client_secret"}`. `redirect_uris` may be an array or, as Mastodon's own API takes it, several URIs newline-separated in one field — those are split into separate entries, trimmed, de-duplicated and stripped of blanks. They used to be wrapped into a single entry, which `ClientService::confirmData()` then compared a lone URI against, so a client registered with more than one could never authorize with any of them. |
| GET | `/oauth/authorize` | user, no-csrf | `client_id`, `redirect_uri`, `response_type` (must be `code`), `scope` (`'read'`), `state` (`''`) | Renders the `oauth2` consent template. `redirect_uri` is checked against the client's registration before the page exists, so there is nothing to confirm on a forged link. `state` is carried through to the POST. Anything wrong with the request — an unknown `client_id`, a `response_type` that is not `code`, a `redirect_uri` or scope the client never registered — is HTTP 400 `{"error": "..."}`, the way the POST answers it; these used to escape as a Nextcloud HTML error page. |
| POST | `/oauth/authorize` | user | `client_id`, `redirect_uri`, `response_type`, `scope` (`'read'`), `state` (`''`) | Issues an authorization code. Answers with a redirect to `redirect_uri` (`RedirectResponse`, so HTTP **303**) carrying `code` and, when one was sent, `state` — appended with `http_build_query`, so a `redirect_uri` that already has a query string or a fragment stays valid. `state` used to be dropped, which a spec-following client rejects and a lax one is open to code injection through. For `urn:ietf:wg:oauth:2.0:oob` the code (and `state`) come back as the response body instead. Errors: HTTP 400 `{"error": "..."}`. |
| POST | `/oauth/token` | public, user, no-csrf | `client_id`, `client_secret`, `redirect_uri`, `grant_type` (only `authorization_code`), `scope` (`'read'`), `code` (`''`) | Exchanges an authorization code for a bearer token: `{"access_token", "token_type": "Bearer", "scope", "created_at"}`. `scope` reports the scopes the **token** carries — what `ApiController::checkTokenScope()` will enforce on every request made with it — not the `scope` of this call. Echoing the latter had a client that omits it (Tusky does) told it had `read` and hiding its compose button while writes in fact worked. The code expires `ClientService::TIME_CODE_TTL` (600 s) after authorization. `client_credentials` is refused: falling through would have returned whatever token the last user's grant left in the client row. `created_at` is the client row's creation date, read as a date — it used to be an integer cast of a `DATE` column, which reported a timestamp in January 1970. Errors are HTTP 400/401 `{"error": "..."}`; credential failures are brute-force throttled. |
| POST | `/oauth/revoke` | public, user, no-csrf | `client_id`, `client_secret`, `token` | Revokes an access token (RFC 7009). Only the client the token was issued to may revoke it; an unknown or already-revoked token still returns HTTP 200 `[]`. Wrong client credentials return a throttled HTTP 401. |

**Grant types:** only `authorization_code` works. `client_credentials` returns HTTP 400 `{"error": "unsupported_grant_type"}`; any other value returns HTTP 400 `{"error": "invalid value for grant_type"}`.

**Scopes:** there is no fixed scope vocabulary — `SocialClient::getScopesFromString()` splits the string on spaces and `ClientService::confirmData()` checks that requested scopes are a subset of the ones stored at registration; the default everywhere is `read`. Bearer tokens are enforced per endpoint by `ApiController::checkTokenScope()`: creating, editing and deleting statuses, uploading media and updating its alt text, status actions, poll votes, moving markers, `update_credentials` and filing reports need `write`; follow/unfollow, block, mute and authorizing or rejecting follow requests need `follow` or `write`; `/api/v1/apps/verify_credentials` accepts any valid token; every other `/api/` route needs `read`. A scope is satisfied by itself or a granular variant (`write:statuses` satisfies `write`). Session-cookie requests are not scope-restricted, but are only accepted together with a valid CSRF token.

**Credential storage:** client secrets, authorization codes and access tokens are stored as `sha256:<hex>` digests (`SecretHasher`); rows from before hashing hold the bare value, are still accepted, and are rewritten once by the `HashClientSecrets` repair step. A presented credential that already looks hashed is never tried as one of those legacy plaintext rows — offering it made the stored digest a working bearer token of its own, so a database dump held usable credentials.

**Token lifetime:** `ClientService::TIME_TOKEN_TTL` is 30672000 s (~1 year) since last use; a used token's `last_update` is refreshed at most once per `TIME_TOKEN_REFRESH` (300 s).

---

## ActivityPub Federation

`ActivityPubController` decides per request, via `checkSourceActivityStreams()`, whether the caller is a Fediverse server: it splits the `Accept` header on commas, trims each entry at the first `;`, and returns true if any entry equals `application/ld+json` or `application/activity+json`. Anything else (a browser) is treated as HTML.

JSON-LD responses are emitted through `activityPubSuccess()`, i.e. `Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"`. The controller also registers `activity+json` and `ld+json; …` responders for format-based negotiation.

| Method | Route | Auth | Content negotiation | Description |
|--------|-------|------|--------------------|-------------|
| GET | `/actor` | public, no-csrf | always JSON-LD | This instance's own `Application` actor — the identity every outbound signed fetch is made as, and the `publicKey.id` owner a peer dereferences to check one. It is not an account: no outbox, no followers, no following and no featured collection are named, because naming a collection no route serves makes a peer that follows it conclude the actor is gone; `manuallyApprovesFollowers` is true and `discoverable`/`indexable` are false, so nothing offers it as somebody to follow or index. `inbox` and `endpoints.sharedInbox` both point at the shared `/inbox`, which is a real endpoint and the right one — anything addressed to this actor is addressed to the server. The key pair lives in app config (`instance_actor_public_key`, and the private half sealed with the instance secret), generated on first use; an instance that does not yet know its own social URL 404s here and signs nothing rather than signing with a `keyId` nobody can resolve. |
| GET | `/users/{username}` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Actor object (with W3C security context). Unknown actor → error envelope with HTTP 404. Without an AP `Accept` header it delegates to `SocialPubController::actor()`, which renders the public Vue page (`PublicTemplateResponse`, HTTP 404 if the actor is uncached). |
| GET | `/@{username}/` | public, no-csrf | same as above | Alias that calls `actor()`. |
| POST | `/@{username}/inbox` | public, no-csrf | JSON | Per-user inbox. Rate-limited on the source address before any signature work, then verifies the HTTP signature, checks the Fediverse access list, spends the *verified* origin's own looser bucket, requires the local actor to exist, imports the activity, then async-processes the stream cache queue (`inbox_throttle` app setting, default 300/min, 0 disables; either bucket → HTTP 429). Returns `{"result": [], "status": 1}`; a gone signature also returns success. A refusal is answered with the status that says what was wrong — 401, 403, 400, 404 or 503, and 500 only for a fault of ours; see the rejection table in `docs/Architecture.md`. |
| GET | `/@{username}/inbox` | public, no-csrf | JSON-LD | Empty `OrderedCollection` (`totalItems: 0`) for the actor's inbox; bare `[]` with HTTP 404 if the actor is unknown. |
| POST | `/inbox` | public, no-csrf | JSON | Shared inbox, same processing (including both rate limits and the same rejection statuses) without the per-actor check. |
| GET | `/@{username}/outbox` | public, no-csrf | always JSON-LD | Outbox collection. The HTML fallback is commented out in the source, so browsers get JSON-LD too. |
| POST | `/@{username}/outbox` | public, no-csrf | always JSON-LD | Same route handler as the GET (`ActivityPub#outbox`); posting an activity is **not** implemented — the method only returns the outbox collection. |
| GET | `/@{username}/followers` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Followers collection, or the public Vue page. |
| GET | `/@{username}/following` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Following collection, or the public Vue page. |
| GET | `/@{username}/collections/featured` | public, no-csrf | always JSON-LD | The actor's pinned posts as an `OrderedCollection` whose `orderedItems` carry the full posts (at most 5). This is where remote servers read pinned posts from; the actor document points at it with `featured`. The route has no `Accept` gate and no viewer, so the posts are read through the anonymous visibility filter and only public ones appear — `PinService::pin()` refuses anything narrower in the first place, but posts pinned before that rule existed stop being exposed here with no cleanup step. Unknown actor → error envelope with HTTP 404. |
| GET | `/@{username}/{token}` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Single post. `{token}` values `outbox`, `followers` and `following` (case-insensitive) are re-routed to those handlers first. For AP callers the `Stream` is returned as JSON-LD (HTTP 404 error envelope with a `stream` key when missing); for browsers the Vue page is rendered with the post pre-loaded into initial state. |
| GET | `/@{username}/{token}/quote_authorizations/{stamp}` | public, no-csrf | always JSON-LD | The FEP-044f approval a quote of `{token}` rests on, as a `QuoteAuthorization` naming the quoted author (`attributedTo`), the quoting post (`interactingObject`) and the quoted post (`interactionTarget`). `{stamp}` is the quoting post's id, base64url-encoded without padding — the same URI this instance put in the `Accept` that granted the approval, which is why nothing has to be stored for the document to be served. Answered from the quoted post's current policy, so a post narrowed after the fact stops being quotable and the approval 404s with it; a stamp this instance would not have written (a second base64 spelling, or one that does not decode to an address) is a 404 as well. No viewer is set: an approval is a public statement about a public post. |
| GET | `/@{username}/{token}/replies` | public, no-csrf | always JSON-LD | The replies to `{token}`, as an `OrderedCollection` whose `first`/`last` point at `?page=N`; with `?page=N` (or Mastodon's `?page=true`, which means the first) it answers with an `OrderedCollectionPage` of at most `OrderedCollection::PAGE_SIZE` (40) items linked by `next`/`prev`. The note itself points here with `replies`. Items are the replies' **ids**, not the replies: a reply is its author's document, served by their instance, which may since have edited or deleted it. No viewer is set, so only **public** replies appear — a followers-only or direct reply is not listed even as an id, because an id is enough to fetch the reply from the instance that holds it. Oldest first, so a page offset stays stable as the thread grows. A post this instance does not hold, or one that does not exist, is an error envelope with HTTP 404. |

### Discovery documents (not app routes)

WebFinger, NodeInfo discovery and host-meta are served by `lib/WellKnown/WebfingerHandler.php`, registered through `registerWellKnownHandler()` in `lib/AppInfo/Application.php` — they are Nextcloud-level paths (.well-known/webfinger, .well-known/nodeinfo, .well-known/host-meta), not entries in this app's route table. Only the NodeInfo 2.0 *document* itself is an app route (see `/.well-known/nodeinfo/2.0` above).

The handler first checks `FediverseService::jailed()` and passes the previous response through when the instance is not allowed to federate. For `webfinger` with a `resource` query parameter (`acct:` prefix stripped; the raw request URI is parsed as a fallback):

- The app's own subject gets an extra link added to the existing response, carrying `app`, `name` and `version` properties.
- For a local actor it returns a JRD document whose `subject` is the requested resource, with aliases for the actor URL and the Nextcloud profile page, a `self` link of type `application/activity+json` pointing at the `/@{username}/` route, an `http://webfinger.net/rel/profile-page` link (`text/html`) to the Nextcloud profile page, and an `http://ostatus.org/schema/1.0/subscribe` link with a `template` of `<social url>ostatus/follow/?uri={uri}`.
- `acct:<host>@<host>` — the instance's own handle, the one Mastodon gives its instance actor — returns a JRD with an alias and a `self` link (`application/activity+json`) pointing at `/actor`. It is answered before any local account is looked up, so a Nextcloud user whose id happens to equal the instance host cannot take the name the server signs under.
- A missing or empty `resource` parameter is an empty JRD with HTTP 400 (RFC 7033 makes the parameter mandatory).
- Unknown or non-local subjects produce an empty JRD with HTTP 404 (or hand back to the previous handler when the actor lookup fails outright).

The `nodeinfo` service returns a single link with rel `http://nodeinfo.diaspora.software/ns/schema/2.0` pointing at the app's `/.well-known/nodeinfo/2.0` route; `host-meta` returns XRD (`application/xrd+xml`) with an `lrdd` template pointing at the instance's WebFinger URL.

---

## Legacy OStatus

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/ostatus/follow/` | user, no-csrf | `uri` (required) | Resolves `uri` as an account, then as an actor id, and renders the Vue app with `account` and `currentUser` in initial state. Requires a logged-in user; failures return the error envelope. |
| GET | `/api/v1/ostatus/followRemote/{local}` | public, user, no-csrf | — | Renders the Vue app with the **guest** layout, providing `local` and `account` in initial state, so a remote visitor can follow the local account `{local}`. |
| GET | `/api/v1/ostatus/link/{local}/{account}` | public, user, no-csrf, rate-limited (10/5min per IP) | — | WebFingers `{account}`, extracts its `http://ostatus.org/schema/1.0/subscribe` link template, substitutes `{uri}` with `{local}`'s account, and returns `{"result": {"url": "<subscribe url>"}, "status": 1}`. |

---

## Frontend / Document serving

These serve HTML or files for the app's own UI; they are not client API endpoints.

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/` | user, no-csrf | `cloudAddress` (read from the request during first-run setup, admins only) | Renders the Vue app (`main` template) and provides `serverData` (`public`, `firstrun`, `setup`, `isAdmin`, `cliUrl`, `cloudAddress`, plus `checks` for admins). Creates the user's actor on first visit and tries to auto-configure the cloud address. |
| GET | `/timeline/{path}` | user, no-csrf | `path` (default `''`, `requirements: .+`) | Same page; `path` is accepted and then ignored — the method just calls `navigate()`. |
| GET | `/follow_requests` | user, no-csrf | — | Same page. The path belongs to the client-side router; the server answers it so that reloading or bookmarking the follow-requests page works instead of 404ing. |
| GET | `/blocked` | user, no-csrf | — | Same page, for the blocked-and-muted-accounts view (**Settings → Blocked and muted accounts** in the app's sidebar). |
| GET | `/document/get` | user, no-csrf | `id` (required) | Streams a cached document with its stored mime type. Errors: error envelope, HTTP 500. |
| GET | `/document/public` | public, no-csrf | `id` (required) | Same for documents marked public. |
| GET | `/document/get/resized` | user, no-csrf | `id` (required) | Streams the resized/preview variant. |
| GET | `/document/public/resized` | public, no-csrf | `id` (required) | Same for public documents. |

---

## Async Queue

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/async/request/{token}` | public, no-csrf | `token` (path) | Internal endpoint the app calls against itself to deliver queued federation requests for `{token}`. With nothing queued it returns an empty HTTP 200. Otherwise it closes the connection (`async()`) and processes standby requests for at most `QueueController::MAX_DURATION` (90 s) — whatever is left stays standby for the cron — then ends in `exit()`, since the connection is already gone. |

---

## Error Responses

There is no single error format; three shapes exist.

**1. `TNCDataResponse` envelope** (`LocalController`, `ConfigController`, `ActivityPubController`, `OStatusController`, `NavigationController` — everything using the trait):

```json
{"result": {}, "status": 1}
```

on success (`success()`; `more` keys are merged in at the top level), and

```json
{"status": -1, "error": "request failed"}
```

on failure (`fail()`) — the exception class and message go to the log, never into the response, since several callers are public pages. The HTTP status is whatever the caller passed — the default is **500**, callers also use 404, and `Config#remote` deliberately returns the failure envelope with HTTP 200. Failures are logged as warnings unless the caller disables it. Two related helpers bypass the envelope: `directSuccess()` returns the object as-is with HTTP 200, and `activityPubSuccess()` does the same while setting `Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"`.

**2. `ApiController` and `TagController` errors** — a bare object, never the envelope:

```json
{"error": "the access_token was revoked"}
```

from the private `error()` helper of each, which maps the failure to a status a client can act on — see the table below. `TagController` maps the same four cases it can raise: 403 for a token whose scope is too narrow (with `WWW-Authenticate: Bearer error="insufficient_scope"`), 401 for no or stale credentials (`Bearer error="invalid_token"`), 422 for something that is not a hashtag, and 500 for anything else — with the message withheld, since these are public routes. Failures raised with no message of their own get a wording that fits the status (`the access_token is invalid`, `not found`, `the request could not be processed`, `request failed`) rather than `{"error": ""}`. `mediaOpen()` is the one route that does not go through it: a missing or non-public document is a 404, any other failure a 400.

Every handler catches `Throwable`, not `Exception`. A `TypeError` — an empty or truncated JSON body was the way to raise one, on seven public endpoints — used to escape as a Nextcloud HTML error page, with a stack trace where debug is on, to a client that can only read JSON.

**3. `OAuthController` errors** — `{"error": "..."}` with HTTP 400 (bad grant type, missing code, token generation failure) or HTTP 401 (`unknown client_id`, other exceptions).

### `ApiController` status codes

`ApiController` keeps Mastodon's `{"error": "..."}` body and maps the failure to a status a client can act on. Every failure used to be a 401, which a client reads as a revoked token: a deleted status, a mistyped timeline name, a database hiccup and a slow remote all logged the reader out of their client.

| Status | When |
|--------|------|
| 401 | No credential, or one that is no longer valid (`ClientNotFoundException`, `AccountDoesNotExistException`). Carries `WWW-Authenticate: Bearer error="invalid_token"`. |
| 403 | A valid token whose grant does not cover the route (`InsufficientScopeException`, tested ahead of the list because it extends `ClientException`). Carries `WWW-Authenticate: Bearer error="insufficient_scope"`. Also a fediverse access rule (`UnauthorizedFediverseException`). |
| 404 | The thing asked for is not here: `StreamNotFoundException`, `CacheActorDoesNotExistException`, `ActorDoesNotExistException`, `ItemNotFoundException`, `CacheDocumentDoesNotExistException`, `HashtagDoesNotExistException`, `ReportNotFoundException`, `FollowNotFoundException`, `InstanceDoesNotExistException`, `OCP\Files\NotFoundException`, and a remote that answered with nothing (`RequestContentException`). |
| 422 | The request was understood and refused, and retrying it unchanged cannot help: `InvalidActionException` (which now also covers a body that claims to be JSON and is not, and a `visibility` this app does not know), `UnknownProbeException`, `InvalidResourceException`, `InvalidResourceEntryException`, `InvalidHandleException`, `ItemUnknownException`, `CacheContentMimeTypeException`, `ClientException`. |
| 429 | `TooManyRequestsException`, and the `#[AnonRateLimit]` / `#[UserRateLimit]` limits on the write, search, account and timeline routes. |
| 502 | Another server let us down: `RequestNetworkException`, `RequestServerException`, `RequestResultNotJsonException`, `RequestResultSizeException`. |
| 500 | Anything unrecognised. The body is always `{"error": "internal server error"}` and the real message is logged with its stack trace — these routes are all `#[PublicPage]`, and echoing `getMessage()` published whatever the failure happened to name. |

Successful Mastodon-compatible responses are **not** wrapped: `ApiController` returns the object or array directly with HTTP 200. HTTP status codes in use across the app are 200, 303 (the actor-header and OAuth authorization redirects — `RedirectResponse`'s default), 400, 401, 403, 404, 422, 429, 500, 502 and 503 (a refused inbox delivery whose signature could not be checked); no endpoint returns 201 or 204.
