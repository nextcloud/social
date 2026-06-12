# Nextcloud Social — OCC Commands Reference

All commands are invoked via `php occ <command>` from the Nextcloud root directory.

---

## Account Management

### `social:account:create`

Create a new social account for a Nextcloud user.

```
php occ social:account:create [--handle HANDLE] <userId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud username of the account |

| Option | Description |
|--------|-------------|
| `--handle` | Social handle (defaults to the `userId` if omitted) |

---

### `social:account:delete`

Delete a local social account.

```
php occ social:account:delete <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `account` | Yes | Social local account identifier |

---

### `social:account:following`

Follow or unfollow a remote or local account.

```
php occ social:account:following [--local] [--unfollow] <userId> <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user performing the action |
| `account` | Yes | Target account handle/address to follow |

| Option | Description |
|--------|-------------|
| `--local` | Indicates the target account is local |
| `--unfollow` | Unfollow instead of follow |

---

## Post / Note Management

### `social:note:create`

Create a new note (post) from a given user.

```
php occ social:note:create [-r|--replyTo REPLYTO] [-t|--to TO] [-y|--type TYPE] [-g|--hashtag HASHTAG] <user_id> <content>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID of the author |
| `content` | Yes | Content of the post |

| Option | Description |
|--------|-------------|
| `-r`, `--replyTo` | In-reply-to an existing thread ID |
| `-t`, `--to` | Mention specific people |
| `-y`, `--type` | Visibility type: `public` (default), `followers`, `unlisted`, `direct` |
| `-g`, `--hashtag` | Hashtag (without the leading `#`) |

---

### `social:note:boost`

Boost or unboost a note.

```
php occ social:note:boost [--unboost] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID (who is boosting) |
| `note_id` | Yes | Note ID to boost |

| Option | Description |
|--------|-------------|
| `--unboost` | Unboost instead of boost |

---

### `social:note:like`

Like or unlike a note.

```
php occ social:note:like [--unlike] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID (who is liking) |
| `note_id` | Yes | Note ID to like |

| Option | Description |
|--------|-------------|
| `--unlike` | Unlike instead of like |

---

## Timeline & Stream

### `social:timeline`

Get a timeline (stream of posts) for a given viewer.

```
php occ social:timeline [--local] [--min_id MIN] [--max_id MAX] [--since SINCE] [--limit N] [--account ACCOUNT] [--crop N] <userId> <timeline>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose timeline to view |
| `timeline` | Yes | Timeline name: `home`, `public`, `direct`, `notifications`, `liked`, `account`; or `#hashtag` |

| Option | Description |
|--------|-------------|
| `--local` | Local-only mode |
| `--min_id` | Minimum ID for pagination (default: 0) |
| `--max_id` | Maximum ID for pagination (default: 0) |
| `--since` | Since Unix timestamp (default: 0) |
| `--limit` | Number of items to return (default: 5) |
| `--account` | Filter by specific account |
| `--crop` | Character limit to crop content (default: 0 = no crop) |

Supports JSON output via `--output json`.

---

### `social:details`

Get details about a specific stream item (who can see it, on which timelines).

```
php occ social:details [--json] <streamId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `streamId` | Yes | ID of the stream item |

| Option | Description |
|--------|-------------|
| `--json` | Return output in JSON format |

---

## Queue Management

### `social:queue:process`

Process the request queue and stream queue (handles pending outbound federation and inbound stream processing).

```
php occ social:queue:process
```

No arguments or options.

---

### `social:queue:status`

Get status of a specific request queue item by its token.

```
php occ social:queue:status [-t|--token TOKEN]
```

| Option | Description |
|--------|-------------|
| `-t`, `--token` | Token of the request (mandatory) |

---

## Cache

### `social:cache:refresh`

Update cached data: local accounts, remote actors, documents, and hashtags.

```
php occ social:cache:refresh [-f|--force]
```

| Option | Description |
|--------|-------------|
| `-f`, `--force` | Enforce update of cached remote accounts |

---

## Federation

### `social:fediverse`

Manage federation access control — allow or deny access to specific Fediverse instances.

```
php occ social:fediverse [-t|--type TYPE] [action] [address]
```

| Argument | Description |
|----------|-------------|
| `action` | Action to perform: `add`, `remove`, `list`, `test`, `reset` (empty to list addresses) |
| `address` | Instance address/host to act upon |

| Option | Description |
|--------|-------------|
| `-t`, `--type` | Change the access type (blacklist/whitelist) |

**Actions:**
- _(empty)_ — list allowed/blocked addresses
- `list` — list both known and listed addresses
- `add <address>` — add an address to the list
- `remove <address>` — remove an address from the list
- `test <address>` — test if an address is authorized
- `reset` — clear the entire list

---

## Installation & Maintenance

### `social:check:install`

Check the integrity of the installation and optionally regenerate the index.

```
php occ social:check:install [--index]
```

| Option | Description |
|--------|-------------|
| `--index` | Regenerate the stream index (requires confirmation) |

Without `--index`:
- Checks installation status
- Removes invalid followers and notes
- Prints current configuration as JSON

With `--index`:
- Confirmation prompt ("Do you confirm this operation?")
- Empties `stream_dest` and `stream_tags` tables
- Regenerates the index for all streams with a progress bar

---

### `social:reset`

Reset ALL Social app data (destructive). Optionally perform a full uninstall.

```
php occ social:reset [--uninstall]
```

| Option | Description |
|--------|-------------|
| `--uninstall` | Full removal: drops all social tables, removes migrations, background jobs, and config |

Requires **double confirmation** before executing.

Without `--uninstall`:
- Empties all social data tables
- Re-runs installation checks
- Optionally allows changing the cloud base URL

With `--uninstall`:
- Drops all `social_*` database tables
- Removes migration entries from the migrations table
- Removes background jobs (Cron)
- Removes app configuration

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error / failure |

---

## Background Jobs

In addition to CLI commands, the app registers two background jobs that run automatically via Nextcloud's cron system:

| Job | Class | Interval | Description |
|-----|-------|----------|-------------|
| Cache maintenance | `OCA\Social\Cron\Cache` | Periodic | Refreshes actor cache, updates hashtag trends, performs key rotation, cleans deleted actors |
| Queue processing | `OCA\Social\Cron\Queue` | Periodic | Processes outbound ActivityPub delivery queue (sends pending activities to remote inboxes) |
