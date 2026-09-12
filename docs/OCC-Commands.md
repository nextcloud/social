# Nextcloud Social — OCC Commands Reference

All commands are invoked via `php occ <command>` from the Nextcloud root directory.

This page documents the twenty commands the app registers in `appinfo/info.xml`.
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

### `social:account:alias`

Manage the `alsoKnownAs` list of a local account: the actor ids it also answers
to. Setting one is the first step of moving an account **to** this server — a
Mastodon (or other) server refuses to start a Move towards an account that does
not list the moving one here.

```
php occ social:account:alias [--add URL] [--remove URL] [--list] <userId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor is aliased |

| Option | Value | Description |
|--------|-------|-------------|
| `--add` | required | Actor id to add — the `https://…` address of the old account (`https://mastodon.example/users/alice`), **not** its `alice@mastodon.example` handle. Adding one that is already listed changes nothing. |
| `--remove` | required | Actor id to remove. Removing one that is not listed changes nothing. |
| `--list` | none | Print the current list. This is also what happens with no option at all. |

`--add` and `--remove` cannot be combined in one run. Every change refreshes the
actor cache, so the new list is on the actor document at once; the remote server
reads it when the Move is started there.

Prints `added <url>` / `removed <url>` followed by the list, or `no alias set.`.
Exit code `1` when `userId` has no actor or the value is not an `http(s)://` URL.

---

### `social:account:move`

Move a local account to one on another server, the way Mastodon's account
migration works: the new account has to list this one in its `alsoKnownAs`, every
follower is sent a `Move`, and the account here is marked as moved.

```
php occ social:account:move [-f|--force] <userId> <target>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor moves away |
| `target` | Yes | Actor id of the new account — its `https://…` address, not its handle |

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Do not ask for confirmation (required with `--no-interaction`) |

What happens, in order:

1. The target is fetched fresh from its server (not read from the cache) and
   must list this account's actor id in `alsoKnownAs` — the same check
   `MoveInterface` applies to an incoming Move. Without it the command stops
   with exit code `1` and nothing changes; add the alias on the new account
   first (in Mastodon: *Preferences → Account → Moving from a different
   account*).
2. A `Move{actor, object: this actor, target}` is signed and queued through the
   ordinary delivery queue to every follower's inbox and to the new account's
   inbox. Servers that support migration re-follow the target for their users
   and drop the follow of the old account.
3. `movedTo` is recorded on the actor: it appears on the actor document and as
   `moved` on the account entity, so clients show the "has moved" banner.
4. Followers **on this server** never receive the Move (deliveries to ourselves
   are dropped), so the command follows the new account on their behalf through
   the ordinary follow path. One follower that cannot be re-followed is logged
   and does not stop the others.

The command asks for confirmation first; under `--no-interaction` it refuses
unless `--force` is given, rather than moving an account because nobody was
there to say no. Posts stay where they are — like Mastodon, a move carries the
followers, not the content.

---

### `social:account:import-follows`

Follow every account of a Mastodon `following_accounts.csv` export from a local
account: the other half of moving an account **to** this server.

```
php occ social:account:import-follows <userId> <csv>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor follows the accounts |
| `csv` | Yes | Path to the export. The current Mastodon format with the header `Account address,Show boosts,Notify on new posts,Languages` is read by its `Account address` column wherever it is; the older format — one handle per line, no header — works too. |

Each handle goes through the same path as `social:account:following`: the
account is resolved (WebFinger), a `Follow` is queued, and a handle already
followed is left alone. A leading `@` is dropped, repeats are followed once, and
a line that is not a `user@host` handle is ignored. The other columns (boosts,
notifications, languages) are not imported.

One handle that fails — an unreachable instance, an account that no longer
exists — is reported and does not stop the rest. The command prints how many
were followed, how many were skipped (the importing account's own handle) and
how many failed, each with its reason. Exit code `1` when the file cannot be
read, or when something was asked for and none of it could be followed.

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
| `-y`, `--type` | optional | Visibility: `public`, `unlisted`, `followers` (Mastodon's `private` is accepted as a synonym) or `direct`. Anything else — including omitting the option — becomes **`direct`**, the most restrictive option, because `Post::setType()` maps a value it does not know through `Stream::visibilityFromClient()`. Note the option's own `--help` text still says "public (default)", which is not what happens. |
| `-g`, `--hashtag` | optional | A single hashtag, without the leading `#` |

`--to` and `--hashtag` each accept only one value. In addition,
`PostService::fixRecipientAndHashtags()` scans the
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

Supported `timeline` values (the `switch` in `StreamRequest::getTimeline()`):

| Value | Meaning |
|-------|---------|
| `home` | Own posts plus posts of followed accounts |
| `public` | Public timeline |
| `direct` | Direct messages |
| `account` | Posts of one account (combine with `--account`) |
| `favourites` | Liked posts |
| `bookmarks` | Posts the viewer bookmarked |
| `notifications` | Notifications (rendered in the notification format) |
| `#<tag>` | A leading `#` selects the hashtag timeline for `<tag>` |

Matching is case-insensitive. `ProbeOptions` also defines `followers` and
`following`, but `getTimeline()` has no case for them and silently returns an
empty list. Any other value behaves the same way.

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

### `social:stream:prune`

Deletes remote statuses older than the retention period, together with their
recipient/action/tag rows and cached attachments.

```bash
php occ social:stream:prune [-d|--days DAYS] [--dry-run]
```

- Without `--days`, the `retention_days` app setting decides the period; `0`
  (the default) disables retention and the command exits without touching
  anything.
- `--dry-run` only counts what would be deleted.
- A status is kept when a local user liked, boosted, replied to or bookmarked
  it, when a local user follows its author, when a local status replies to it
  or boosts it, or when it is a direct message. Local content is never touched.
- The same pruning runs from the `Cron\Cache` background job (bounded to 5000
  statuses per run) whenever `retention_days` is greater than 0; the admin can
  change the period in the Social section of the administration settings.

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

Report the state of the outbound delivery queue, or dump the rows belonging to
one request token.

```
php occ social:queue:status [-t|--token TOKEN]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--token` | optional | Token of the request; without it, the whole queue is summarised |

With a token, each matching row is printed as one line of JSON.

Without one, the command prints how many deliveries are waiting and how many are
being sent, how many have already failed at least once and how many are close to
being abandoned (a delivery is dropped after 15 attempts), followed by the worst
instances — how many deliveries are stacked up for each, the highest attempt
count so far and when it was last tried. The same figures appear in the
Federation health section of the administration settings.

---

### `social:queue:retry`

Put queued deliveries back on standby, or drop them.

```
php occ social:queue:retry [-t|--token TOKEN] [--min-tries N] [--limit N]
                           [--stream] [--flush] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--token` | optional | Act on one delivery only, by the token `social:queue:status` prints |
| `--min-tries` | int (1) | Without a token, act on the rows that have already failed at least this many times |
| `--limit` | int (500) | How many rows one run touches at most; run it again to work through the rest |
| `--stream` | none | Act on the inbound stream queue (`social_stream_queue`) instead of the outbound delivery queue |
| `--flush` | none | Delete the matching rows instead of queueing them again |
| `-f`, `--force` | none | Do not ask for confirmation (required with `--no-interaction`) |

Retrying clears the attempt count and sets the row back to standby, so it gets the
full run of retries again on the next queue cron (or `occ social:queue:process`)
rather than being abandoned on its next failure. `--flush` deletes the rows: those
activities are never delivered, which is what you want for a delivery that will
never succeed — a peer that is gone, or an activity it refuses.

Rows that already succeeded are never touched, so a retry cannot send an activity
twice. The command prints how many rows matched, across how many delivery tokens
and with what spread of attempt counts, and asks before it changes anything.

---

## Development

### `social:benchmark`

Seeds a plausible amount of content and times the queries behind the timelines, because a query's cost cannot be judged on the couple of dozen rows a development instance holds. **For development instances only** — it writes thousands of rows.

```
php occ social:benchmark [--actors=200] [--notes=5000] [--follows=150] [--viewer=USER]
                         [--seed-only] [--time-only] [--clean] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--actors` | int (200) | Remote actors to seed |
| `--notes` | int (5000) | Public notes to seed, spread over the preceding weeks |
| `--follows` | int (150) | How many of those actors the viewer follows, which is what the home timeline joins through |
| `--viewer` | username | The local account the timelines are read as; the first local actor by default |
| `--seed-only` | none | Write the rows without timing anything |
| `--time-only` | none | Time what is already seeded |
| `--clean` | none | Delete everything the command wrote and nothing else |
| `-f`, `--force` | none | Seed without asking (required with `--no-interaction`) |

Seeding says how many rows it is about to write and asks before writing any of
them; under `--no-interaction` it refuses unless `--force` is given, rather than
seeding a production database because nobody was there to say no. `--clean` and
`--time-only` write nothing and do not ask.

Every row it writes carries `benchmark.invalid` in its id, which is what `--clean` matches on. The reported time is the second run of each query, so it measures a served request rather than a cold cache.

---

## Cache

### `social:cache:refresh`

Run the cache maintenance steps once, printing a counter per step.

```
php occ social:cache:refresh [-f|--force] [--rotate-keys]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Refresh cached remote actors even if they are not due |
| `--rotate-keys` | none | Renew the RSA key pair of local actors older than `AccountService::KEY_PAIR_LIFESPAN` (60) days. Blind rotation: no `Update` is federated, remote servers pick the new key up when they next fetch the actor (most do so after a failed signature check), so expect a short delivery hiccup. Deliberately opt-in and never run by the cron |

Steps and their output lines: local accounts deleted, local accounts regenerated,
remote accounts created, remote accounts updated, remote accounts details updated,
documents cached, hashtags updated. With `--rotate-keys`, `N key pairs refreshed`
is printed first.

Rotation is the only step that is opt-in, and it is the only way to rotate a key
pair: nothing else calls `AccountService::blindKeyRotation()`, and the cron never
does.

---

## Federation

### `social:fediverse`

Inspect and change the Fediverse access list.

```
php occ social:fediverse [-t|--type TYPE] [<action>] [<address>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `''` | One of `list`, `add`, `remove`, `test`, `reset`, `silence`, `unsilence`, `silenced`, or empty |
| `address` | No | `''` | Address / host the action applies to |

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--type` | required | Set the access type. Only `all_but` (deny-list, the default) and `none_but` (allow-list) are accepted; anything else throws `invalid type` (`FediverseService::setAccessType()`). |

Passing `--type` **sets the type and exits** — the `action` argument is not executed
in the same invocation (`Fediverse::typeAccess()` returns true and the command
returns). Without `--type`, the
command first prints the current access type and then runs the action:

| Action | Effect |
|--------|--------|
| _(empty)_ | Print the access list under `- List:` |
| `list` | Print `- Known address:` followed by the access list |
| `add <address>` | Add the address to the list |
| `remove <address>` | Remove the address from the list |
| `test <address>` | Print `Authorized` or `Unauthorized` for that address |
| `reset` | Empty the list |
| `silence <address>` | Silence the instance: keep it out of the public, global and hashtag timelines |
| `unsilence <address>` | Lift the silence |
| `silenced` | Print the silenced instances under `- Silenced:` |

An unknown action throws
`specify action: add, remove, list, reset, silence, unsilence, silenced`.
`silence` and `unsilence` without an address throw `specify an address to
silence` / `... to unsilence`.

**Silencing, the tier between a block and nothing.** A block cuts the instance
off in both directions and `social:domain:purge` deletes what it already sent,
which also cuts off the local users who deliberately follow somebody there — so
the tool was too blunt to reach for and the nuisance stayed. A silence stores
the host in a second list (`silenced_list`) and changes one thing: posts whose
author is on that instance are left out of the **public**, **global**,
**hashtag** and **followed-tag** timelines (`StreamRequest::filterSilencedInstances()`).
Delivery, fetching, webfinger, search by address, following, and the home
timeline of somebody who follows the account are all untouched — a silence is
not enforced in `authorized()`. Nothing is deleted, so `unsilence` brings the
posts back.

The match is on the author's actor id, so it covers the domain **and everything
under it** the way a deny-list entry does: silencing `noisy.test` also silences
`sub.noisy.test`, and not `notnoisy.test`. The two lists are independent — an
instance can be silenced, blocked, both or neither.

**What is actually enforced.** There is a single list (`access_list`) whose meaning
depends on `access_type`: with `all_but` every address that is *not* listed is
allowed; with `none_but` only listed addresses and the local host are allowed.
`FediverseService::authorized()` is enforced on both inbox routes in
`ActivityPubController` and on every outgoing HTTP request in `CurlService`, so the
list does take effect for inbox delivery and for fetching remote data. A refused
inbox delivery is answered **403**, not 500, so the peer stops redelivering it —
see the rejection table in `docs/Architecture.md`.

**How an address is matched.** Case-insensitively, and without the trailing dot of
the absolute form. The two modes then read the list differently, on purpose:

- With `all_but`, a listed domain covers the domain itself **and everything under
  it** (`isListed()`). Blocking `evil.test` while `www.evil.test` walks straight
  back in is not a block.
- With `none_but`, an entry matches **exactly** (`isExactlyListed()`). A subdomain
  of an allowed domain is a different instance, and whoever runs the parent domain
  was never asked before it appeared.

There is no wildcard syntax; `add` stores what you type, and `remove` takes it away
by the same exact comparison.

**Known limitations:**

- `list` always prints an empty `Known address:` section, because
  `FediverseService::getKnownAddresses()` returns an empty array.
- The older two-list implementation (`blockAddress()`, `allowAddress()`,
  `isBlocked()`, `isAllowed()`, and the separate blacklist/whitelist config keys) is
  commented out at the end of `lib/Service/FediverseService.php`. Only the single
  `access_list` above exists; there is no separate block list.
- Webfinger lookups are not filtered per address; `WebfingerHandler` only calls
  `jailed()`, which refuses service when the instance is in `none_but` mode with an
  empty list.

### `social:emoji`

The custom emoji this instance publishes.

```
php occ social:emoji [-c|--category CATEGORY] [--hidden] [<action>] [<shortcode>] [<file>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `list` | One of `list`, `add`, `remove` |
| `shortcode` | No | `''` | Required by `add` and `remove`. The name between the colons: 2–64 characters of `a-z`, `0-9` and `_`. Read lowercase and trimmed, so `BlobCat` and `blobcat` are the same emoji |
| `file` | No | `''` | Required by `add`. A readable path to the picture |

| Option | Value | Description |
|--------|-------|-------------|
| `-c`, `--category` | required | Group it under this in a client's picker |
| `--hidden` | none | Store it usable by name but not offered in a picker (Mastodon's `visible_in_picker: false`) |

| Action | Effect |
|--------|--------|
| `list` _(the default)_ | Print every emoji with its category, whether a picker offers it, and the URL it is served from |
| `add <shortcode> <file>` | Publish the picture under that shortcode |
| `remove <shortcode>` | Stop publishing it |

An unknown action throws `specify action: list, add, remove`; removing one that
is not there throws `no emoji is published as :<shortcode>:`.

**What is accepted.** A PNG, GIF, WebP or JPEG of at most **256 KiB** — the
bytes decide, not the extension, because this is served to every reader of
every post that uses it. Anything else is refused with a reason.

**What `add` does to a shortcode already in use.** Replaces the picture. A
shortcode names one emoji, and an admin re-uploading under a name in use means
to replace it rather than to be told it exists.

**What a post carries.** The shortcode stays in the content as text, and an
`Emoji` tag beside it says where the picture is — which is how every fediverse
server does it, and why an instance that has never heard of `:blobcat:` still
renders the post. A shortcode this instance has no picture for is left as the
text it already was. Editing a post rebuilds its tags, so a shortcode added by
an edit renders and one removed by an edit takes its tag with it.

**What `remove` does to posts that used it.** Nothing. What they carry is the
tag they were federated with; this instance no longer offering the picture does
not rewrite what was already said. Locally, the picture stops being served, so
the shortcode shows as text again.

### `social:domain:purge`

Delete everything an instance already sent this one. Blocking the domain
(`social:fediverse add`) is what stops it coming back; this removes what arrived
before the block.

```
php occ social:domain:purge [-b|--batches BATCHES] [--check] <domain>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `domain` | Yes | The instance to purge, e.g. `spam.example`. Accepts what a user would type — `https://spam.example/@someone`, `@someone@spam.example`, `Spam.Example:8443` — and reduces it to the host |

| Option | Value | Description |
|--------|-------|-------------|
| `-b`, `--batches` | required | Stop after this many batches of 50 accounts instead of running to the end. Without it the command runs until nothing of the domain is left |
| `--check` | none | Only report whether anything of the domain is still stored; deletes nothing |

**What goes.** For every account of that instance still known here: its cached
actor, its posts (with their recipient, tag, action, card, revision and cached
attachment rows), the notifications they caused, the follows in **both**
directions, the per-user blocks, mutes and notes about it, and the deliveries
still queued towards it. Leaving the follows behind kept the instance in the
delivery fan-out of every local post.

**This cannot be undone.** Unblocking the domain lets it reach the instance
again; it does not restore anything deleted here. The command says so before it
exits.

**Safe to interrupt and safe to repeat.** Each pass asks what of the domain is
still stored rather than counting off a position, so a run that is killed half
way is resumed by running it again, and a run against an already-purged domain
does nothing. Accounts are found from three places — the actor cache, the
authors of stored posts, and the follow rows — because a post can outlive the
actor it was cached from and a follow can name an account this instance never
cached.

**The exact host, not subdomains.** A deny-list entry covers everything under
the domain (`isListed()`), but the purge does not: refusing traffic from one
instance too many is undone by editing the list, and deleting one is not. Purge
each subdomain you mean to include.

**This normally runs on its own.** Adding a domain to the deny list queues
`OCA\Social\Cron\DomainPurge`, which does 10 batches per cron run and re-queues
itself until the instance is gone. Use this command for a domain blocked before
the purge existed, for a job that failed part way, or to finish without waiting
for cron.

---

## Installation & Maintenance

### `social:check:install`

Check the integrity of the installation, or regenerate the stream index.

```
php occ social:check:install [--index] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--index` | none | Regenerate the stream index instead of running the checks |
| `-f`, `--force` | none | Skip the confirmation of `--index` (required with `--no-interaction`) |

Without `--index`:

- runs `CheckService::checkInstallationStatus()`,
- prints how many invalid followers and invalid notes were removed,
- reports whether the address Social builds ids from still matches the one the server reports, printing both and what it would cost to change either when they disagree (the `.well-known` probe is not run here: it needs a request and a session cache the console does not have — the app shows that one on its first screen),
- prints the current app configuration as pretty JSON.

With `--index` the checks are skipped entirely. The command warns that the operation
takes a while, asks `Do you confirm this operation? (y/N)`, and on confirmation
empties `stream_dest` and `stream_tags` and rebuilds both from `social_stream`, a
few hundred rows at a time, with a progress bar. Answering anything but `y` exits
without changes; under `--no-interaction` it refuses unless `--force` is given
(exit code `1`), because the index tables are truncated before the rebuild starts
and a run that stops there leaves every timeline empty.

Rows it cannot parse are reported at the end (the first ten in full, then a count)
and make the command exit `1`; the rest of the index is still rebuilt.

---

### `social:reset`

Delete all Social data, or uninstall the app's database footprint.

```
php occ social:reset [--uninstall] [--uri ADDRESS] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--uninstall` | none | Full removal instead of a data flush |
| `--uri` | address | The cloud base address to rebuild every id from, instead of being asked for it. This is the option `social:check:install` names when the configured address no longer matches the server |
| `-f`, `--force` | none | Skip both confirmations (required with `--no-interaction`) |

The command asks **two** confirmations before doing anything:

1. `Do you confirm this operation? (y/N)`
2. `Operation is destructive. Are you sure about this? (y/N)`

Answering anything but `y` to either question aborts with exit code `0` and changes
nothing. Under `--no-interaction` the command refuses with exit code `1` unless
`--force` is given: a confirmation prompt answers itself with its default when
nobody is there, so without this the command would exit `0` having done nothing.

Without `--uninstall`:

- empties every Social table (`CoreRequestBuilder::emptyAll()`),
- re-runs `checkInstallationStatus(true)`,
- sets the cloud base address to `--uri`, or, with somebody at the keyboard and no
  `--uri`, offers to change it, pre-filled with the current one; entering the same
  value leaves it unchanged. Non-interactively and without `--uri` the address is
  left as it was.

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
| 0 | Success, and also a confirmation prompt answered with "no" |
| 1 | A refusal to act non-interactively without `--force` (`social:reset`, `social:check:install --index`, `social:benchmark`, `social:queue:retry`), a failed flush or uninstall in `social:reset`, streams `social:check:install --index` could not parse, `social:account:following` handled failure, or an uncaught exception in any command |

---

## Background Jobs

The app registers three `TimedJob`s in `appinfo/info.xml`, run by Nextcloud's
cron, and queues two more on demand:

| Job | Class | Description |
|-----|-------|-------------|
| Cache maintenance | `OCA\Social\Cron\Cache` | Every 12 minutes. Same steps as `social:cache:refresh` (deleted actors, local actor cache, remote actors and their details, documents, hashtags), and additionally syncs the timelines of cached remote actors. No key rotation is performed. |
| Queue processing | `OCA\Social\Cron\Queue` | Every 12 minutes. Processes the outbound request queue **and** the stream queue, like `social:queue:process`. |
| Scheduled posts | `OCA\Social\Cron\ScheduledPosts` | Every 5 minutes. Publishes the posts whose `scheduled_at` has passed, at most 50 per run. Shorter than the other two on purpose: a scheduled post may be published up to one cron period late, and a longer period would promise a precision the five-minute minimum on `scheduled_at` implies but the app could not keep. |
| Domain purge | `OCA\Social\Cron\DomainPurge` | Queued with a domain when one is added to the deny list — not registered in `appinfo/info.xml`, because a job listed there is added once at install time with no argument. Does 10 batches of 50 accounts per run and re-queues itself while anything of the domain is left. |
| Actor cleanup | `OCA\Social\Cron\ActorCleanup` | Queued with an actor id when a deleted account is addressed by more posts than one inbox request should rewrite — not in `appinfo/info.xml`, for the same reason as the domain purge. Rewrites 2000 posts per run and re-queues itself while any remain. Without it, the rewrite ran inline in the request a peer was waiting on for its `Delete`, so the peer timed out, re-sent, and the work started over. |
