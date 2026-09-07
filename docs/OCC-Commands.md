# Nextcloud Social — OCC Commands Reference

All commands are invoked via `php occ <command>` from the Nextcloud root directory.

This page documents the fourteen commands the app registers in `appinfo/info.xml`.
Every command extends Nextcloud's `OC\Core\Command\Base`, so the generic
`--output plain|json|json_pretty` option exists on all of them, but only
`social:timeline` reads it (see below).

---

## Account Management

### `social:account:create`

Create the Social actor for an existing Nextcloud user.

```
php occ social:account:create [--handle HANDLE] <userId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud username of the account |

| Option | Value | Description |
|--------|-------|-------------|
| `--handle` | required | Social handle. If omitted, the `userId` is used as the handle. |

Fails with `Unknown user` if no such Nextcloud user exists. On success the command
prints nothing.

---

### `social:account:delete`

Delete a local Social account.

```
php occ social:account:delete <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `account` | Yes | Local Social account (the handle / preferred username) |

Prints nothing on success.

---

### `social:account:following`

Follow or unfollow an account on behalf of a local user.

```
php occ social:account:following [--local] [--unfollow] <userId> <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user performing the action |
| `account` | Yes | Account to follow (e.g. `user@example.org`) |

| Option | Value | Description |
|--------|-------|-------------|
| `--local` | none | Resolve `account` as a **local** account first, and use its canonical account name |
| `--unfollow` | none | Send an `Undo`/unfollow instead of a follow |

Prints progress lines (`Following account...`, the resolved local actor id and nid,
then the result). This is the only command that returns exit code `1` on a handled
failure; the rest let the exception surface.

---

## Post / Note Management

### `social:note:create`

Create a note (post) as a given user and federate it.

```
php occ social:note:create [-r|--replyTo REPLYTO] [-t|--to TO] [-y|--type TYPE] [-g|--hashtag HASHTAG] <user_id> <content>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID of the author |
| `content` | Yes | Content of the post |

| Option | Value | Description |
|--------|-------|-------------|
| `-r`, `--replyTo` | optional | Id of the post this one replies to |
| `-t`, `--to` | optional | A single mentioned account |
| `-y`, `--type` | optional | Visibility: `unlisted`, `followers` or `direct`. Anything else — including omitting the option — results in a **public** post; the value is not validated (`StreamService::setRecipient()`, `lib/Service/StreamService.php:115`). |
| `-g`, `--hashtag` | optional | A single hashtag, without the leading `#` |

`--to` and `--hashtag` each accept only one value. In addition,
`PostService::fixRecipientAndHashtags()` (`lib/Service/PostService.php:86`) scans the
content for `@mentions` and `#hashtags` and adds those too.

Prints the resulting activity as pretty JSON followed by `token: <request token>`
(written with `echo`, so `--output json` does not change it).

---

### `social:note:boost`

Boost (`Announce`) a note, or undo a boost.

```
php occ social:note:boost [--unboost] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID boosting the note |
| `note_id` | Yes | Id of the note |

| Option | Value | Description |
|--------|-------|-------------|
| `--unboost` | none | Undo the boost instead of creating one |

Prints the activity as pretty JSON plus `token: <request token>`.

---

### `social:note:like`

Like a note, or undo a like.

```
php occ social:note:like [--unlike] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID liking the note |
| `note_id` | Yes | Id of the note |

| Option | Value | Description |
|--------|-------|-------------|
| `--unlike` | none | Undo the like instead of creating one |

Prints the activity as pretty JSON plus `token: <request token>`.

---

## Timeline & Stream

### `social:timeline`

Print a timeline as seen by a given local viewer.

```
php occ social:timeline [--local] [--min_id MIN] [--max_id MAX] [--since SINCE] [--limit N] [--account ACCOUNT] [--crop N] <userId> <timeline>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose view is used. Fails with `Unknown user` if unknown. |
| `timeline` | Yes | See the table of supported values below |

| Option | Value | Default | Description |
|--------|-------|---------|-------------|
| `--local` | none | off | Restrict to local content |
| `--min_id` | required | `0` | Pagination bound |
| `--max_id` | required | `0` | Pagination bound |
| `--since` | required | `0` | Unix timestamp bound |
| `--limit` | required | `5` | Number of items |
| `--account` | required | `''` | A **local** account, resolved with `CacheActorService::getFromLocalAccount()`; used as the account filter |
| `--crop` | required | `0` | Truncate the printed content to N characters (`0` = no cropping) |

Supported `timeline` values (`StreamRequest::getTimeline()`,
`lib/Db/StreamRequest.php:410`):

| Value | Meaning |
|-------|---------|
| `home` | Own posts plus posts of followed accounts |
| `public` | Public timeline |
| `direct` | Direct messages |
| `account` | Posts of one account (combine with `--account`) |
| `favourites` | Liked posts |
| `notifications` | Notifications (rendered in the notification format) |
| `#<tag>` | A leading `#` selects the hashtag timeline for `<tag>` |

`ProbeOptions` also defines `followers` and `following`, but `getTimeline()` has no
case for them and silently returns an empty list (`lib/Db/StreamRequest.php:434`).
Any other value behaves the same way.

Output is a table (`Nid`, `Id`, `Source`, `Type`, `Author`, `Content`).
`--output json` switches this command to a JSON dump of the streams.

---

### `social:details`

Print who can see one stream item and which timelines it lands on.

```
php occ social:details [--json] <streamId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `streamId` | Yes | Id of the stream item. Fails with `Unknown item` if it does not exist. |

| Option | Value | Description |
|--------|-------|-------------|
| `--json` | none | Dump the details object as pretty JSON |

Without `--json` it prints the item id, author and type, then `Affected Timelines`
with the `Home` viewers, the `Direct` viewers, and the `Public` / `Federated` flags.
This command uses its own `--json` flag; the inherited `--output` option is ignored.

Note the command name is `social:details`, not `social:stream:details`, even though
the class is `OCA\Social\Command\StreamDetails`.

---

## Queue Management

### `social:queue:process`

Process both queues once: the outbound request queue (federation delivery) and the
stream queue (caching of not-yet-resolved incoming objects).

```
php occ social:queue:process
```

No arguments or options. Prints how many items are in each queue, how many are
processable right now, and a `.` per processed item.

---

### `social:queue:status`

Dump the request-queue rows belonging to one request token.

```
php occ social:queue:status [-t|--token TOKEN]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--token` | optional | Token of the request |

The option is declared optional but the command throws
`As of today, --token is mandatory` when it is missing
(`lib/Command/QueueStatus.php:70`). There is no way to list the whole queue with
this command. Each matching row is printed as one line of JSON.

---

## Cache

### `social:cache:refresh`

Run the cache maintenance steps once, printing a counter per step.

```
php occ social:cache:refresh [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Refresh cached remote actors even if they are not due |

Steps and their output lines: local accounts deleted, local accounts regenerated,
remote accounts created, remote accounts updated, remote accounts details updated,
documents cached, hashtags updated.

Key-pair rotation is **not** part of this command; the `blindKeyRotation()` call is
commented out (`lib/Command/CacheRefresh.php:51`).

---

## Federation

### `social:fediverse`

Inspect and change the Fediverse access list.

```
php occ social:fediverse [-t|--type TYPE] [<action>] [<address>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `''` | One of `list`, `add`, `remove`, `test`, `reset`, or empty |
| `address` | No | `''` | Address / host the action applies to |

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--type` | required | Set the access type. Only `all_but` (deny-list, the default) and `none_but` (allow-list) are accepted; anything else throws `invalid type` (`lib/Service/ConfigService.php:61`). |

Passing `--type` **sets the type and exits** — the `action` argument is not executed
in the same invocation (`lib/Command/Fediverse.php:53`). Without `--type`, the
command first prints the current access type and then runs the action:

| Action | Effect |
|--------|--------|
| _(empty)_ | Print the access list under `- List:` |
| `list` | Print `- Known address:` followed by the access list |
| `add <address>` | Add the address to the list |
| `remove <address>` | Remove the address from the list |
| `test <address>` | Print `Authorized` or `Unauthorized` for that address |
| `reset` | Empty the list |

An unknown action throws `specify action: add, remove, list, reset`.

**What is actually enforced.** There is a single list (`access_list`) whose meaning
depends on `access_type`: with `all_but` every address that is *not* listed is
allowed; with `none_but` only listed addresses and the local host are allowed.
`FediverseService::authorized()` is enforced on incoming activities
(`lib/Controller/ActivityPubController.php:183` and `:226`) and on every outgoing
HTTP request (`lib/Service/CurlService.php:259`), so the list does take effect for
inbox delivery and for fetching remote data.

**Known limitations:**

- `list` always prints an empty `Known address:` section, because
  `FediverseService::getKnownAddresses()` returns an empty array
  (`lib/Service/FediverseService.php:121`).
- The older two-list implementation (`blockAddress()`, `allowAddress()`,
  `isBlocked()`, `isAllowed()`, and the separate blacklist/whitelist config keys) is
  commented out (`lib/Service/FediverseService.php:178-255`). Only the single
  `access_list` above exists; there is no separate block list.
- Webfinger lookups are not filtered per address; `WebfingerHandler` only calls
  `jailed()`, which refuses service when the instance is in `none_but` mode with an
  empty list (`lib/WellKnown/WebfingerHandler.php:63`).
- Matching is exact string comparison against the host
  (`FediverseService::isListed()`); there is no wildcard or subdomain handling.

---

## Installation & Maintenance

### `social:check:install`

Check the integrity of the installation, or regenerate the stream index.

```
php occ social:check:install [--index]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--index` | none | Regenerate the stream index instead of running the checks |

Without `--index`:

- runs `CheckService::checkInstallationStatus()`,
- prints how many invalid followers and invalid notes were removed,
- prints the current app configuration as pretty JSON.

With `--index` the checks are skipped entirely. The command warns that the operation
takes a while, asks `Do you confirm this operation? (y/N)`, and on confirmation
empties `stream_dest` and `stream_tags` and rebuilds both for every stream, with a
progress bar. Answering anything but `y` exits without changes.

A `--push` option for testing Nextcloud Push integration is present in the source but
commented out (`lib/Command/CheckInstall.php:66-70`), so it is not available.

---

### `social:reset`

Delete all Social data, or uninstall the app's database footprint.

```
php occ social:reset [--uninstall]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--uninstall` | none | Full removal instead of a data flush |

The command always asks **two** confirmations before doing anything:

1. `Do you confirm this operation? (y/N)`
2. `Operation is destructive. Are you sure about this? (y/N)`

Answering anything but `y` to either question aborts with exit code `0`.

Without `--uninstall`:

- empties every Social table (`CoreRequestBuilder::emptyAll()`),
- re-runs `checkInstallationStatus(true)`,
- offers to change the cloud base address, pre-filled with the current one; entering
  the same value leaves it unchanged.

With `--uninstall`:

- drops the Social tables,
- removes the app's rows from the migrations table,
- removes the app's background jobs,
- unsets the app configuration.

The app files themselves are not removed, and the app is not disabled.

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success, and also an aborted confirmation prompt or a caught error in `social:reset` |
| 1 | `social:account:following` handled failure, or an uncaught exception in any command |

---

## Background Jobs

The app also registers two `TimedJob`s, both with an interval of 12 minutes, run by
Nextcloud's cron:

| Job | Class | Description |
|-----|-------|-------------|
| Cache maintenance | `OCA\Social\Cron\Cache` | Same steps as `social:cache:refresh` (deleted actors, local actor cache, remote actors and their details, documents, hashtags), and additionally syncs the timelines of cached remote actors. No key rotation is performed. |
| Queue processing | `OCA\Social\Cron\Queue` | Processes the outbound request queue **and** the stream queue, like `social:queue:process`. |
