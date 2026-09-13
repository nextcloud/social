# Performance and scalability

A survey of the query and scalability behaviour of this app: what costs what,
what is bounded, and what is still known to be wrong. Every claim below was
checked against the code rather than carried over from a previous survey.

**Verified against:** app version 0.19.12, `master`, 2026-09-13 — re-measured
end to end on a seeded instance (22,642 posts, 65,014 recipient rows, 916
follows, 435 cached actors) after the read-path wave below.

This file is **not** enforced by `tests/DocumentationTest.php` — its claims are
about behaviour rather than about routes or schema rows, and a test that tried
to check them would either be brittle or would be the fix. It therefore goes
stale silently. Re-verify before trusting a line of it, and update it in the
same change as any work on a read path.

## What a page costs

Measured with `occ social:benchmark --time-only`, which times
`StreamRequest::getTimeline()` itself — the query builders and the hydration,
not just the SQL. Two datasets: a demo instance of 650 posts, and the same
instance with `--actors=400 --notes=20000 --follows=300` seeded on top.

| timeline | 22k posts, before | 22k posts, now | 650 posts, now |
|---|---|---|---|
| home (My Feed) | 114.7 ms | **49.6 ms** | 16.6 ms |
| public (Local/Global) | 52.6 ms | **3.6 ms** | 4.6 ms |
| notifications | 29.6 ms | **7.4 ms** | 7.0 ms |
| direct | 1.3 ms | 1.3 ms | 1.2 ms |

Three changes did that, and all three are about what a *page-selection* query
asks for rather than about indexes:

1. **The page queries stopped projecting columns nobody reads.**
   `linkToCacheActors()` appends twenty select aliases — `source`, `details`,
   `summary`, `public_key` among them — plus eleven for the cached document,
   and the home timeline's `SELECT DISTINCT` had to deduplicate over all of
   them. `joinCacheActors()` is the same join without the select list; the
   home query went from 94.5 ms to 56.2 ms on nothing else.
2. **`SELECT DISTINCT` is asked for only where a page can actually
   duplicate.** `social_stream_dest` is unique on `(stream_id, actor_id,
   type)`, so a query that fixes the actor *and* the type — public,
   notifications, direct, the marked timelines — cannot match a post twice.
   Those now select without it: the public page query went from **37.4 ms to
   0.21 ms**, for the same twenty rows, with no duplicates among them. The
   home timeline keeps it, because a post addressed to three accounts you
   follow really does match three rows. `getNidsFromRequest()` deduplicates the
   twenty integers in PHP as well, which covers the one case SQL uniqueness
   does not: the left join on expired mutes can match twice for a viewer who
   timed-muted both a booster and the account they boosted.
3. **Notifications moved onto the two-query pattern** the home and public
   timelines already used — choose a page of ids, then hydrate them. It was
   carrying two full stream column sets, two cached-actor sets and two
   cached-document sets through a `DISTINCT` to pick twenty rows, and every
   client polls it on a timer.

And one that is not about reads at all: **a viewer's domain blocks are no
longer joined per row.** The filter was a `LEFT JOIN` whose `ON` clause held
four `LIKE`s against `LOWER(attributed_to)` — an unindexed text column —
evaluated for every candidate row of every timeline, for every account,
including the overwhelming majority who have blocked nothing. The list is read
once per request and compared as constants; an account that has blocked nothing
now adds no clause at all. Measured at 56.2 ms → 41.5 ms on the home timeline
of an instance with a single block row.

## What is still open

### Unbounded work

Nothing is unbounded any more. What is left is bounded work that
could still be cheaper, and the schema items at the end.

| Where | What it costs now |
|-------|-------------------|
| `StreamRequest::getDescendants()` | A thread is walked a level at a time, each level one query, bounded by depth and by page size. Fine for a conversation; a thread thousands deep is still thousands of levels. |
| `CacheActorService::getFromId()` | An uncached actor is fetched over HTTP inside the request that asked for it. Bounded by the access list and a timeout, but it is network in a read path. |
| `StreamPruneService` | The retention pass is `NOT EXISTS` over the largest table, which is a sequential scan by design. It runs from cron and from `occ`, never from a request. |

### Transactions

`StreamRequest::save()` is now one transaction: the post, its recipient rows
and its tags go in together or not at all, and `StreamDestRequest::create()`
raises instead of logging a failure and carrying on. It was the one place where
a partial write was both permanent and silent — the recipient rows are what put
a post in a timeline, so the post existed and was in nobody's.

Inside a transaction, a *tolerated* failure is not free either. Recipients and
hashtags repeat in ordinary posts — `getToAll()` returns `to` alongside
`toArray`, the unique index on the recipient rows does not include the subtype
so the same actor in `to` and `cc` collides too — and both inserts used to
catch the violation and carry on. PostgreSQL aborts the whole transaction on
any refused statement, so that caught violation took the commit with it and the
post was lost. Both now use `insertIgnoreConflict()`: the database skips the
duplicate row, nothing fails, and the raise is left to mean what it says.

Two places still have no transaction and want one:

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
including `content`, `source`, `details`, `cache`, `tags` and `to_array` — to
deduplicate. Direct messages, account timelines, hashtag timelines,
notifications, search, `getNoteSince` and `getDescendants` are the ones worth
moving.

### Lookups that cannot use an index

`DomainBlocksRequestBuilder::filterDomainBlocked()` is no longer one of them:
the four `LIKE`s it built are now compared against constants read once per
request, and skipped entirely for an account that has blocked nothing. They are
still `LIKE`s on an unindexed column for an account that has blocked something,
bounded at `CoreRequestBuilder::BLOCKED_DOMAINS_IN_A_QUERY`; storing the
author's host in its own indexed column would make them equalities, and would
serve the silenced-instance filter next door as well.


The `*_prim` columns (md5 of the lower-cased id) exist so a lookup can be an
indexed equality. The hot paths now use them, but `ActorsRequest`'s
`preferred_username` lookups still compare `LOWER(column)` against
`LOWER(?)` on an unindexed column — the public ActivityPub actor endpoint and
webfinger both land there.

### Schema shape

- ~~`social_follow`'s unique indexes lead with the `accepted` boolean~~ —
  fixed by `Version1000Date20260913000001`, which drops both, adds
  `social_f_oa_u` unique on `(object_id_prim, actor_id_prim)` and
  `social_f_foa` on the three id columns, and removes the duplicate rows that
  the old shape allowed before adding them. That migration also drops
  `social_stream_dest.ts (type, subtype)`: three values against three across
  the second-largest table, never the best index for any query the app makes,
  and pure write cost — 27.7 MB of index over 8 MB of data on a 65,000-row
  table.
- Several indexes from the 2022 migration are unnamed, so Doctrine names them
  per install and a later `hasIndex()`/`dropIndex()` cannot refer to them. Index
  names from that era are also not app-prefixed (`sa`, `ts`, `aoa`, …) and live
  in PostgreSQL's schema-global namespace, where another app can collide with
  them. Migrations from 2026 use `social_*`.
- Every `NOT NULL` column these migrations add carries an explicit default,
  including `social_cache_doc.account`. PostgreSQL refuses a `NOT NULL` column
  on a table that already has rows unless there is one ("column contains null
  values") where MySQL quietly invents the empty string, so the default is what
  makes such a column safe to add at all. `InitialSchemaTest` holds the four
  columns on that table to it.
- `social_stream_act.bookmarked` is `SMALLINT` while its three sibling flags are
  `BOOLEAN`.
- `social_stream` carries nine JSON-in-TEXT columns — `to_array`, `cc`, `bcc`,
  `hashtags`, `tags`, `details`, `instances`, `attachments` and `cache` — plus
  `source`, the whole ActivityPub wire object. None of them can be filtered,
  indexed or sorted on by any database this app supports, so anything that has
  to be queried needs a column or a side table of its own, and every insert
  writes both copies: `hashtags` duplicates `social_stream_tag`, and
  `to_array`/`cc`/`bcc` duplicate `social_stream_dest`. That is the cost of the
  arrangement, and it is paid knowingly — the side tables exist *because* the
  JSON is unqueryable.
- Five post fields used to be readable only by `json_decode`ing `source` once
  per timeline row: `tag`, `language`, `updated`, `quote` and
  `quoteAuthorization`. `Version1000Date20260912000007` gave each a column, so
  `language` is now an indexed equality (`social_s_lang`) and the other four are
  read without parsing. `tags` stayed JSON, because the one facet of the `tag`
  array worth querying — the hashtags — is already `social_stream_tag`, and a
  second side table would be a second source of truth for it. `quote` and
  `quote_authorization` have no `_prim` companions: no query filters on either,
  and an md5 column plus index on the largest table in the app costs every
  insert for a read nobody makes. Rows written before that step still fall back
  to `source` until `BackfillStreamPostFields` reaches them.
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
| `getNotCachedDocuments()` read every uncached document and fetched each one over HTTP in a single cron slot | Capped at `CACHE_BATCH`; the rest is the next run's, which is what its two siblings already did |
| `deleteStreamFromActor()` walked every post that ever addressed an account, inline in the inbox request a peer was waiting on — so the peer timed out, re-sent the `Delete`, and the walk started again | Paged by keyset (`DETACH_PAGE`), bounded per request (`DETACH_INLINE`), and finished by `Cron\ActorCleanup`, which re-queues itself while rows remain |
| A page of relationships cost six queries per account — two follow rows, the blocks and mutes, the note, the mute's expiry | Five queries for any number of accounts (`getBetweenMany()`, `getNotes()`, `getExpiries()`), and the single-account route goes down the same path so the two cannot disagree |
| `HashtagService::manageHashtags()` read every hashtag the instance had ever seen on every cron run | `getWithAnyTrend()` reads only the rows that claim a trend — the only ones it can change |
| `FollowService::getFollowers()` hydrated every follower for a route with no cursor | Bounded at `FOLLOWERS_PAGE`; the paging route is `/api/v1/accounts/{account}/followers` |
| `StreamRequest::save()` wrote the post, then its recipients, then its tags, outside any transaction, and the recipient insert swallowed its failure | One transaction, and `StreamDestRequest::create()` raises. A post that cannot have recipients is not stored at all, so the delivery can be retried into a clean state. The duplicate recipient and hashtag rows an ordinary post produces are skipped by the database rather than caught, which a transaction on PostgreSQL does not survive |

## The home timeline, and one thing that did not work

Home is now the slowest page and the reason is structural: it asks for "the
newest posts addressed to anyone I follow", where the selection lives in
`social_stream_dest` and the order in `social_stream`. The database drives from
the follow rows, walks each followed account's recipient rows, joins the post to
every one of them and sorts the result — at 22,642 posts and 325 follows that
join produced **16,111 rows to return 20**, with `Using temporary; Using
filesort` over all of them.

The obvious fix looked like copying the post's `nid` onto its recipient row, so
that `(actor_id, type, nid)` could select *and* order a page from one index. On
a stripped query that measured 25.9 ms → 2.95 ms. **It does not work on the real
one**, and the plan says why: with the filters attached — the hidden-actor
anti-joins, the mute expiry, the author join — MariaDB still drives from the
follows and still sorts, so the sort key on the recipient row changes nothing it
can use. The column, its index, the write and a backfill repair step were
written, measured at **no improvement**, and removed again rather than shipped.
Anyone reaching for that idea again should measure the *whole* query first.

What is left, in order of how much it would cost to try:

- **A semi-join.** `WHERE EXISTS (SELECT 1 FROM social_stream_dest sd JOIN
  social_follow f …)` emits each post once, which removes the deduplication and
  lets the database walk `social_stream` backwards by primary key and stop after
  twenty — exactly what made the public timeline 178× faster. The trade is the
  opposite one: it is fast when the people you follow post often and slow when
  they do not, because then it walks a long way back for twenty rows. Worth
  measuring on both shapes of instance before choosing.
- **Fan-out on write.** One row per (viewer, post) in a table indexed
  `(viewer, nid)` makes the home timeline a single index range. It is what the
  large implementations do, and it costs a write per follower on every post.

## What to do next

1. **Move the remaining read paths onto `getStreamNidsSelectSql()`.** The one
   item left that changes how the app scales. Every timeline that is not home or
   public still makes the database sort or hash `content`, `source`, `details`,
   `cache` and `to_array` to deduplicate a page of twenty rows. It is also the
   most mechanical: the pattern exists, it is proven on seven paths, and each
   move is independently testable. Do notifications first — it carries two full
   stream column sets, two cached-actor sets and two cached-document sets, and
   every client polls it on a timer.
2. **`StreamActionService::saveAction()`** wants the
   insert-then-catch-unique-then-update shape its two neighbours already use.
   Correctness rather than speed: two concurrent likes can both insert today.
3. **`ModerationRequest::save()`** wants a transaction around its delete and
   insert, for the same reason.
4. **The schema items**, next time a migration touches those tables. The
   `social_follow` index order is the one worth doing deliberately: it is why a
   duplicate accepted/pending pair can exist at all.
5. **An index on `social_actor.preferred_username`**, or a `*_prim` column for
   it, if the public actor endpoint and webfinger ever show up in a profile.
   Both still compare `LOWER(column)` against `LOWER(?)`.
