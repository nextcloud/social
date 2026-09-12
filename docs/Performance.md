# Performance and scalability

A survey of the query and scalability behaviour of this app: what costs what,
what is bounded, and what is still known to be wrong. Every claim below was
checked against the code rather than carried over from a previous survey.

**Verified against:** app version 0.15.1, 2026-09-12.

This file is **not** enforced by `tests/DocumentationTest.php` — its claims are
about behaviour rather than about routes or schema rows, and a test that tried
to check them would either be brittle or would be the fix. It therefore goes
stale silently. Re-verify before trusting a line of it, and update it in the
same change as any work on a read path.

## What is still open

### Unbounded work

| Where | What happens |
|-------|--------------|
| `CacheDocumentsRequest::getNotCachedDocuments()` | No `setMaxResults`. `DocumentService::manageCacheDocuments()` loops the whole set with one outbound HTTP fetch per row, so a backlog of uncached avatars is attempted in a single cron slot. Its two siblings are capped — `CacheActorsRequest::getRemoteActorsToUpdateDetails()` at `SYNC_BATCH`, `StreamQueueRequest::getStandby()` at `STANDBY_BATCH` — and this one was missed. |
| `FollowsRequest::getFollowersByActorId()` | Takes `$limit`, but the delivery fan-out (`FollowService::getFollowers()`, and `MigrationService`) calls it with no limit. A popular local actor's every post loads its whole follower set into PHP memory before the queue sees it. |
| `PersonInterface::deleteStreamFromActor()` | An incoming `Delete` for a remote actor walks `getRelatedToActor()` — itself unbounded — and issues a `getStream()` and possibly an `update()` per row, inline in the HTTP request the peer is waiting on. |
| `HashtagsRequest::getAll()` | Takes an optional limit; whoever calls it without one reads the whole table. |

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
column — it selects nids, then hydrates. Five read paths use it: two branches of
`getTimeline()` (home and public), the marked timelines (favourites and
bookmarks), the list timeline in `ListsRequest`, and the deprecated direct
timeline. Sixteen call sites still go through `getStreamSelectSql()`, which pairs `selectDistinct('s.id')` with the
full stream column set plus, on some paths, a second `os_*` stream set and two
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
  accepted and one pending row for the same pair can coexist.
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

## Rough order of value

1. Cap `getNotCachedDocuments()` — one line, and it is the last unbounded cron
   loop.
2. Bound the follower fan-out, which is the one unbounded read on the posting
   path.
3. Move the remaining sixteen read paths onto `getStreamNidsSelectSql()`.
4. Wrap `StreamRequest::save()` in a transaction, and give
   `StreamActionService::saveAction()` the insert-then-catch shape its
   neighbours already use.
5. The schema items, next time a migration touches those tables.
