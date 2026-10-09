<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration tests (run against a real Nextcloud + database)

Unlike the unit suite (`tests/`), which stubs OCP and needs no server, these
tests resolve real services from the container and hit the actual database, so
they exercise the migrations, the query SQL, the unique constraints and the
storage boundaries (key encryption, credential hashing) that mocks cannot reach.
On their first run they caught two shipped bugs the green unit suite missed —
an OAuth secret column too short for its hashed value on MySQL, and an UPDATE
built without a SET clause — which is exactly the class of regression they exist
to stop.

- `Db/TimelineSeedTest` — seeds actors, follows and notes through the production
  Request classes and asserts what each timeline returns: home via the follow
  join, direct-message isolation, favourites/bookmarks via the action join,
  hashtags, account visibility and id-based pagination.
- `Db/ActorsKeyStorageTest` — private keys land encrypted, read back decrypted,
  legacy plaintext rows stay readable, the EncryptPrivateKeys repair converts
  them idempotently.
- `Db/TokenLifecycleTest` — an OAuth token through `ClientService`: a code
  exchanged once and refused after ten minutes, a revoked token that names
  nobody while another account's authorization of the same app survives, an
  app or account unable to revoke what is not theirs, and a `read` token
  refused by a write route before its service is reached.
- `Db/ClientCredentialStorageTest` — OAuth secrets/codes/tokens land hashed,
  plaintext tokens resolve through the hashed lookup, re-authorization
  invalidates the old token, revocation works, a legacy plaintext row is
  refused and the hashing migration converts it.
- `Db/StreamActionsFlagsTest` — the per-viewer flags are written field-wise and
  idempotently.
- `Db/ReplyCountTest` — the reply counter on a post: a recount counts what is
  stored, counts one fewer after a delete, and carries the replies that live on
  the post's own server across untouched.
- `Db/RequestQueueLifecycleTest` — standby → running → deleted on success,
  failures counted and retried, abandoned after MAX_TRIES, stale RUNNING rows
  reaped back to standby.
- `Db/OnlyMediaTimelineTest` — the predicate behind the Photos view: posts with
  attachments are kept and all three spellings of "no media" (NULL, `''`, `'[]'`)
  are dropped, and a post carrying several pictures is still one post.
- `Db/OnlyMediaTimelineTest`, `Db/OnlyVideoTimelineTest`,
  `Db/MediaTypeTimelineTest` also seed media at a realistic ratio
  (`Db/MediaUnderText`: three pictures and two videos under sixty newer posts
  of text) and page the Photos and Videos views of home, public and a profile
  — a page of twenty, then two at a time on `max_id` — with the home fast path
  on and off: a narrowing applied after the page was chosen finds nothing.
- `Db/FollowedTagsTest` — the followed-hashtag table and the second half of the
  home timeline: the unique index that makes following twice a no-op, paging on
  the row id, a 127-character multi-byte tag, and the join itself — a public
  post carrying a followed tag reaches home whatever case its author typed the
  tag in, a followers-only one does not, a post carrying two followed tags
  is listed once, and a post read from Bluesky is in it like any other unless
  its author is muted — and which tags the background read of followed tags
  picks: each once, never read first, then read longest ago.
- `Db/HomeMentionTest` — a post that names the reader is on their home timeline
  whether the fast page query or the old join answers it: the Loopback row is
  what carries a mention from an account they do not follow, and a set of
  recipient rows built without it drops every one of them.
- `Db/DurableCacheTableTest` — the table behind `DurableCache` on an instance
  with no memcache: a read ignores an expired row, an insert over an existing
  key is skipped rather than raised, the purge takes only what has expired.
- `Db/ActorRelationRequestTest` / `Db/StreamFilterTest` — block/mute storage and
  the hidden-actor anti-join on every timeline.
- `Db/ListsRequestTest`, `Db/TrendsRequestTest`, `Db/DiscoveryRequestTest`,
  `Db/ConversationsRequestTest`, `Db/StreamInterestsTest`,
  `Db/CacheDocumentsRequestTest` and one `Db/*RequestTest` for each smaller
  table (reactions, interests, featured tags, portfolios, media tags, stories,
  places, post holds, quote grants, revisions, imports, emoji and GIFs,
  channels and teams, access blocks, account notes, mutes, stream views,
  watches, trend review, Discover categories) — the request classes whose SQL
  no test had run. `Db/InstanceStatsRequestTest` caught the weekly activity
  counting zero statuses: its window was bound as integers against a DATETIME
  column.
- `Db/ActorCascadeTest` — deleting a Nextcloud user (`IUser::delete()`, so the
  listener and the whole cascade run) leaves no row in any table that names
  their account, and another user's rows untouched. A schema guard fails when
  a table with an account-like column is neither cascaded nor listed as kept
  or as a known gap.
- `Db/StreamMediaTest` — a post's stored attachment copies and the
  `social_stream_media` links: paging by nid, `carriesUpload()` (which failed
  on every database until this test ran it), relinking on a rewrite, remote
  recovery, and rebuilding a document's copy on the posts that carry it.
- `Db/StreamDeletionTest` — `deleteRelatedTo()` empties every table that keys
  on a post and nothing of another post, `deleteByPrims()`, a domain's authors
  for the instance purge, and the notification sweep's selection.
- `Db/StreamThreadsTest` — the public `replies` collection and its
  `totalItems` agree and leave out every reply that is not public; the
  viewer-filtered reply list.
- `Db/TimelineValidatorsTest` — the newest-nid probes a timeline's entity tag
  is built from, and the unread notification badge.
- `Db/SearchTermsRequestTest` / `Db/StreamTagsRequestTest` — the word index
  (index, edit, whole words and prefixes, backfill) and the hashtag rows.
- `Db/StreamStatisticsTest` — the grouped queries behind the statistics page
  and the activity chart: public posts only, conversation partners both ways,
  boosters once each.
- `Db/SocialCrossQueryBuilderTest` — the joins on their own; above all that a
  boost hands the viewer the post it repeats only where they may read it.
- `Migration/RowBackfillMigrationsTest` — the upgrade steps that fill rows
  (recipient nids, `media_kind`, `news_kind`, OAuth authorizations moved to
  `social_client_auth`) run on rows in their pre-upgrade shape, twice.
- `Helper/RowSeeder` — writes a row into any of the app's tables from the
  installed schema, filling what a test does not name.
- `QueryCounter` / `QueryCounterTest` — records every statement the server's
  connection executes during a block (through Doctrine's SQL logger, swapped in
  for the block and put back after), with its parameters; the test proves it
  counts what the server's own counter counts.
- `Api/QueryBudgetTest` — query budgets per page of the client API, driven
  through the controllers as a signed-in reader (`Api/ReaderTestCase`): home (fast
  and join path), public, notifications, an account's statuses, a status and
  its thread, search, and a byte range of a media file. A page of twenty must
  cost what a page of five costs (no query per post) and stay within today's
  count plus two.
- `Api/LimitClampTest` — every `GET` route taking `int $limit`, found by
  reflection, asked for 500 (the most Nextcloud's dispatcher lets through),
  100000 and -1: each `LIMIT` that reaches the SQL must be one a request at
  the route's documented maximum, at 1 or at its default also produced. A
  route whose page is not read by a query is listed with what bounds it.
- `Db/QueryPlanTest` — the plan of each hot query, recorded from the production
  method and handed to `EXPLAIN` verbatim: home (fast path on `social_sd_atn`,
  join path), Photos and Videos on home, public and a profile, an account's
  statuses, notifications, the delivery queue's standby read, follower
  inboxes and the handle lookup. SQLite: every guarded table is searched
  through an index, never scanned. MySQL: a key and no full read, no filesort
  or temporary table unless the query is listed as one that sorts.
  PostgreSQL: no `Seq Scan` on a guarded table. MySQL and PostgreSQL are
  seeded to ~2,000 posts and `ANALYZE`d first.
- `Db/RepairWalkTest` — the repairs `occ social:check:install` runs, over 1,200
  notes and 600 follows: orphans removed, the rest kept, statements bounded by
  two a page plus the deletes, and the peak memory under 4 MB.
- `Cron/QueueWorkBudgetTest` — one pass of the delivery cron over a real
  queue of 0, 200 and 400 deliveries, with only the network edge replaced and
  the clock frozen: a constant cost per pass, two statements per delivery and
  three per batch, and the second batch costing exactly what the first did.
- `Api/*` — the Mastodon client API called the way a client calls it: real
  accounts with a bearer token, each controller built by a fresh app
  container, the answer put through `json_encode()` as the JSON responder
  does (`ApiTestCase`). `Api/StatusMentionsTest` caught every status written
  here reaching clients with `mentions: []`; `Api/StoredSourceTest` caught an edit or a vote
  storing the client entity where the post's ActivityPub document belongs.
- `Command/*` — the occ commands, driven through Symfony's `CommandTester` with
  each command built by the real container, so a constructor these tests cannot
  satisfy is one occ cannot satisfy either. `ResetTest` and `CheckInstallTest`
  cover the destructive paths without taking them: both refusal paths (a
  declined prompt, and `--no-interaction` without `--force`, which used to exit
  0 having done nothing) and the `--uri` option the app's own advice names.
  `QueueRetryTest` and `QueueStatusTest` drive the delivery queue through a
  failure and back out again; `StreamPruneTest` covers the retention guard and
  `--dry-run`.
- `Federation/InboundCorpusTest` — the inbound fixture corpus (Misskey,
  GoToSocial, Akkoma, Friendica, Lemmy, Mastodon 4.5 quotes and polls, Block,
  Flag, account Delete, PeerTube Dislike/View/CacheFile, Threads, WordPress,
  Ghost, Bridgy Fed) delivered through `ImportService` and the real inbox
  interfaces, asserting the stored row and the Mastodon entity it is served
  as; it caught Mastodon's inlined `QuoteRequest.instrument` being dropped.
  `Federation/InboxTestCase` is the shared delivery harness.
- `Federation/RedeliveryOrderTest` — the same activity delivered twice leaves
  the rows of the first delivery and a usable connection; an Update before
  its Create, two edits in reverse order (caught an older edit overwriting a
  newer one), a Delete before its Create (caught the late Create resurrecting
  the post), an Undo{Like} before the Like, and a rotated key arriving in an
  Update{Person} verifying the next signature.

## Running

**CI** runs the suite in every `phpunit-*` job (SQLite, MySQL, PostgreSQL across
the supported server versions) via `composer run test:integration`; the workflows
pick the script up automatically now that it is defined.

**Locally**, point the bootstrap at an installed server (the app directory may be
a symlink into it):

    NEXTCLOUD_ROOT=/var/www/nextcloud vendor/bin/phpunit -c tests/Integration/phpunit.xml

All rows the tests create carry unique test-only ids and are removed in
tearDown, so the suite is safe to run against an instance that holds other data.
