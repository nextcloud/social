# My Interests — feature specification

Status: specified 2026-09-24, not implemented · App: Social (baseline 0.27.0)

## 1. Summary

Social learns which hashtags a user cares about from how they read. It looks at
how long they linger on a post, what they skip, and what they like, boost,
reply to, bookmark, open or play. From that it keeps a private, ranked list of
**interest hashtags**. A new **My interests** feed shows posts carrying those
hashtags, ranked by relevance blended with recency. In Settings the user can
see the list and add, remove, reorder and pin its entries.

## 2. Goals and non-goals

Goals
- A feed that surfaces interesting posts from everything the server knows, not
  only from followed accounts.
- Every post in the feed can say why it is there (a matching hashtag).
- The user can see and control the model completely: every learned interest is
  visible, editable and resettable.
- Learning never leaves the server and is never visible to anyone else.

Non-goals (v1)
- Topics inferred from untagged text. The feed contains hashtagged posts only.
- Fetching posts from peer servers to fill the feed. v1 uses the local cache.
- Learning from accounts or links. Only hashtags are learned.
- Hiding posts already seen in other feeds.

## 3. Decisions from the interview

| Topic | Decision |
|---|---|
| Where signals are collected | Home, Local, Global, hashtag pages, Explore/Discover, post detail/thread |
| Signals | Dwell time, explicit actions, negative actions, media interaction |
| Storage | Server-side, private per user, aggregated scores only (no raw event log) |
| Consent | Opt-out per user; the default (on/off) is an admin setting, on out of the box |
| "Looked at extra long" | Relative to the user's own baseline, adjusted for post length and media |
| Change over time | Exponential decay (half-life, default 30 days) |
| Listed interest | Score above a threshold, capped at top N (default 30); manual tags always listed |
| Multi-tag posts | A post's signal is split evenly across its hashtags |
| Candidate pool | Every post the viewer may see in the local cache |
| Ordering | Relevance × recency |
| Untagged posts | Excluded |
| Feed extras | "Why am I seeing this" chip, diversity/exploration, inline "less like this" |
| Settings display | A tag cloud: size and emphasis show priority, flowing in rank order |
| Reprioritize | Drag within the cloud, or Higher/Lower in a per-tag popover; pin |
| Remove | Resets the score to zero; the tag can be learned again |
| Followed hashtags | Kept as a separate list; followed tags seed interests |
| Settings controls | Pause learning, reset all, include in data export |
| Cold start | Followed tags, featured tags and local trending, with a "still learning" hint |
| Placement | Timeline switcher option; Mastodon API for apps |
| Safety | Mutes, blocks, domain blocks and filters respected; language filter |
| Admin | Enable/disable the feature; default for users; tuning parameters |

## 4. User experience

### 4.1 Feed
- A fourth option, **My interests**, in the timeline switcher next to My Feed /
  Local / Global ([src/views/Timeline.vue](../src/views/Timeline.vue), the
  `home|timeline|federated` options). Route `/interests`, which needs a
  `NavigationController` route as well as the client route.
- Each post shows a small chip: "Because you follow #photography" (for a
  followed tag) or "Because you're interested in #photography" (learned or
  manual). With two or more matches: "#photography, #analog +1". Tapping the
  chip opens the hashtag timeline.
- The post menu gains **Less like this** (see 5.2). In this feed, using it
  also hides the post at once and shows a toast with an Undo.
- While learning is thin (see 6.5), a dismissible banner at the top says:
  "Still learning what you like. Showing posts from hashtags you follow and
  what's trending here. [Manage interests]".
- Empty state (no interests, nothing trending): explains how the feed learns
  and links to Settings → Interests.

### 4.2 First-use notice
Tracking is on by default, so users must be told about it. The first time the
web UI records a signal for a user, it shows a one-time notice once per
account. The acknowledgement is stored server-side:

> Social now learns which hashtags interest you from how you read, to build
> your My interests feed. This stays on this server and is only visible to
> you. [Manage] [Turn off] [Got it]

### 4.3 Settings → Interests
A new section in [src/views/Settings.vue](../src/views/Settings.vue) with id
`interests`, placed after the followed-hashtags section.

- **Learn from my browsing** (toggle, default on). Off = opt-out: no signals
  are collected, the feed tab is hidden, the list is kept but frozen.
- **Pause learning** (toggle). The feed keeps working on the current list, but
  no new signals are recorded and decay stops. It is for "I'm browsing
  something unusual today". It is disabled while learning is off.
- **Interest cloud**: the interests are shown as a tag cloud, the main visual
  element of the section (see 4.4).
- **Add interest**: a hashtag input with autocomplete from known hashtags, at
  the end of the cloud (an inline "+ Add" pill that turns into the input). The
  tag is added as a manual interest at the bottom of the pinned block and
  animates into its place in the cloud.
- **Languages for My interests**: multi-select, empty = all languages. This is
  a new preference; Social has no language preference yet.
- **Reset all interests**: clears learned scores, manual entries, pins and the
  dwell baseline. Needs a confirmation step built into the page (the viewer has
  no `confirm()`).

Removing a learned tag resets its score to 0 (it may be learned again). The
row disappears and a toast offers Undo for 10 s. Removing a followed tag's
interest only removes the interest; the follow stays. Its seed (6.4) keeps it
listed while it is followed, so the settings page says "Unfollow #x to remove
it from interests" for followed tags.

### 4.4 The interest cloud
The cloud shows priority at a glance and lets the user change it in place.

**Layout.** Tags flow left to right and wrap, in effective rank order (6.5),
so the reading order is the priority order and the top interest comes first.
The layout is deliberately not a random scatter: an ordered flow keeps drag
targets predictable, works with a keyboard and a screen reader, and reflows
cleanly at phone width.

**Encoding.**
- *Size* follows the rank weight `w(rank)` (6.6): six steps from 2.0 rem for
  the top entries down to 0.85 rem for the tail, so the size change between
  neighbouring ranks is gradual rather than jumpy.
- *Weight and colour intensity* follow the same step: the top tags are bold
  on the primary colour, the tail is regular on a muted tint. Both themes are
  covered with Nextcloud colour variables, never fixed colours.
- *Source* is a small leading glyph rather than a badge, to keep the cloud
  calm: a pin for pinned, a hand/pencil for added by you, a bell for followed,
  no glyph for learned.
- *Trend* (learned tags only): a subtle up/down arrow when the score moved by
  more than 20 % over the last 7 days. This needs a `score_week` column (the
  decayed score copied over by the weekly background job) in
  `social_interest`. It is optional: drop both if the cloud gets noisy.
- Tags with a negative score are not shown in the cloud.
- Hover/focus shows a tooltip: "Rank 4 · learned · score 12.3".

**Changing priority.**
- *Drag* a tag onto a new position in the flow. The other tags make room with
  a short animation, and the dropped tag resizes to its new rank. Dropping an
  unpinned learned tag pins it (6.5), shown by the pin glyph appearing.
- *Tag popover*: clicking a tag opens a small popover with
  - **Higher priority** / **Lower priority** (moves one rank, pins it),
  - **Move to top**,
  - **Pin / Unpin**,
  - **Open #tag** (the hashtag timeline),
  - **Remove**.
  This is how touch and keyboard users reprioritize without dragging.
- Keyboard: the cloud is one roving-tabindex group. Arrow keys move focus,
  Enter opens the popover, and Alt+←/→ moves the focused tag one rank.
  Every move is announced through `aria-live` ("#photography, now priority
  3 of 24").

**Removing.** A small × appears on hover/focus (always visible on touch) and
in the popover. The tag fades out, the cloud reflows, and a toast offers Undo
for 10 s.

**States.** Empty: a faint placeholder cloud with the text "Your interests
appear here as you read" and the Add pill. Thin learning (6.5): the cloud
shows what exists plus a "Still learning" note. The temporary trending seeds
are not shown. Learning off: the cloud is shown dimmed, still editable, with
a note that it is frozen.

**Size cap.** The cloud shows the listed entries (at most `N`, default 30).
Below the cloud, a "Show more candidates" disclosure lists learned tags that
are just under the threshold as small pills. Tapping one adds it as a manual
interest, which is a quick way to promote a tag.

**Motion.** Reordering and resizing animate with FLIP transitions of about
200 ms, which are turned off under `prefers-reduced-motion`.

Implementation: `src/components/InterestCloud.vue` (cloud + popover) inside
`InterestsSettings.vue`. It uses the existing `NcPopover`/`NcActions` and no new
drag library unless the one already in the bundle is insufficient. Check this
against the dependency list before adding one.

## 5. Signal collection

### 5.1 Dwell (web UI only)
Tracked in `TimelineList.vue`, which already runs an `IntersectionObserver`
([TimelineList.vue:931](../src/components/TimelineList.vue#L931)), plus
`TimelineSinglePost.vue` for the detail view. Collection lives in one
`src/services/interestTracker.js` module so every view uses the same rules.

- A post is **in view** while ≥ 50 % of it is visible, `document.visibilityState`
  is `visible` and the window has focus.
- Dwell pauses after 20 s without scroll, pointer or key input (reading
  stopped) and resumes on input. One view's dwell is capped at 30 s.
- A post collapsed behind a content warning counts only after it is expanded.
- A post counts as **skipped** when it passed fully through the viewport with
  < 0.3× the expected dwell (see 6.1) while the user was scrolling.
- Nothing is recorded for the user's own posts, for posts without hashtags, or
  for posts shown only as a filter warning.
- The tracker buffers events and flushes them every 30 s, on
  `visibilitychange → hidden` and on `pagehide` (via `navigator.sendBeacon`).
  At most 100 events per request.

Event sent to the server (the client never sends hashtags; the server reads
them from the stored post, so a client cannot inject tags):

```json
{ "status_id": "113…", "kind": "dwell", "ms": 5400, "context": "home" }
{ "status_id": "113…", "kind": "skip", "context": "federated" }
{ "status_id": "113…", "kind": "media", "context": "tag" }
```

`kind` ∈ `dwell | skip | open | media | link`. `context` ∈ `home | local |
federated | tag | explore | detail | interests`.

### 5.2 Explicit and negative actions (server-side)
These are recorded on the server where the action happens, so they also count
when the action comes from a Mastodon app:

| Action | Where it is recorded | Signal |
|---|---|---|
| Favourite | `LikeService` | +2 |
| Boost | `BoostService` | +3 |
| Reply | status creation with `inReplyTo` | +3 |
| Bookmark | bookmark endpoint | +3 |
| Open detail/thread | web `open` event | +1.5 |
| Expand media / play video / open link | web `media`, `link` events | +1 |
| Long dwell / normal dwell / skip | web `dwell`, `skip` events | +1 / +0.3 / −0.2 (see 6.1) |
| Less like this | new endpoint | −3 |
| Mute author (from a post) | `MuteDialog` → mute endpoint with the post id | −1 |

Undoing an action (unfavourite, unboost, unbookmark) does not subtract. It is
not worth the bookkeeping, and decay covers it.

Dedupe: each `(user, post, kind)` counts at most once per 24 h, so rereading
a post does not inflate its tags. This uses a short-lived cache key
(`ICache`, TTL 24 h) instead of a table.

Nothing is recorded while learning is off or paused, or while the feature is
disabled by the admin.

## 6. Scoring

All scoring lives in one pure class, `InterestScorer`, with no database access,
so it can be unit-tested exhaustively.

### 6.1 Dwell normalisation
Expected dwell for a post:

```
expected_ms = 1200 + 35 × visible_text_chars + 1500 × media_count
```

(capped at 20 000). The ratio `r = dwell_ms / expected_ms` is compared with the
user's own baseline `b`, an exponential moving average of `r` over their dwell
events (α = 0.05, starting at 1.0, stored per user):

- `r ≥ 2b` → long dwell, +1
- `b ≤ r < 2b` → normal dwell, +0.3
- `r < b` and not a skip → 0
- skip → −0.2

The baseline is updated only by `dwell` events, never by skips.

### 6.2 Distribution across hashtags
A post with `n` hashtags (from `social_stream_tag`, normalised with
`FollowedTagsRequest::normalise`) gives each tag `signal / n`. Posts with more
than 15 hashtags produce no signal at all, because they are tag spam.

### 6.3 Decay
Scores decay exponentially with half-life `H` (admin, default 30 days). Decay
is applied lazily: a row stores `score` and `scored_at`, and

```
score_now = score × 2^(−(now − scored_at) / H)
```

is computed on read and when a new signal is folded in (the result is written
back with `scored_at = now`). No background job is needed. While learning is
paused, `scored_at` is shifted forward on resume by the paused duration, so a
pause does not decay the list.

Scores are clamped to [−10, 50]. Negative scores are kept: they push a tag's
posts down in the feed and stop weak positive signals from listing it again
right away. Pinned and manual entries do not decay.

### 6.4 Followed hashtags as seeds
While a tag is followed, its effective score is `max(score, T)`, where `T` is
the listing threshold. It is always listed, but real reading still moves it
above other entries. Unfollowing removes the floor; the learned score stays.

### 6.5 The listed interests
Effective list, in order:
1. **Pinned** entries (learned or manual), in the user's drag order.
2. **Manual** unpinned entries, in the user's drag order.
3. **Learned and followed** entries with effective score ≥ `T` (admin, default
   3.0), by score, until the list holds `N` entries (admin, default 30).

Dragging an unpinned learned tag pins it at the drop position. Dragging is
the only way to fix a position, which matches "drag to reorder + pin"; the pin
toggle unpins it back into the scored block.

Learning is **thin** while fewer than 3 entries qualify. The feed then adds
the user's featured tags and the top 10 local trending tags
(`HashtagService` trends) as temporary seeds with weight 0.5. These do not
appear in Settings.

### 6.6 Feed weight per tag
Weight follows rank rather than raw score, so dragging has a predictable
effect: `w(rank) = 1 / (1 + 0.15 × rank)` (rank 0 = top), which gives 1.0 for
the top entry, about 0.5 at rank 7, and 0.18 at rank 30. Tags with a negative
effective score get weight `−0.5`.

## 7. Feed ranking

### 7.1 Candidates
Posts the viewer is allowed to see (the same visibility limits as the public
and home timelines), created within the last `W` days (admin, default 7),
carrying at least one tag from the effective list. The query joins
`social_stream_tag` on `hashtag IN (…)` (index `social_st_ht` exists), and at
most 1 000 candidates are fetched, newest first.

Excluded: the user's own posts; posts from blocked or muted accounts and
blocked domains; posts matched by a filter with the *hide* action (a *warn*
filter shows the usual warning); posts marked "less like this"; posts not in
the chosen languages (posts without a language are kept); replies to posts the
viewer cannot see.

### 7.2 Score

```
relevance = Σ w(tag) over matched tags, at most 3 tags counted
recency   = 2^(−age_hours / 24)
rank      = relevance × recency
```

Candidates with `relevance ≤ 0` are dropped.

### 7.3 Diversity and exploration
Applied when a page is assembled:
- at most 2 posts per author per 20;
- at most 40 % of a page from the same top-matched tag;
- about 1 in 10 slots is an **exploration** post: a post with a tag that
  frequently co-occurs with the user's top 5 interests but is not listed
  itself. Its chip says "Related to #photography". Exploration is left out
  while learning is thin.

### 7.4 Pagination
Ranking is not chronological, so a page is cut from a **ranked snapshot** of
post ids, cached per user for 15 minutes (`ICache`). The snapshot supports
both `offset` and `max_id`: with `max_id` the page continues after that id's
position in the snapshot, so clients that page with the last post's id work
unchanged. Pull to refresh (no cursor) builds a new snapshot. The response
carries a `Link` header with `next` (`max_id`) and `prev`.

## 8. API

Internal (web UI), under the app's existing API routing:

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/interests/signals` | batch of web events (5.1); 204; rate limited 30/min |
| GET | `/api/v1/interests` | effective list with source, pinned, rank, score |
| POST | `/api/v1/interests` | add manual `{ tag }` |
| PUT | `/api/v1/interests/order` | `{ tags: [...] }` for the pinned + manual block |
| POST | `/api/v1/interests/{tag}/pin` / `unpin` | |
| DELETE | `/api/v1/interests/{tag}` | reset (6.3) / remove manual entry |
| POST | `/api/v1/interests/reset` | reset all |
| GET/PUT | `/api/v1/interests/settings` | learning on/off, paused, languages, notice acknowledged |
| POST | `/api/v1/statuses/{id}/less_like_this` | −3 to its tags, hide from feed |
| GET | `/api/v1/timelines/interests` | the feed; `limit`, `max_id`, `offset` |

Mastodon apps: there is no standard endpoint for this, and clients do not know
`/api/v1/timelines/interests`. To make the feed visible in existing apps
without client changes, `/api/v1/lists` also returns a **pseudo-list**
`{ "id": "interests", "title": "My interests", "replies_policy": "none",
"exclusive": false }`, and `/api/v1/timelines/list/interests` serves the
feed ([ListController.php:384](../lib/Controller/ListController.php#L384)).
Editing the pseudo-list (members, title, delete) returns 422. Learning from
apps is limited to the explicit actions in 5.2, because apps send no dwell.

These endpoints need to go into `Mastodon-Compatibility.md` and the route
tables. Watch out for the cross-controller route-order hazard (#2284).

## 9. Data model

Two new tables. They are schema-only, so they go into the squashed migration,
`$tables`, `Architecture.md`, and a regenerated `schema.json`.

`social_interest`

| Column | Type | Notes |
|---|---|---|
| id | bigint, autoincrement | |
| actor_id | string(127) | local actor |
| hashtag | string(127) | normalised |
| score | float | clamped [−10, 50] |
| scored_at | datetime | for lazy decay |
| manual | bool | added by the user |
| pinned | bool | |
| position | int, nullable | order within the pinned/manual block |
| score_week | float, nullable | optional, for the cloud's trend arrow (4.4) |

Unique index `(actor_id, hashtag)`; index `(actor_id, score)`.

`social_interest_hide` — posts marked "less like this": `actor_id`,
`stream_id`, `created_at`, unique `(actor_id, stream_id)`. Rows older than
`W` days are purged by the existing background job, because they cannot match
the candidate window any more.

Per-user preferences (`IConfig` user values, app `social`): `interests_enabled`
(default `1`), `interests_paused`, `interests_paused_at`,
`interests_languages` (JSON), `interests_baseline` (float),
`interests_notice_ack`.

Folding a signal into a score is one upsert per (actor, tag). On PostgreSQL a
swallowed unique violation aborts the transaction, so this must be a real
upsert or a select-then-insert with retry, never a catch-and-ignore.

## 10. Administration

In `AdminSettings` (Social admin page):
- **Enable My interests** (default on). Off: the switcher tab and settings
  section are hidden, all `/interests` endpoints and the pseudo-list return
  404 / are omitted, and nothing is collected. Existing data is kept, so
  switching the feature back on restores everyone's interests.
- **Learning default for users** (on / off, default on). It applies to every
  user who has not changed the *Learn from my browsing* toggle themselves; a
  user's own choice always wins. It lets operators who read the GDPR as
  requiring consent for profiling make learning opt-in. With the default off,
  the first-use notice (4.2) becomes an invitation ("Turn on" / "Not now")
  instead of an announcement.
- **Tuning**: half-life `H` (days, 7–180, default 30); listing threshold `T`
  (default 3.0); list cap `N` (5–100, default 30); candidate window `W` (1–30
  days, default 7). Validated server-side and exposed through `ConfigService`.

## 11. Privacy and data lifecycle

- Scores are private: never federated, never in the public profile, never in
  any API response for another user, and not visible to admins in the UI.
- No raw event log: signals are folded into scores and discarded. Dedupe keys
  expire after 24 h.
- **Data export** (`lib/UserMigration/SocialMigrator.php`): interests
  (hashtag, score, scored_at, manual, pinned, position) and the preferences in
  §9 are exported and imported. Imported scores keep their `scored_at`, so
  decay continues correctly. `social_interest_hide` is not exported.
- **Account deletion** (`ActorCascadeService`): both tables are purged for the
  actor.
- **Reset all** deletes the user's rows in both tables and the baseline.
- Opting out keeps the data (so opting back in restores the list), and the
  settings section offers **Reset all** next to the toggle.

## 12. Performance

- The signal endpoint does at most one lookup of post hashtags for the whole
  batch (`stream_id IN (…)` on `social_stream_tag`) and one upsert per
  affected tag. Target: < 30 ms for 100 events.
- The feed query is bounded by the top-N tag list, the `W`-day window and a
  1 000-row cap. Ranking happens in PHP on that set. The snapshot cache means
  one ranking per user per 15 minutes of scrolling. Target: first page < 150 ms
  at 1M users / 10M posts (the same scale as the #2210 perf work). This has to
  be measured with `log_query` on devel before merge.
- The tracker adds no request per post: batched, beacon on unload.

## 13. Accessibility and i18n
- Cloud reordering has keyboard and touch equivalents (4.4: popover
  Higher/Lower/Move to top, Alt+arrow keys, `aria-live` announcements). Rank
  is never conveyed by size alone: it is in each tag's accessible name
  ("#photography, priority 3 of 24, pinned").
- The chip is a real link with an accessible name ("Why you're seeing this:
  interested in #photography").
- All new strings go through `t('social', …)`. A new label in Navigation,
  TimelinePost or similar trips `TranslatableStringsTest`, so phpunit runs as
  well as the JS build.

## 14. Testing
- `InterestScorer`: normalisation against the baseline, skip, splitting,
  15-tag cutoff, decay and pause shift, clamping, followed floor, list
  assembly (pinned / manual / learned / cap), rank weights, thin-learning
  seeds.
- Feed ranking: relevance × recency, diversity caps, exploration slot,
  exclusion rules (mutes, blocks, filters, language, hidden, own posts),
  snapshot pagination by `offset` and by `max_id`.
- Controllers: every endpoint, including the disabled-by-admin and opted-out
  paths, rate limiting, 422 on editing the pseudo-list, and that one user can
  never read or change another user's interests.
- Signal hooks in Like/Boost/Bookmark/reply services, including dedupe.
- Migrator export/import round trip; cascade on account deletion.
- `interestTracker.js`: fake timers + a mocked IntersectionObserver for the
  visibility, focus, idle pause, 30 s cap, CW-collapsed and skip rules, and
  the flush on `pagehide`.
- `InterestCloud.vue`: rank → size step mapping, drag reorder, popover
  actions, keyboard moves and announcements, reduced-motion.
- Playwright on devel: switcher tab, chip, less like this + undo, the cloud
  (drag, popover higher/lower/pin/remove, add, candidates, reset) at desktop
  and phone width, in light and dark themes.

## 15. Documentation
The README, `info.xml` description (with a fresh screenshot of the feed and
the settings section), `Architecture.md` (tables, scorer, tracker),
`Mastodon-Compatibility.md` (new endpoints, pseudo-list) must be updated in
the same change as the code; `DocumentationTest` guards the checkable parts.

## 16. Delivery plan
1. **Scoring core**: tables, `InterestScorer`, `InterestService`, server-side
   action hooks, settings endpoints, export/cascade. Learning from actions
   starts; there is no UI yet.
2. **Web tracker**: `interestTracker.js`, signals endpoint, first-use notice.
3. **Feed**: candidate query, ranking, diversity, snapshot pagination, switcher
   tab, chip, less like this, thin-learning seeds, pseudo-list.
4. **Settings UI**: Interests section with the interest cloud
   (drag/popover/pin/add/remove/candidates), reset, languages, toggles.
5. **Admin**: enable switch, learning default for users, tuning parameters.

Each phase is one PR. Phases 1+2 and 3 are the large ones; together they are
likely to approach the size where a split matters.

## 17. Decisions
Resolved 2026-09-24:
1. **GDPR / on-by-default tracking**: an admin setting *Learning default for
   users* is added (§10). Out of the box it is on.
2. **Remove = reset only**: confirmed. A removed tag can be learned again;
   lasting suppression comes from "less like this" or a filter.
3. **Admin disable keeps data**: confirmed (§10).
4. **How the feed reaches Mastodon apps**: as a pseudo-list named
   "My interests" (§8). Apps only show the feeds the Mastodon API defines,
   and every app has a Lists section, so the feed appears there without app
   changes. Editing the pseudo-list (rename, members, delete) returns 422,
   which some apps show as an error; this is accepted.

No open points remain.
