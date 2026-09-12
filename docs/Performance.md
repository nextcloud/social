# Performance and scalability

A survey of the query and scalability behaviour of this app: what costs what,
what is bounded, and what is still known to be wrong. Every claim below was
checked against the code rather than carried over from a previous survey.

**Verified against:** app version 0.16.0, `master`, 2026-09-12 — re-checked
after the federation, compatibility and dependency waves landed.

This file is **not** enforced by `tests/DocumentationTest.php` — its claims are
about behaviour rather than about routes or schema rows, and a test that tried
to check them would either be brittle or would be the fix. It therefore goes
stale silently. Re-verify before trusting a line of it, and update it in the
same change as any work on a read path.

## What is still open

### Unbounded work

| Where | What happens | Cost grows with |
|-------|--------------|-----------------|
| `CacheDocumentsRequest::getNotCachedDocuments()` | No `setMaxResults`. `DocumentService::manageCacheDocuments()` loops the whole set with one outbound HTTP fetch per row, so a backlog of uncached avatars is attempted in a single cron slot. Its two siblings are capped — `CacheActorsRequest::getRemoteActorsToUpdateDetails()` at `SYNC_BATCH`, `StreamQueueRequest::getStandby()` at `STANDBY_BATCH` — and this one was missed. **The last unbounded cron loop.** | uncached remote media |
| `PersonInterface::deleteStreamFromActor()` | An incoming `Delete` for a remote actor walks `StreamDestRequest::getRelatedToActor()` — which takes a `$limit` and is called without one — and issues a `getStream()` and possibly an `update()` per row, inline in the HTTP request the peer is waiting on. The peer times out and re-sends, and the work starts again. | posts that ever addressed that actor |
| `FollowService::getFollowers()` | Returns every follower row, hydrated with its `Person` and details, for `LocalController::followers()`. The delivery path no longer goes near it (see below); this is the web UI's own list, and it has no paging at all. | one account's followers |
| `HashtagsRequest::getAll()` | `HashtagService::manageHashtags()` calls it with no limit on every cron run, reading the whole hashtag table into memory to diff it against five aggregates. | distinct hashtags ever used |

### One query per row on two read paths

- **`GET /api/v1/accounts/search?following=true`** filters the candidates by
  calling `FollowService::getRelationshipWith()` once per account, and each of
  those is its own `social_follow` lookup plus the block, mute and note reads
  behind a `Relationship`. Bounded — the candidate list is sliced to `limit`
  first, at most 80 — but it is still up to 80 round trips to answer one
  autocomplete keystroke, and clients call this on every keystroke.
- **`FollowService::getRelationships()`**, which `/api/v1/accounts/relationships`
  uses, resolves the actors in one batch and then builds each relationship the
  same one-at-a-time way. A client asking about a page of 40 accounts pays 40
  times.

Both want the same thing: one query that reads the viewer's follow, block, mute
and note rows for a *set* of actor ids. The join already exists per row; it is
the loop around it that is the cost.

### No transactions, anywhere

`beginTransaction` appears nowhere in `lib/`. Three places where that is
visible:

- `StreamRequest::save()` — insert the stream, then N recipient rows, then M
  tag rows. `StreamDestRequest::create()` swallows every `DBException`
  silently, and the recipient rows are what put a post in a timeline: a partial
  failure leaves a post that exists and is in nobody's timeline, permanently
  and invisibly.
- `StreamActionService::saveAction()` — update, and insert if no row was
  affected. Two concurrent likes both see nothing affected and both insert. On
  MySQL/MariaDB `rowCount()` returns *changed* rows, so setting a flag to the
  value it already holds takes the same path. `ActorRelationRequest` and
  `StreamCardsRequest` already use the insert-then-catch-unique-then-update
  shape that avoids this.
- `ModerationRequest::save()` — delete then insert, with the insert failure only
  logged: the old decision is gone and the new one was never applied.

### Wide `SELECT DISTINCT`

`getStreamNidsSelectSql()` exists precisely to avoid deduplicating over every
column — it selects nids, then hydrates. Seven call sites use it: the home and
public branches of `getTimeline()`, the marked timelines (favourites and
bookmarks), the list timeline in `ListsRequest`, and the deprecated direct
timeline. **Eighteen** call sites in `StreamRequest` still go through
`getStreamSelectSql()`, which pairs `selectDistinct('s.id')` with the full
stream column set plus, on some paths, a second `os_*` stream set and two
cached-actor and cached-document sets. The database sorts or hashes all of it —
including `content`, `source`, `details`, `cache` and `to_array` — to
deduplicate. Direct messages, account timelines, hashtag timelines,
notifications, search, `getNoteSince` and `getDescendants` are the ones worth
moving.

### Lookups that cannot use an index

The `*_prim` columns (md5 of the lower-cased id) exist so a lookup can be an
indexed equality. The hot paths now use them, but `ActorsRequest`'s
`preferred_username` lookups still compare `LOWER(column)` against
`LOWER(?)` on an unindexed column — the public ActivityPub actor endpoint and
webfinger both land there.

### Schema shape

- `social_follow`'s unique indexes `afoa` and `aoa` both **lead with the
  `accepted` boolean**, so `(object_id_prim, actor_id_prim)` is not unique: one
  accepted and one pending row for the same pair can coexist. This is the one
  schema item on this list that is a correctness risk rather than a cost.
- Several indexes from the 2022 migration are unnamed, so Doctrine names them
  per install and a later `hasIndex()`/`dropIndex()` cannot refer to them. Index
  names from that era are also not app-prefixed (`sa`, `ts`, `aoa`, …) and live
  in PostgreSQL's schema-global namespace, where another app can collide with
  them. Migrations from 2026 use `social_*`.
- `Version1000Date20230217000002` adds `social_cache_doc.account` as `NOT NULL`
  with no default. Fresh installs and MySQL are fine; a PostgreSQL upgrade with
  existing rows fails with "column contains null values".
- `social_stream_act.bookmarked` is `SMALLINT` while its three sibling flags are
  `BOOLEAN`.
- The four `NOT EXISTS` clauses in `StreamPruneService` are assembled by calling
  `getSQL()` on separate query builders and concatenating the strings. The
  sub-builders have their own parameter namespaces, so a
  `createNamedParameter()` added inside one later would silently vanish from the
  outer query.

## What was fixed, and what fixed it

The previous survey (2026-09-10, against 0.11.49) is largely answered. Kept
here so a reader who finds that report knows why the code no longer matches it.

| Then | Now |
|------|-----|
| Anonymous reads cross-joined the whole `social_follow` table: the no-viewer branch called `selectDestFollowing($aliasDest)` without the second argument, so the join had no predicate | Both branches pass `''`. On an instance with no follow rows, anonymous status fetches and public timelines work |
| `social_cache_doc.id_prim` — the join key for every avatar and attachment — had no index | `Version1000Date20260910000001` adds `social_cd_idp` and `social_cd_pidp` |
| Every id/account lookup wrapped the column in `LOWER()` on unindexed text | The hot lookups take the `*_prim` path (`limitToIdPrimString()` and friends) |
| Missing indexes on favourites, bookmarks, hashtag timelines, like/boost counts, both queue drains, and `social_client.token` — the last one a full scan of the client table on every API request | All added by `Version1000Date20260910000001`, which also drops the redundant five-column `ipoha` index |
| Deleting a post left rows in five tables and files on disk | `deleteById()`/`deleteByAuthor()` go through the related-row deletion |
| `occ social:reset --uninstall` left two tables behind | Every declared table is in `$tables`, and `tests/Db/SchemaConventionsTest.php` fails if a new constant is not |
| Two cron loops did unbounded outbound HTTP per row | `getRemoteActorsToUpdateDetails()` and `getStandby()` are capped (one remains — see above) |
| The admin report list resolved each reported account one at a time, fetching over the network when uncached: one unreachable instance hung the page | `ReportService::getReports()` resolves them in one batch from the cache only |
| `filterSilencedActors()` was dead code, so a silenced account was not silenced | Called from the three read paths that need it |
| `HashtagService::manageHashtags()` ran five hydrated wide queries and one write per hashtag, and reported the same number for every window | `StreamRequest::countHashtagsSince()` is a grouped aggregate |
| `CacheActorsRequest::getSharedInboxes()` returned `''` for actors with no shared inbox, which the caller turned into a delivery to the host `''` | Empty values and local actors are excluded in SQL |
| The delivery fan-out hydrated every follower into a `Follow` with a `Person` and its details to read one string off each | `ActivityService::generateInstancePathsFollowers()` asks `FollowsRequest::getFollowerInboxes()` for one row per distinct inbox, resolved in the database — the number of *instances*, not of followers |
| `MigrationService` re-followed on behalf of every local follower from one unbounded read | Paged at `REFOLLOW_PAGE`, bounded at `REFOLLOW_MAX` |
| `social_actor.user_id` had no index, and it is what resolves the logged-in user's actor on every authenticated request | `Version1000Date20260912000001` adds `social_a_uid`, and the four `social_hashtag` trend columns got theirs |
| `ReportService` and the account entity leaked `source` on every read, so every Account query carried `follow_requests_count` | `source` is built by the two credentials routes only; nothing else asks the database for it |

## What to do next

Ordered by what it buys against what it costs, not by severity. The first three
are an afternoon each; the fourth is the one that changes how the app scales.

1. **Cap `getNotCachedDocuments()`.** One line, and it is the last unbounded
   cron loop in the app. An instance that has been offline for a day currently
   tries to fetch its whole media backlog in one slot.
2. **Bound `deleteStreamFromActor()`.** It is the only unbounded read left on an
   *inbound request* path, and the failure mode is the bad one: the peer times
   out, re-sends the `Delete`, and the work starts over. Page it, or move it to
   the stream queue where the rest of the inbox work already lives.
3. **One query for a set of relationships.** Kills the N+1 behind
   `accounts/search?following=true` and `accounts/relationships` at once, and
   those are the two routes a client calls most often per keystroke and per
   page.
4. **Move the eighteen read paths onto `getStreamNidsSelectSql()`.** This is the
   big one: every timeline that is not home or public still makes the database
   sort or hash `content`, `source`, `details`, `cache` and `to_array` to
   deduplicate a page of twenty rows. It is also the most mechanical — the
   pattern exists, it is proven on five paths, and each move is
   independently testable. Do notifications first: it carries two full stream
   column sets, two cached-actor sets and two cached-document sets, and it is
   polled by every client on a timer.
5. **A transaction around `StreamRequest::save()`**, and the
   insert-then-catch-unique shape for `StreamActionService::saveAction()` that
   `ActorRelationRequest` and `StreamCardsRequest` already use. This is
   correctness rather than speed: today a partial save leaves a post that exists
   and is in nobody's timeline, invisibly.
6. **`FollowService::getFollowers()`** needs paging before an instance has an
   account with tens of thousands of followers, not after.
7. The schema items, next time a migration touches those tables. The
   `social_follow` index order is the one worth doing deliberately: it is the
   reason a duplicate accepted/pending pair can exist at all.
