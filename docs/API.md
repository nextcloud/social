# Nextcloud Social API Reference

## Overview

The Social app provides three categories of APIs:

- **Mastodon-compatible REST API** under `/api/v1/` — designed to be compatible with the Mastodon client API specification
- **Custom Local API** under `/api/v1/` and `/local/v1/` — app-specific endpoints for the Vue frontend
- **ActivityPub Federation API** under `/users/`, `/@{username}/`, and `/inbox` — W3C ActivityPub protocol endpoints

Base URL: `/apps/social` (e.g. `https://cloud.example/apps/social/api/v1/statuses`)

---

## Authentication

The API supports two authentication methods:

1. **OAuth Bearer Token** — obtained via the OAuth flow (see OAuth section below). Include as `Authorization: Bearer <token>` header.
2. **Session-based** — uses the Nextcloud session cookie when the user is logged in to the web UI.

Public endpoints (ActivityPub, media serving) do not require authentication.

---

## Mastodon-compatible API

### Instance

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/v1/instance/` | Returns local instance metadata (title, version, usage stats, registration) |
| GET | `/api/v1/apps/verify_credentials` | Returns connected app name and website |
| GET | `/api/v1/custom_emojis` | Returns custom emoji list (currently empty, not implemented) |

### Accounts

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/accounts/verify_credentials` | — | Returns the authenticated user's account |
| GET | `/api/v1/accounts/relationships` | `id[]` (account IDs) | Returns relationship info (following, followed_by, etc.) |
| GET | `/api/v1/accounts/{account}/statuses` | `limit`, `max_id`, `min_id`, `since_id` | Returns statuses by a specific account |
| GET | `/api/v1/accounts/{account}/following` | `limit`, `max_id`, `min_id` | Returns who an account is following |
| GET | `/api/v1/accounts/{account}/followers` | `limit`, `max_id`, `min_id` | Returns an account's followers |

### Statuses

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| POST | `/api/v1/statuses` | `status`, `visibility`, `media_ids[]`, `in_reply_to_id`, `spoiler_text`, `sensitive` | Create a new post |
| GET | `/api/v1/statuses/{nid}` | — | Get a single status by NID |
| PUT | `/api/v1/statuses/{nid}` | `status`, `spoiler_text`, `sensitive` | Edit an existing post |
| GET | `/api/v1/statuses/{nid}/context` | — | Get conversation context (ancestors and descendants) |
| POST | `/api/v1/statuses/{nid}/{act}` | `act`: `favourite`, `unfavourite`, `boost`, `unboost` | Perform an action on a status |

### Timelines

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/timelines/{timeline}/` | `local`, `limit` (20), `max_id`, `min_id`, `since_id` | Get a timeline. `timeline` values: `home`, `account`, `public`, `direct`, `favourites` |
| GET | `/api/v1/timelines/tag/{hashtag}` | `limit` (20), `max_id`, `min_id`, `since_id`, `local`, `only_media` | Get posts with a specific hashtag |
| GET | `/api/v1/favourites/` | `limit` (20), `max_id`, `min_id`, `since_id` | Get liked/favourited posts |

### Notifications

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/notifications` | `limit` (20), `max_id`, `min_id`, `since_id`, `types[]`, `exclude_types[]`, `accountId` | Get notifications for the viewer |

### Media

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| POST | `/api/v1/media` | `file` (multipart upload) | Upload a media attachment |
| GET | `/api/v1/media/{nid}` | `preview` (optional) | Get media metadata by NID (stub, not fully implemented) |
| GET | `/media/{uuid}` | — | Serve a media file by UUID (supports optional `.ext` suffix) |

### Search

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/search` | `search` (query string) | Unified search across accounts, hashtags, and post content |
| GET | `/api/v1/global/accounts/search` | `search` | Search cached accounts by name/identifier |
| GET | `/api/v1/global/tags/search` | `search` | Search hashtags |

### Pagination

Endpoints that return lists support cursor-based pagination via these query parameters:

- `limit` — maximum number of items to return (default: 20)
- `max_id` — return items older than this ID
- `min_id` — return items newer than this ID
- `since_id` — return items created after this ID
- `since` — return items published after this Unix timestamp

---

## Custom Local API

### Streams

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/stream/home` | `since`, `limit` | Home timeline (posts from followed accounts) |
| GET | `/api/v1/stream/notifications` | `since`, `limit` | User notifications |
| GET | `/api/v1/stream/timeline` | `since`, `limit` | Local timeline (all local public posts) |
| GET | `/api/v1/stream/federated` | `since`, `limit` | Global/federated timeline |
| GET | `/api/v1/stream/direct` | `since`, `limit` | Direct messages |
| GET | `/api/v1/stream/liked` | `since`, `limit` | Liked posts |
| GET | `/api/v1/stream/tag/{hashtag}/` | `since`, `limit` | Posts by hashtag |
| GET | `/api/v1/account/{username}/stream` | `since`, `limit` | Posts by a specific account |

### Posts

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/local/v1/post` | `id` (ActivityPub ID) | Get a single post by its ActivityPub ID |
| GET | `/local/v1/post/replies` | `id`, `since`, `limit` | Get replies to a post |
| POST | `/api/v1/post` | `content`, `to[]`, `type`, `replyTo`, `attachments`, `hashtags[]` | Create a new post |
| DELETE | `/api/v1/post` | `id` | Delete own post |
| POST | `/api/v1/post/like` | `postId` | Like a post |
| DELETE | `/api/v1/post/like` | `postId` | Unlike a post |

### Current User

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/v1/current/info` | Get current user's actor info with complete details |
| GET | `/api/v1/current/followers` | Get current user's followers |
| GET | `/api/v1/current/following` | Get who the current user follows |
| PUT | `/api/v1/current/follow` | Follow an account (`?account=`) |
| DELETE | `/api/v1/current/follow` | Unfollow an account (`?account=`) |

### Account Info

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| GET | `/api/v1/account/{username}/info` | — | Get info for a local account (with cache fallback) |
| GET | `/api/v1/global/account/info` | `account` (e.g. `@user@domain`) | Get info for any local or remote account |
| GET | `/api/v1/global/actor/info` | `id` (ActivityPub actor ID) | Get actor info by raw ActivityPub ID |
| GET | `/api/v1/global/actor/avatar` | `id` | Serve an actor's avatar image |
| GET | `/api/v1/global/actor/header` | `id` | Redirect to an actor's banner/header image |

### Banner

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| POST | `/api/v1/banner` | `file` (multipart) | Upload a profile banner image |
| POST | `/api/v1/banner/url` | `url` | Download and set a profile banner from a URL |

### Config

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| POST | `/api/v1/config/cloudAddress` | `cloudAddress` | Set the cloud base URL in app config |

### System

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/local/` | Get app version and setup status |
| GET | `/test/{account}/` | Run a WebFinger test against an account (debug only, requires `social.tests` system config) |

---

## OAuth

The Social app implements a Mastodon-compatible OAuth flow.

| Method | Route | Parameters | Description |
|--------|-------|------------|-------------|
| POST | `/api/v1/apps` | `client_name`, `redirect_uris`, `website`, `scopes` | Register a new OAuth client app |
| GET | `/oauth/authorize` | `client_id`, `redirect_uri`, `response_type`, `scope` | Show OAuth authorization page |
| POST | `/oauth/authorize` | `client_id`, `redirect_uri`, `response_type`, `scope` | Confirm OAuth authorization |
| POST | `/oauth/token` | `client_id`, `client_secret`, `redirect_uri`, `grant_type`, `code` | Exchange authorization code for bearer token |

**Grant types:** `authorization_code`, `client_credentials`

**Default scope:** `read`

---

## ActivityPub Federation

The app implements the W3C ActivityPub protocol (Server-to-Server). All endpoints return `application/activity+json` content type for ActivityPub clients, and fall back to HTML for browsers.

### Actor

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/users/{username}` | Get ActivityPub Actor object for a local user |
| GET | `/@{username}/` | Alias for actor endpoint |

### Inbox

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/@{username}/inbox` | Receive ActivityPub activities for a specific user |
| GET | `/@{username}/inbox` | Get an empty OrderedCollection (inbox is not enumerable) |
| POST | `/inbox` | Shared inbox — receives activities for all local actors |

### Outbox

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/@{username}/outbox` | Get the user's outbox as an OrderedCollection |
| POST | `/@{username}/outbox` | Create a new activity in the outbox |

### Followers / Following

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/@{username}/followers` | Get the followers collection |
| GET | `/@{username}/following` | Get the following collection |

### Posts

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/@{username}/{token}` | Get a single post as ActivityPub JSON-LD |

### Discovery

| Route | Description |
|-------|-------------|
| `/.well-known/webfinger` | WebFinger endpoint for `acct:user@domain` lookups (returns `self` link to Actor) |
| `/.well-known/nodeinfo/2.0` | NodeInfo 2.0 document |

---

## Legacy OStatus

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/ostatus/follow/` | Renders follow page via OStatus (`?uri=`) |
| GET | `/api/v1/ostatus/followRemote/{local}` | Render guest follow page for remote account |
| GET | `/api/v1/ostatus/link/{local}/{account}` | Perform WebFinger and get OStatus subscribe link |

---

## Frontend / Document Serving

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/` | Main SPA entry point (renders the Vue app) |
| GET | `/timeline/{path}` | SPA timeline route |
| GET | `/document/get` | Get a cached document by ID (requires auth) |
| GET | `/document/public` | Get a public cached document by ID (no auth) |
| GET | `/document/get/resized` | Get a resized/preview of a cached document (requires auth) |
| GET | `/document/public/resized` | Get a public resized/preview document (no auth) |

---

## Async Queue

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/async/request/{token}` | Process queued ActivityPub federation requests for a given token |

---

## Error Responses

The API returns standard HTTP status codes:

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 201 | Created |
| 204 | No Content (deletion success) |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 405 | Method Not Allowed |
| 500 | Internal Server Error |

Error bodies are JSON: `{"message": "...", "code": ...}`
