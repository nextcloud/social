# Nextcloud Social API Reference

> This document is written by hand but mechanically checked: `tests/DocumentationTest.php` asserts that the set of routes documented here matches `appinfo/routes.php`. Every route below appears in a table row with its URL exactly as written in `appinfo/routes.php`. Paths that are *not* routes of this app (the `.well-known` discovery documents handled by the Nextcloud WellKnown API) are deliberately written without code spans so that check stays exact.

## Overview

The Social app exposes four groups of endpoints, all registered in `appinfo/routes.php`:

- **Mastodon-compatible REST API** (`ApiController`, `OAuthController`) — a partial implementation of the Mastodon client API. Several endpoints are stubs; each is marked below.
- **Custom Local API** (`LocalController`, `ConfigController`) — the endpoints the app's own Vue frontend calls. They are not Mastodon-compatible and their response envelope differs (see Error Responses).
- **ActivityPub Federation API** (`ActivityPubController`, `SocialPubController`) — server-to-server ActivityPub, plus the HTML profile/post pages served on the same URLs.
- **Frontend, document, OStatus and queue endpoints** (`NavigationController`, `OStatusController`, `QueueController`) — HTML pages and internal plumbing.

All URLs are relative to the app's route base, i.e. index.php/apps/social + the URL from the route table (for example, index.php/apps/social/api/v1/statuses).

---

## Authentication

Two mechanisms exist, and which one applies depends on the controller:

1. **OAuth Bearer token** — only `ApiController` reads it. Its constructor parses the `Authorization` header, accepts a `bearer` auth type, and resolves the token through `ClientService::getFromToken()`. If no bearer token is present it falls back to the logged-in Nextcloud session user.
2. **Nextcloud session** — `LocalController`, `ConfigController`, `NavigationController`, `OAuthController` (authorize/authorizing) and `OStatusController` use the session `userId` only. They do **not** honour bearer tokens, so the Custom Local API is effectively usable only from the app's own frontend (or with a Nextcloud session cookie / app password + `OCS-APIRequest`).

Access control is declared with **PHPDoc annotations, not PHP attributes** — the controllers contain no `#[PublicPage]`, `#[NoCSRFRequired]` or `#[NoAdminRequired]` attributes at all, only `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` docblocks. No endpoint uses `@BruteForceProtection`. The "Auth" column in the tables below records these annotations:

- `public` — `@PublicPage`: reachable without a Nextcloud login (an endpoint may still fail later if it needs a viewer).
- `user` — `@NoAdminRequired`: any logged-in user.
- `admin` — no annotation at all: Nextcloud requires an admin session.
- `no-csrf` — `@NoCSRFRequired`: no CSRF token needed, which matters for non-browser clients.

Note that many `ApiController` endpoints are annotated `@PublicPage` but call `initViewer(true)` internally, which throws when there is neither a session nor a valid bearer token; those return HTTP 401 with `{"error": "the access_token was revoked"}`.

---

## Mastodon-compatible API

### Instance and app metadata

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/instance/` | public, no-csrf | — | Local instance metadata from `InstanceService::getLocal()` (title, version, usage, registrations). Returned as-is, not wrapped. The `version` field is Pleroma-style — `4.1.0 (compatible; Nextcloud Social <app version>)` — because clients gate features on it; NodeInfo keeps reporting the real app version. |
| GET | `/api/v1/apps/verify_credentials` | public, no-csrf | — | `{"name", "website"}` of the client behind the bearer token; falls back to `{"name": "Nextcloud Social", "website": "https://github.com/nextcloud/social/"}` when no client is identified. |
| GET | `/api/v1/custom_emojis` | public, no-csrf | — | **Not implemented.** Always returns an empty array `[]` with HTTP 200. |
| GET | `/api/saved_searches/list.json` | public, no-csrf | — | **Not implemented.** Initialises the viewer, then always returns `[]`. |
| GET | `/.well-known/nodeinfo/2.0` | public, no-csrf | — | NodeInfo 2.0 document: `version`, `software.name` (instance title), `software.version`, `protocols: ["activitypub"]`, `rootUrl`, `usage`, `openRegistrations`. Falls back to name `Nextcloud Social` and the installed app version if no local instance row exists. |

### Accounts

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/accounts/verify_credentials` | public, no-csrf | — | The viewer's `Person` actor serialised in local format. 401 `{"error": ...}` when unauthenticated. |
| PATCH | `/api/v1/accounts/update_credentials` | public, no-csrf (viewer required, `write` scope) | JSON/form body; only `locked` is supported | Sets whether new followers need manual approval (`manuallyApprovesFollowers`). Other Mastodon profile fields are ignored. Returns the refreshed account entity. |
| GET | `/api/v1/follow_requests` | public, no-csrf (viewer required) | — | Accounts with a pending follow request towards the viewer, serialised in local format. |
| POST | `/api/v1/follow_requests/{id}/authorize` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Accepts the pending follow request from account `{id}` (numeric id or full actor id; accepts slashes): federates the `Accept` and marks the follow accepted. Returns the updated relationship entity; 404 when no request is pending. |
| POST | `/api/v1/follow_requests/{id}/reject` | public, no-csrf (viewer required, `follow` or `write` scope) | — | Rejects the pending follow request from account `{id}`: federates a `Reject` and deletes the follow row. Returns the updated relationship entity; 404 when no request is pending. |
| GET | `/api/v1/blocks` | public, no-csrf (viewer required) | `limit` (40, capped at 50) | Accounts the viewer has blocked. |
| GET | `/api/v1/mutes` | public, no-csrf (viewer required) | `limit` (40, capped at 50) | Accounts the viewer has muted. |
| GET | `/api/v1/accounts/relationships` | public, no-csrf | `id` (array, required) | Relationship entries from `FollowService::getRelationships()`. Sent as `id[]=…` on the wire. |
| POST | `/api/v1/accounts/{id}/block` | public, no-csrf (viewer required) | — | Blocks the account (`{id}` is the numeric id or a full actor id; accepts slashes). Severs the follow relationship in both directions and, unless `federate_blocks` is `0`, sends a `Block` activity to the account's server. Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/unblock` | public, no-csrf (viewer required) | — | Lifts a block (federates `Undo{Block}` under the same setting). Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/mute` | public, no-csrf (viewer required) | `notifications` (true) | Mutes the account — purely local, never federated. With `notifications=true` (default) the account's notifications are hidden too. Returns the updated relationship entity. |
| POST | `/api/v1/accounts/{id}/unmute` | public, no-csrf (viewer required) | — | Lifts a mute. Returns the updated relationship entity. |
| GET | `/api/v1/accounts/{account}/statuses` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | Statuses of `{account}`; syncs the remote timeline first. `{account}` accepts slashes (`requirements: .+`). |
| GET | `/api/v1/accounts/{account}/followers` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since` (0) | Followers of `{account}`. For a remote domain the actor's `followers` collection is fetched over HTTP and up to `limit` actors are returned; otherwise the local cache is probed. Note the fourth parameter is `since`, not `since_id`. |
| GET | `/api/v1/accounts/{account}/following` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since` (0) | Same as above for the `following` collection. |

### Statuses

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/statuses` | public, no-csrf | Body (JSON or form-encoded): `status`, `visibility`, `media_ids` (array), `in_reply_to_id`, `spoiler_text`, `sensitive`, `content_type` | Creates a post. The body is read from `php://input` and parsed by `Content-Type`. Only `status`, `visibility`, `media_ids` and `in_reply_to_id` affect the created post — `spoiler_text`, `sensitive` and `content_type` are parsed but ignored here. `visibility` maps to the stream type (`public`, `unlisted`, `followers`, `direct`). Returns the new status; errors return HTTP 400 `{"error": "..."}`. |
| GET | `/api/v1/statuses/{nid}` | public, no-csrf | — | One status by numeric id, local export format. |
| PUT | `/api/v1/statuses/{nid}` | public, no-csrf | Body: `status`, `spoiler_text`, `sensitive` | Edits an own post via `PostService::editPost()`. An empty `spoiler_text` is sent as `null`. Errors return HTTP 400 `{"error": "..."}`. |
| GET | `/api/v1/statuses/{nid}/context` | public, no-csrf | — | Ancestors/descendants of the status. |
| POST | `/api/v1/statuses/{nid}/{act}` | public, no-csrf | `act` (path) | Performs an action on a status — see the action table below. |

`{act}` is validated against `ActionService::$availableStatusAction`. Accepted values are `translate`, `favourite`, `unfavourite`, `reblog`, `unreblog`, `bookmark`, `unbookmark`, `mute`, `unmute`, `pin`, `unpin`; anything else throws `InvalidActionException`:

| `act` value | Effect |
|-------------|--------|
| `favourite`, `unfavourite` | Creates/deletes a Like (`LikeService`). |
| `reblog`, `unreblog` | Creates/deletes an Announce (`BoostService`). The Mastodon-ish names `boost` and `unboost` are **not** accepted. |
| `bookmark`, `unbookmark` | Toggles the viewer's local bookmark flag (`social_stream_act.bookmarked`). Purely local, never federated; the bookmarked posts are served by `/api/v1/bookmarks`. |
| `translate` | Returns the status unchanged (translation is a TODO). |
| `mute`, `unmute`, `pin`, `unpin` | **Not implemented** — refused with `InvalidActionException` rather than silently accepted, so a client never displays a state that was not stored. |

The response is the status itself in local format.

### Timelines and notifications

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/timelines/{timeline}/` | public, no-csrf | `local` (false), `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | `{timeline}` must be one of `home`, `account`, `public`, `direct`, `favourites` (case-insensitive); anything else raises `UnknownProbeException` → 401 `{"error": "unknown timeline"}`. |
| GET | `/api/v1/timelines/tag/{hashtag}` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `local` (false), `only_media` (false) | Posts carrying `{hashtag}`. |
| GET | `/api/v1/favourites/` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's favourited posts. |
| GET | `/api/v1/bookmarks` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0) | The viewer's bookmarked posts. |
| GET | `/api/v1/notifications` | public, no-csrf | `limit` (20), `max_id` (0), `min_id` (0), `since_id` (0), `types` (array), `exclude_types` (array), `accountId` (string) | Notification stream for the viewer. |

All four return a bare JSON array of statuses (no envelope, no `Link` header).

### Reports

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/reports` | public, no-csrf (viewer required, `write` scope) | `account_id` (required), `status_ids` (array), `comment`, `category` (`spam`, `legal`, `violation` or `other`; anything else becomes `other`) | Files a moderation report about the account (numeric id or full actor id) for the instance admins, who are notified and review it in the Social section of the administration settings. Reporting yourself is a 422, a missing `account_id` too. Returns the Mastodon `Report` entity (`action_taken`, `category`, `comment`, `status_ids`, `target_account`, …); `forwarded` is always `false` — reports are never forwarded to the remote instance. |

Incoming federated reports (`Flag` activities from other instances) are stored the same way and land in the same admin panel.

### Media

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v2/media` | public, no-csrf | Same as POST `/api/v1/media` | Identical upload endpoint — modern Mastodon clients POST v2 and only fall back to v1 on a 404. |
| POST | `/api/v1/media` | public, no-csrf | `file` (multipart, read from `$_FILES['file']`), `description` (the alt text), `focus` (accepted, not stored) | Uploads an attachment (jpeg/gif/png, sniffed from the content), caches it and returns the `MediaAttachment`. Failure returns HTTP 400 `{"error": "..."}`. |
| GET | `/api/v1/media/{nid}` | public, no-csrf | `nid` (path), `preview` (default `''`, ignored) | One of the viewer's own attachments, by the id the upload returned. 404 for an unknown id or someone else's attachment. |
| PUT | `/api/v1/media/{nid}` | public, no-csrf | Body: `description` | Updates the alt text of the viewer's own attachment and returns it. 404 for an unknown id or someone else's attachment. |
| GET | `/media/{uuid}` | public, no-csrf | `uuid` (path, may carry a `.ext` suffix) | Streams a cached document by UUID. The `Content-Type` is the media type sniffed from the content at ingest; the extension in the URL is ignored. 404 when unknown. |

`POST /api/v1/media` and `POST /api/v2/media` are both served by `ApiController::mediaNew()`. On the wire an attachment's alt text travels as the ActivityPub `name`, both incoming and outgoing.

### Search (Mastodon-adjacent, app-specific shapes)

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/search` | user, no-csrf | `search` (required) | `{"result": {"accounts": [...], "hashtags": [...], "content": [...]}, "status": 1}`. Not the shape of the Mastodon v2 search endpoint. |
| GET | `/api/v1/global/accounts/search` | user | `search` (required) | `{"result": {"accounts": [...], "exact": <actor or null>}, "status": 1}`. A leading `@` is stripped; an empty query returns empty lists. |
| GET | `/api/v1/global/tags/search` | user | `search` (required) | `{"result": {"tags": [...], "exact": <tag or null>}, "status": 1}`. A leading `#` is stripped. |

### Pagination

`ApiController` list endpoints accept the cursor parameters shown per route above (`limit`, `max_id`, `min_id`, and either `since_id` or `since`), all integers defaulting to `0` except `limit` (20). `LocalController` stream endpoints use a different pair: `since` (a numeric cursor, default `0`) and `limit` (default **5**, not 20).

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
| GET | `/api/v1/account/{username}/stream` | user, public | Posts of `{username}` (remote timeline synced first). `{username}` accepts slashes (`requirements: .+`). |

### Posts

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/local/v1/post` | user, public, no-csrf | `id` (required, ActivityPub id) | One post, returned **unwrapped** (`directSuccess()`). |
| GET | `/local/v1/post/replies` | user, no-csrf | `id` (required), `since` (0), `limit` (5) | Replies to a post, wrapped in `result`. |
| POST | `/api/v1/post` | user | `content` (`''`), `to` (array), `type` (default `public`), `replyTo` (`''`), `attachments` (mixed, default `[]`), `hashtags` (array) | Creates a post. Returns `{"result": {"post": <object>, "token": "<request token>"}, "status": 1}`. |
| DELETE | `/api/v1/post` | user | `id` (required) | Deletes an own post; `{"result": [], "status": 1}`. Rejects posts not attributed to the caller. |
| POST | `/api/v1/post/like` | user | `postId` (required) | Likes a post; `{"result": {"like": <activity>, "token": "…"}, "status": 1}`. |
| DELETE | `/api/v1/post/like` | user | `postId` (required) | Removes the like; same shape. |

`LocalController::postBoost()`, `postUnboost()`, `uploadAttachement()` and `documentsCache()` exist in the controller but have **no routes**; boosting from a client goes through `POST /api/v1/statuses/{nid}/{act}` with `reblog`. `uploadAttachement()` unconditionally throws `BadMethodCallException('uploadAttachment is not implemented yet')`.

### Current user

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/current/info` | user | — | `{"result": {"account": <Person>}, "status": 1}`; refreshes the local actor cache first. |
| GET | `/api/v1/current/followers` | user | — | `{"result": [actors], "status": 1}`. |
| GET | `/api/v1/current/following` | user | — | `{"result": [actors], "status": 1}`. |
| PUT | `/api/v1/current/follow` | user | `account` (required) | Follows an account; `{"result": [], "status": 1}`. |
| DELETE | `/api/v1/current/follow` | user | `account` (required) | Unfollows an account; `{"result": [], "status": 1}`. |

### Account info

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| GET | `/api/v1/account/{username}/info` | user, public | — | Local account with complete details, returned **unwrapped** as a `Person`; rebuilds the actor cache if it is missing. |
| GET | `/api/v1/global/account/info` | user, public | `account` (required, e.g. `user` or `user@domain`) | Local or remote account, returned **unwrapped**. A leading `@` is stripped; remote accounts get follower/following/post counts fetched. A local actor is created on demand only when the logged-in viewer asks about their **own** account — the route is public, so creating for anyone would let anonymous visitors force a Fediverse identity onto any Nextcloud user. |
| GET | `/api/v1/global/actor/info` | user, public | `id` (required, ActivityPub actor id) | `{"result": {"actor": <Person>}, "status": 1}`. |
| GET | `/api/v1/global/actor/avatar` | user, public, no-csrf | `id` (required) | Streams the cached avatar with a 24 h cache header; 404 (envelope shape) when the actor has no icon. |
| GET | `/api/v1/global/actor/header` | user, public, no-csrf | `id` (required) | HTTP **redirect** to the actor's header URL, 24 h cache; 404 when unset. |

Two more methods, `LocalController::accountFollowers()` and `accountFollowing()`, have no routes — the followers/following lists of an arbitrary account are only reachable through the Mastodon-compatible `/api/v1/accounts/{account}/followers` and `/api/v1/accounts/{account}/following`.

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

### Moderation (admin only)

Backing routes of the Social section in the administration settings. All of them require an admin session **and** a CSRF token (they are deliberately not part of the bearer-token client API).

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/moderation/reports/{id}/resolve` | admin, csrf | `resolved` (true) | Marks the report resolved (or reopens it with `resolved=false`). Returns the report entity; 404 for an unknown id. |
| POST | `/moderation/fediverse/add` | admin, csrf | `address` (required) | Adds an instance to the Fediverse access list (the list `occ social:fediverse` manages). Invalid addresses are a 422. Returns `{"list": [...]}`. |
| POST | `/moderation/fediverse/remove` | admin, csrf | `address` (required) | Removes an instance from the access list. Returns `{"list": [...]}`. |
| POST | `/moderation/fediverse/access` | admin, csrf | `type` (required) | Switches the access mode: `all_but` (blocklist) or `none_but` (allowlist). Anything else is a 422. Returns `{"accessType": "..."}`. |

---

## OAuth

A partial, Mastodon-shaped OAuth 2 flow (`OAuthController`, `ClientService`).

| Method | Route | Auth | Parameters | Description |
|--------|-------|------|------------|-------------|
| POST | `/api/v1/apps` | public, no-csrf | `client_name` (`''`), `redirect_uris` (string or array, `''`), `website` (`''`), `scopes` (`'read'`) | Registers a client. Returns `{"id", "name", "website", "scopes", "client_id", "client_secret"}`. A non-array `redirect_uris` is wrapped into a one-element array (handling a real array from the request is a TODO). |
| GET | `/oauth/authorize` | user, no-csrf | `client_id`, `redirect_uri`, `response_type`, `scope` (`'read'`) | Renders the `oauth2` consent template. `response_type` must be `code`, else `ClientNotFoundException`. |
| POST | `/oauth/authorize` | user | `client_id`, `redirect_uri`, `response_type`, `scope` (`'read'`) | Confirms authorization. Unless `redirect_uri` is `urn:ietf:wg:oauth:2.0:oob` it emits a `Location: <redirect_uri>?code=<code>` header and exits; for the out-of-band URI it returns `{"code": "<code>"}`. Errors: HTTP 400 `{"error": "..."}`. |
| POST | `/oauth/token` | public, user, no-csrf | `client_id`, `client_secret`, `redirect_uri`, `grant_type`, `scope` (`'read'`), `code` (`''`) | Exchanges the code for a token: `{"access_token", "token_type": "Bearer", "scope", "created_at"}`. The code expires `ClientService::TIME_CODE_TTL` (600 s) after authorization. Errors are HTTP 400/401 `{"error": "..."}`; credential failures are brute-force throttled. |
| POST | `/oauth/revoke` | public, user, no-csrf | `client_id`, `client_secret`, `token` | Revokes an access token (RFC 7009). Only the client the token was issued to may revoke it; an unknown or already-revoked token still returns HTTP 200 `[]`. Wrong client credentials return a throttled HTTP 401. |

**Grant types:** only `authorization_code` works. `client_credentials` returns HTTP 400 `{"error": "unsupported_grant_type"}`; any other value returns HTTP 400 `{"error": "invalid value for grant_type"}`.

**Scopes:** there is no fixed scope vocabulary — `SocialClient::getScopesFromString()` splits the string on spaces and `ClientService::confirmData()` checks that requested scopes are a subset of the ones stored at registration; the default everywhere is `read`. Bearer tokens are enforced per endpoint by `ApiController::checkTokenScope()`: creating and editing statuses, uploading media, status actions, `update_credentials` and filing reports need `write`; block, mute and authorizing or rejecting follow requests need `follow` or `write`; `/api/v1/apps/verify_credentials` accepts any valid token; every other `/api/` route needs `read`. A scope is satisfied by itself or a granular variant (`write:statuses` satisfies `write`). Session-cookie requests are not scope-restricted, but are only accepted together with a valid CSRF token.

**Credential storage:** client secrets, authorization codes and access tokens are stored as `sha256:<hex>` digests (`SecretHasher`); rows from before hashing hold the bare value, are still accepted, and are rewritten once by the `HashClientSecrets` repair step.

**Token lifetime:** `ClientService::TIME_TOKEN_TTL` is 30672000 s (~1 year) since last use; a used token's `last_update` is refreshed at most once per `TIME_TOKEN_REFRESH` (300 s).

---

## ActivityPub Federation

`ActivityPubController` decides per request, via `checkSourceActivityStreams()`, whether the caller is a Fediverse server: it splits the `Accept` header on commas, trims each entry at the first `;`, and returns true if any entry equals `application/ld+json` or `application/activity+json`. Anything else (a browser) is treated as HTML.

JSON-LD responses are emitted through `activityPubSuccess()`, i.e. `Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"`. The controller also registers `activity+json` and `ld+json; …` responders for format-based negotiation.

| Method | Route | Auth | Content negotiation | Description |
|--------|-------|------|--------------------|-------------|
| GET | `/users/{username}` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Actor object (with W3C security context). Unknown actor → error envelope with HTTP 404. Without an AP `Accept` header it delegates to `SocialPubController::actor()`, which renders the public Vue page (`PublicTemplateResponse`, HTTP 404 if the actor is uncached). |
| GET | `/@{username}/` | public, no-csrf | same as above | Alias that calls `actor()`. |
| POST | `/@{username}/inbox` | public, no-csrf | JSON | Per-user inbox. Rate-limited per claimed origin host + source address (`inbox_throttle` app setting, default 300/min, 0 disables → HTTP 429 before any signature work), then verifies the HTTP signature, checks the Fediverse allow/blocklist, requires the local actor to exist, imports the activity, then async-processes the stream cache queue. Returns `{"result": [], "status": 1}`; a gone signature also returns success. |
| GET | `/@{username}/inbox` | public, no-csrf | JSON-LD | Empty `OrderedCollection` (`totalItems: 0`) for the actor's inbox; bare `[]` with HTTP 404 if the actor is unknown. |
| POST | `/inbox` | public, no-csrf | JSON | Shared inbox, same processing (including the rate limit) without the per-actor check. |
| GET | `/@{username}/outbox` | public, no-csrf | always JSON-LD | Outbox collection. The HTML fallback is commented out in the source, so browsers get JSON-LD too. |
| POST | `/@{username}/outbox` | public, no-csrf | always JSON-LD | Same route handler as the GET (`ActivityPub#outbox`); posting an activity is **not** implemented — the method only returns the outbox collection. |
| GET | `/@{username}/followers` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Followers collection, or the public Vue page. |
| GET | `/@{username}/following` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Following collection, or the public Vue page. |
| GET | `/@{username}/{token}` | public, no-csrf | JSON-LD for AP `Accept`, else HTML | Single post. `{token}` values `outbox`, `followers` and `following` (case-insensitive) are re-routed to those handlers first. For AP callers the `Stream` is returned as JSON-LD (HTTP 404 error envelope with a `stream` key when missing); for browsers the Vue page is rendered with the post pre-loaded into initial state. |

### Discovery documents (not app routes)

WebFinger, NodeInfo discovery and host-meta are served by `lib/WellKnown/WebfingerHandler.php`, registered through `registerWellKnownHandler()` in `lib/AppInfo/Application.php` — they are Nextcloud-level paths (.well-known/webfinger, .well-known/nodeinfo, .well-known/host-meta), not entries in this app's route table. Only the NodeInfo 2.0 *document* itself is an app route (see `/.well-known/nodeinfo/2.0` above).

The handler first checks `FediverseService::jailed()` and passes the previous response through when the instance is not allowed to federate. For `webfinger` with a `resource` query parameter (`acct:` prefix stripped; the raw request URI is parsed as a fallback):

- The app's own subject gets an extra link added to the existing response, carrying `app`, `name` and `version` properties.
- For a local actor it returns a JRD document whose `subject` is the requested resource, with aliases for the actor URL and the Nextcloud profile page, a `self` link of type `application/activity+json` pointing at the `/@{username}/` route, an `http://webfinger.net/rel/profile-page` link (`text/html`) to the Nextcloud profile page, and an `http://ostatus.org/schema/1.0/subscribe` link with a `template` of `<social url>ostatus/follow/?uri={uri}`.
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

**2. `ApiController` errors** — a bare object, never the envelope:

```json
{"error": "the access_token was revoked"}
```

with HTTP **401** from its private `error()` helper (used by most getters, including for the "unknown timeline" case), or HTTP **400** with the same shape from `statusNew()`, `statusUpdate()`, `mediaNew()` and `mediaGet()`. `mediaOpen()` uses HTTP 404 for a missing document.

**3. `OAuthController` errors** — `{"error": "..."}` with HTTP 400 (bad grant type, missing code, token generation failure) or HTTP 401 (`unknown client_id`, other exceptions).

Successful Mastodon-compatible responses are **not** wrapped: `ApiController` returns the object or array directly with HTTP 200. HTTP status codes in use across the app are 200, 302 (actor header redirect), 400, 401, 404 and 500; no endpoint returns 201 or 204.
