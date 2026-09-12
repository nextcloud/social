# Technical debt and legacy code

What in this app is old, borrowed or load-bearing in a way nobody would choose
today, what is simply dead, and what it would cost to change. Written for
whoever has to decide where refactoring effort goes.

**Verified against:** `master` at `d3a8310e` (0.16.0), 2026-09-12, read
alongside the release checkout that raises the floors to PHP 8.3 and Nextcloud
35. Where the two differ, the difference is called out rather than averaged.
This is the second pass after the fixes in
[#2127](https://github.com/nextcloud/social/pull/2127); it also accounts for
the ESLint 10 migration in [#2129](https://github.com/nextcloud/social/pull/2129)
and the dependency sweep in [#2130](https://github.com/nextcloud/social/pull/2130),
both listed under [What has been done](#what-has-been-done). Every number was
measured on that tree rather than carried over, and
[Reproducing the measurements](#reproducing-the-measurements) gives the command
for each.

**The support floors are whatever `appinfo/info.xml` declares.** Nothing else
in the repository holds them: the PHPUnit, lint and app-store workflows read the
PHP and Nextcloud ranges out of that file (through
`icewind1991/nextcloud-version-matrix`, and by grepping `php min-version`
directly), so changing two attributes there changes what CI tests against.
`master` at `d3a8310e` declares PHP 8.1–8.5 and Nextcloud 28–35. The checkout
being prepared for the next release — the one this file was written on, also
installed on the devel server — declares **PHP 8.3–8.5 and Nextcloud 35–36**.
This document is written for the raised floors, and says where the code has not
caught up with them.

Like [Performance.md](Performance.md), this file is **not** enforced by
`tests/DocumentationTest.php` beyond its title: its claims are structural rather
than checkable against a route table, so nothing fails when it goes stale.
Re-measure before quoting it. Query and scalability behaviour lives in
`Performance.md` and is not repeated here; the schema is described in
[Architecture.md](Architecture.md).

---

## The shape of what is left

The app is 76,072 lines of PHP across 416 files in `lib/`, and 16,678 lines of
JavaScript and Vue across 81 files in `src/`.

Most of what one expects to find in an app of this age is not here, and a good
deal of it never was — see [What is not debt](#what-is-not-debt), which lists
the searches so they do not get repeated. What remains is concentrated rather
than spread out.

| Theme | Severity | Size |
|---|---|---|
| Vendored toolkit extending a private core class | **High** | 3,079 LOC |
| The superseded half of the Custom Local API | Medium | 18 routes |
| Five post fields readable only by parsing JSON | Medium | — |
| Vuex + mixins + 100 % Options API | Medium | 1,432 LOC |
| Floors raised in `info.xml`, nothing in the code moved with them | Medium | 5 places |
| The 2022–2023 migration block | Low | 1,650 LOC |
| PHPUnit 9 (end of life) and a two-year-old `nextcloud/ocp` pin | Low | 81 data providers |
| Nineteen ESLint style rules switched off pending a reformat | Low | ~1,200 reports |
| Translation catalogue covers under 40 % of source strings | Low | — |

Two things worth saying before the list.

**The debt has a shape.** Draw a line around `lib/Tools/` and
`lib/Db/CoreRequestBuilder.php` and you have enclosed nearly all of it: 33 of
the 35 remaining untyped properties, the internally `@deprecated` methods still
being called, and the one inheritance edge that can break the app without anyone
changing the app. Outside that line the code is markedly more modern than the
app's age would predict.

**Several items were blocked by the declared support range, and the range has
now moved.** While `info.xml` claimed Nextcloud 28 and PHP 8.1, four cleanups —
`IAppConfig` (`@since 29`), attribute routes (`@since 29`), a current
`nextcloud/ocp` (needs PHP 8.3) and `#[\Override]` (PHP 8.3) — could not be
done. With the floors at Nextcloud 35 and PHP 8.3 every one of them is
unblocked, and none has been started. The raise itself is also only half done:
`composer.json` still says `"php": ">=8.1"` with a `platform.php` of `8.1.31`,
`psalm.xml` still suppresses `MissingOverrideAttribute` with a comment that
names the 8.1 floor as the reason, and `nextcloud/ocp` still points at a
2024-10-23 commit. See [Finishing the floor raise](#finishing-the-floor-raise).

---

## Age of the code

Lines of `lib/` by the year they were last touched, from `git blame`:

| Year | Lines | Share |
|------|-------|-------|
| 2018 | 8,139 | 10.7% |
| 2019 | 8,700 | 11.4% |
| 2020 | 3,999 | 5.3% |
| 2021 | 11 | 0.0% |
| 2022 | 6,323 | 8.3% |
| 2023 | 3,711 | 4.9% |
| 2024 | 1,044 | 1.4% |
| 2025 | 50 | 0.1% |
| 2026 | 44,095 | 58.0% |

Run this on a complete checkout: `git blame` can only attribute lines it has
history for, so a shallow or out-of-date clone silently understates the recent
share. Measuring it on a checkout that was hundreds of commits behind put 2026
at 29 % rather than 58 %.

Within that, `lib/Tools/` is frozen: **93 % of it dates from the 2022 import and
has not been touched since**, 42 lines changed in 2023, 73 in 2024 and 114 in
2026 — the last of those being the constructor promotion and the two files moved
out in #2127, not work on the toolkit itself.

---

## The database layer is built on a private core class

This is the largest item in the report, and the only one that can break the app
without anyone changing the app.

`lib/Tools/` is a re-namespaced copy of the `daita/my-small-php-tools` library —
27 files, 3,079 lines, vendored into the app rather than required through
Composer. Every upstream reference has been stripped and the headers rewritten,
but the provenance is legible in the `NC`-prefixed class names, the PHP 7.0-era
style, the `@deprecated - 19` / `@deprecated - 21` markers that refer to
*toolkit* versions rather than app versions, and `git blame`, which attributes
the files to their original author at `artificial-owl.com`.

It is not dead weight. It is the foundation of the data layer.

### The inheritance edge

```
lib/Tools/Db/ExtendedQueryBuilder.php:16   use OC\DB\QueryBuilder\QueryBuilder;
lib/Tools/Db/ExtendedQueryBuilder.php:29   class ExtendedQueryBuilder extends QueryBuilder …
```

`OC\DB\QueryBuilder\QueryBuilder` lives in the server's `lib/private/` and
carries no API stability promise. The whole persistence layer descends from it:

```
OC\DB\QueryBuilder\QueryBuilder          (Nextcloud private)
  └─ ExtendedQueryBuilder
       └─ SocialCoreQueryBuilder
            └─ SocialCrossQueryBuilder
                 └─ SocialLimitsQueryBuilder
                      └─ SocialFiltersQueryBuilder
                           └─ SocialQueryBuilder
                                └─ 68 files in lib/Db/
```

`QBMapper` and `Entity` — the API the server offers apps — appear nowhere.

`lib/Db/SocialCoreQueryBuilder.php` hard-codes
`parent::__construct($connection, $systemConfig, $logger)`, and
`ExtendedQueryBuilder` declares no constructor at all, so a server release that
changes that signature is an immediate fatal with no deprecation first. Core has
already added sharding parameters to it upstream. A refactor that fully respects
the server's *public* contract can still break every database call in this app,
and it would arrive as a fatal on somebody's `occ upgrade`.

Because `OC\SystemConfig` is private and not resolvable through app dependency
injection, `lib/Db/CoreRequestBuilder.php` reaches for it directly with
`OC::$server->get(\OC\SystemConfig::class)` — the only use of `\OC::$server` in
production code in the whole app, and it exists solely to feed the private
parent.

### What the toolkit actually buys

Most of the live surface is thin sugar over the public `IQueryBuilder`:
`limitToDBField` (58 uses) and `setDefaultSelectAlias` (47 uses) are the two
that matter, and both amount to `andWhere()` + `expr()->eq()` with a default
table alias. That is the argument for retiring the toolkit rather than porting
it.

The rest is duplication. `lib/Db/CoreRequestBuilder.php` reimplements the *same
ten helpers* that already exist on `ExtendedQueryBuilder` — one copy takes
`IQueryBuilder &$qb` by reference, the other is a method on the builder.
`CoreRequestBuilder::leftJoinCacheActors()` and
`SocialCrossQueryBuilder::leftJoinCacheActor()` are two implementations of one
join.

### The order to do this in

Moving to `QBMapper` touches every `*Request` class and is not the first step.
The first step, worth doing on its own, is to stop extending the private class:
replace `ExtendedQueryBuilder`'s inheritance with composition over the public
`IQueryBuilder`, keeping only the handful of helpers that carry their weight.
The two modern security classes that used to sit in this directory have already
been moved to `lib/Security/`, so nothing recent is mixed in to complicate it.

---

## Two API generations in one URL namespace

The app serves two API designs, and they share the `/api/v1/` prefix.

- **Mastodon-compatible** — `ApiController` (2,873 lines), `ListController`,
  `FilterController` and others, through `StreamService::getTimeline(ProbeOptions)`.
- **Custom local** — `LocalController` (1,036 lines), through the five
  `@deprecated` `StreamService::getStream*()` methods and then the five
  `StreamRequest::getTimeline*_dep()` query methods behind them.

So `/api/v1/stream/home` (local: `since`/`limit` cursor, default page size 5, no
`Link` header, `{status, result}` envelope) sits directly beside
`/api/v1/timelines/home` (Mastodon: `max_id`/`since_id`, page size 20, `Link`
header, bare entity).

**18 of the 31 `Local#` routes have no caller anywhere in `src/`**, including all
seven `/api/v1/stream/*` endpoints — the ones still backed by the `_dep` query
methods. They are marked deprecated in [API.md](API.md) with the Mastodon route
to use instead, and kept rather than removed because they are a published
surface. Retiring them retires that whole query layer; doing it needs a
deprecation cycle and a release note, not a patch. The Mastodon parity wave in
[#2126](https://github.com/nextcloud/social/pull/2126) grew `ApiController` by
another 137 lines and touched none of this, which is the expected direction:
the Mastodon side keeps growing, the local side is frozen.

Routes themselves are still an array: 202 in `appinfo/routes.php`, and zero
`#[FrontpageRoute]`/`#[ApiRoute]` attributes, although access control is fully
attribute-based. The policy lives next to the method and the URL lives 200 lines
away. The route attributes are `@since 29.0.0`, so on the old Nextcloud 28
floor the array was the only option; on the 35 floor it is the remaining half
of a finished migration, and low priority.

---

## Five post fields live only inside JSON

`Stream::importFromDatabase()` runs once per timeline row. It `json_decode`s the
stored ActivityPub wire object and extracts `tag`, `language`, `updated`,
`quote` and `quoteAuthorization` from it, because — as the code comments state
outright — none of the five has a column. It then derives the `remote_likes`,
`remote_boosts` and `replies` counters from the same JSON, and rehydrates media
attachments and the cache object from two more JSON columns.

Those five fields are user-visible and cannot be queried, indexed or sorted on.
Post language in particular is what a language filter would need. The two
schema steps added on 2026-09-12 gave `social_stream` a `forwarded` flag and
`social_cache_actor` a `bot` flag; neither touched the five.

Related, and deliberate rather than accidental: `social_hashtag` stores each
trend twice (a JSON blob the API returns, and five integer columns because no
supported database can sort on the JSON), and `social_stream` carries nine
JSON-in-TEXT columns of which `hashtags` duplicates `social_stream_tag` and
`to_array`/`cc`/`bcc` duplicate `social_stream_dest`. The side tables exist
*because* the JSON is unqueryable, so every insert writes both.

---

## Frontend

Modern in its framework usage, dated in its architecture.

**Vuex 4, no Pinia.** 1,320 lines across six files; 5 modules, 30 state keys, 40
mutations, 21 getters, 10 actions; 111 `this.$store.*` references across 20
files. No module is namespaced, so every mutation and getter name is unique by
convention only. Two `state` conventions coexist — `notifications.js` uses the
correct factory form and its own comment explains why the alternative is wrong,
while the other four use a shared object literal. There are no map helpers at
all, which is what makes a Pinia migration a 111-site change rather than a
helper swap.

**100 % Options API.** Zero `<script setup>`, zero `setup()`, and no
`ref`/`computed`/`reactive`/`watch`/`onMounted` anywhere in `src/`.

**Three mixins, 112 lines, 19 consuming components**, all pure bundles of
computed properties over `$store` and a 1:1 map to composables. `accountMixins`
mixes in `serverData`, so components inherit a transitive property set they
never named, and one mixin is imported under four different spellings
(`currentuserMixin`, `CurrentUserMixin`, `currentUserMixin`, `currentUser`).
`accountMixins` also carries a comment admitting `accountLoaded` is "somewhat
duplicate with `accountInfo()`, but needed (for some reason)".

**One dead compatibility branch.** `src/profile.js` registers against
`window.OCA.Core.ProfileSections` "to keep compatibility with older Nextcloud
builds". That contract predates Nextcloud 25, so it was already dead at the old
floor of 28 and is further from reach at 35; the branch immediately above it
covers the entire supported range.

**JSDoc typedefs that nothing checks.** `src/types/ActivityPub.js` and
`src/types/Mastodon.js` (237 lines) are imported as types by 22 files — up from
ten a week ago, so the habit is spreading — but there is no root `tsconfig.json`
and no `checkJs`, so nothing validates them against the real API shapes.

**Unlinted code.** `npm run lint` covers `src` and `tests/js`; `npm run
stylelint` covers `src`. Outside that: `js/social-adminSettings.js` (160
hand-written lines shipped to administrators), `css/dashboard.css`,
`webpack.common.js`, `vitest.config.js` and `eslint.config.mjs` itself. The
flat-config migration did not widen the scope.

**Deprecated global APIs.** Three uses of `OC.Notification.showTemporary`,
all in `src/adminAnnouncements.js` — an earlier pass counted nine, but three is
what the tree held at 0.15.1 as well. `@nextcloud/dialogs` is already a
dependency and imported by 13 files, so the replacement is mechanical.

**Nineteen style rules switched off.** `eslint.config.mjs` disables nineteen rules
from `@nextcloud/eslint-config` 9 in a block that names each one and what it
would cost — about 1,200 reports across 126 files, the largest being 523 for
blank lines between component properties, ~290 across eight `@stylistic`
whitespace rules, 200 for import order and 109 for arrow functions at module
top level. None of them reports a defect. Adopting them is a reformatting
decision to be taken on its own; until then every new file is held to a looser
standard than the shared config intends, and `perfectionist/sort-imports` in
particular cannot be turned on without first moving the comments that explain
the side-effect imports.

---

## Toolchain

| Tool | Pinned | Status |
|---|---|---|
| `nextcloud/ocp` | `dev-master` at a **2024-10-23** commit | **Was blocked, now stale.** Current dev-master requires PHP 8.3, which the floor now gives. Pin the branch that matches the declared range (`dev-stable35`) rather than `dev-master`, which tracks the next major. |
| PHPUnit | `^9.5` (9.6.34) | End of life. PHPUnit 10+ requires static data providers; all 81 providers across 43 test files are instance methods. PHPUnit 12 needs PHP 8.3, which the floor now gives. |
| Psalm | `^6.17` | Current, and running on supported PHP. |
| ESLint | 10.10.0, `@nextcloud/eslint-config` 9.0.1, flat config | Current since #2129. Nineteen style rules deferred, see above. |
| Stylelint | `^17.15` | Current. |
| Vitest / jsdom | 5.0 / 30.0 | Current since #2130. |
| webpack / vue-router / vite | 5.110 / 5.3 / 8.3 | Current since #2130. |
| `node-polyfill-webpack-plugin` | exactly `4.0.0` | **Blocked upstream.** `@nextcloud/webpack-vue-config` 6.3.2 peer-pins it; 4.1.0 needs a release there first. |

`terser-webpack-plugin` became a direct devDependency in #2130 because webpack
5.110 stopped hoisting it where `@nextcloud/webpack-vue-config` expects it. It
was always a real dependency of the build; it was being borrowed from another
package's tree, and it is worth knowing that the build depends on the shared
webpack config's internal layout in more places than that one.

### Finishing the floor raise

`info.xml` moved to PHP 8.3 and Nextcloud 35; these are the places that were
waiting for it and have not moved.

1. **`composer.json`** still declares `"php": ">=8.1"` and a `platform.php` of
   `8.1.31`. Until the platform matches the floor, Composer resolves every
   dependency as if PHP 8.1 were still supported, which is exactly what keeps
   `nextcloud/ocp` on its 2024 commit.
2. **`nextcloud/ocp`** — see the table. Bump it and re-run Psalm: two years of
   `@since` additions and deprecations arrive at once.
3. **`IConfig` -> `IAppConfig`.** `IConfig`'s app-value methods are deprecated
   as of Nextcloud 29 and `IAppConfig`'s typed API is `@since 29.0.0`. Five
   files inject `IConfig`; the 18 direct `getAppValue`/`setAppValue` calls sit
   almost entirely in `ConfigService`, so this is a one-file change plus its
   tests. The nine user-value calls stay on `IConfig`, which is still the API
   for those.
4. **`#[\Override]`.** `psalm.xml` suppresses `MissingOverrideAttribute` for
   all of `lib/` with a comment saying the 8.1 floor forbids the attribute. That
   reason is no longer true, and `lib/UserMigration/SocialMigrator.php` already
   carries the attribute three times. Drop the suppression and let Psalm list
   the sites.
5. **Attribute routes** — see [Two API generations](#two-api-generations-in-one-url-namespace).

None of these is large. What makes them worth listing is that the floor raise
without them is invisible: the app *declares* it needs Nextcloud 35 and PHP 8.3
while every tool still checks it against 28 and 8.1, so nothing catches a
`@since 35` API used by accident or an 8.3-only construct the toolchain would
have flagged.

---

## Schema and migrations

`Performance.md` covers query behaviour and the index and schema-shape hazards.
What follows is the debt in the migration set itself.

All 32 schema steps use the frozen prefix `Version1000Date`, so only the date
orders them:

| Era | Steps |
|---|---|
| 2022-11-18 | 1 (creates all 14 original tables, 1,450 lines) |
| 2023-02 to 2023-04 | 3 (all repairs of the 2022 one) |
| 2026-06-11 | 1 (drops 14 legacy `social_3_*` tables) |
| 2026-09-07 to 2026-09-12 | 27 |

There are numbering gaps at `20260911000003`, `000012` and `000015`–`000019`,
and twenty-seven of thirty-two steps were authored in a six-day window.

Two squash candidates are clean: the 2022 creation plus its three 2023 repairs
(1,650 lines, two of them chasing the same `social_cache_actor` primary-key
defect), and `Version1000Date20260908000001`, which widens a column introduced
one day earlier in the same burst.

`Version1000Date20260611000001` drops fourteen `social_3_*` tables that **no
other file in the tree ever creates** — the creating migrations were deleted and
only the drop survives, so it is dead weight on every fresh install. The legacy
naming split it addresses is finished: there is no `social_a2_*` prefix
anywhere, and all 32 current tables are both read and written.

Squashing is left undone deliberately. It rewrites the upgrade path for every
existing instance, and the 2022–2023 block is exactly the era with no test
coverage — `tests/Migration/` holds 13 tests against 32 schema steps, every one
of them for a 2026 step or a repair step — so the change would be made in the
one place where a mistake surfaces as a failed `occ upgrade` on somebody's
server.

### Two columns written and never read

`social_stream_dest.id` and `social_stream_tag.id` are autoincrement surrogate
keys added purely to give those tables a primary key. Neither appears in the
column lists in `CoreRequestBuilder` nor in the select lists of their request
builders. These are the two highest-insert-rate side tables in the app, and
every row pays for a sequence and an index write nothing reads. The primary keys
are still worth having — this is a note on the cost.

---

## Remaining odds and ends

**`lib/Tools/Model/Request` and `NCRequest`** are 856 lines of hand-rolled HTTP
request modelling wrapped around `OCP\Http\Client\IClient` — an OCP client
re-wrapped in a pre-OCP abstraction. Replacing them with `IClientService`
directly means rewriting the request pipeline and the nine services that
construct an `NCRequest`, including `SignatureService`, which signs based on the
request's method, path and headers. It is the federation transport, and nothing
in the test suite exercises it against a real peer, so it wants a dedicated
change with interop testing behind it.

**32 `@deprecated` markers in `lib/`, 20 of them on methods that still have live
callers**, led by `Request::getUrl` (55 callers, deprecated in favour of
`getPath()` by toolkit version 19), `CoreRequestBuilder::leftJoinStreamAction`
(17) and `CoreRequestBuilder::limitToId` (12). `ACore::verify` still carries its
`// TODO - Compare this with checkOrigin() - and delete this method.` and is
down to three callers. A `@deprecated` marker with fifty-five callers is not a
plan; it is a note that a plan was intended.

**Constructor promotion is at 149 of 205 (73 %).** The remaining 56 are the ones
an automated pass should not touch: constructors that do real work in the body,
that forward a parameter to a parent as well as storing it, or whose property
name differs from the parameter.

**35 untyped properties remain, 33 of them in `lib/Tools/`** —
`Tools/Model/Request.php` accounts for 20, `TArrayTools` for 6, `CacheItem` for
5. The two outside are `TDetails::$details` and `ACore::$parent`.

---

## Translations

The frontend contains 295 distinct translatable strings at 354 call sites. The
best-covered locales (`de`, `de_DE`, `en_GB`) carry 135. The median locale
across 98 languages carries 21, and 47 carry fewer than 20. The strings `Quote`,
`Bookmark`, `Scheduled`, `Announcement`, `Follow requests` and `Photos` appear
in **zero** translation files.

The cause is diagnosed in the header of `.github/workflows/l10n.yml`: nothing in
the repository ever extracted the source strings, so nothing was ever pushed for
translation. `.tx/config` is correct as written — `translationfiles/` is what
the extractor produces, not something the repository holds. The workflow now
exists; the catalogue has not caught up, and that is a Transifex round trip
rather than a code change. Note that #2129 changed six source strings (an
ellipsis now preceded by a non-breaking space), so those six lose whatever
translations they had when the round trip finally runs.

---

## What is not debt

Each item here is a search that does not need repeating.

**Vue 2 leftovers: none.** `Vue.prototype`, `new Vue(`, `$listeners`,
`$set`/`$delete`, `.sync`, `filters:`, `beforeDestroy`, `functional: true` and
`slot-scope` all return zero hits in `src/`. The only occurrences anywhere are
the regexes in `tests/js/vue3.test.js`, which holds each at zero.

**PHPDoc route annotations: none.** Access control is 100 % PHP 8 attributes,
406 occurrences across 20 files, zero docblock annotations, with a regression
test asserting the old form is not reintroduced.

**Legacy PHP constructs: none.** Zero occurrences of `array()`, `list()`,
`strftime`, `utf8_encode`, `each()`, `create_function`, `ereg*` or
`money_format`. No dynamic property creation.

**Raw SQL string concatenation: effectively none.** Not one
`SELECT`/`INSERT`/`UPDATE`/`DELETE` string literal in `lib/`; all `IDBConnection`
uses go through the query builder. The single exception is the visibility filter
in `SocialCrossQueryBuilder`, which binds its parameters.

**Legacy bootstrap: none.** No `appinfo/app.php`, no `appinfo/application.php`.
`lib/AppInfo/Application.php` is 71 lines of correct `IBootstrap` registration.

**`@nextcloud/vue` v9 usage: current.** All 63 imports use the v9 subpath-export
style; zero deep `dist/` imports, zero removed or renamed components.

**Dead frontend code: none.** The import graph over all 81 files in `src/`
resolves, and there are no unused exports.

**Dead model classes: none** (70 checked). **Dead exception classes: none** (46
checked). **Unused controllers: none** (21 checked).

**Repair steps: all four carry markers.** `EncryptPrivateKeys`,
`HashClientSecrets`, `BackfillRemoteVisibility` and the newer
`CacheFeaturedCollections` each record a version in app config and return
early, so none of them re-scans on every upgrade.

### A trap worth knowing before you audit this app

Nextcloud controller methods are **admin-required by default** —
`SecurityMiddleware` enforces it unless the method carries `#[NoAdminRequired]`
or `#[PublicPage]`. The *absence* of an attribute is the guard, not a missing
one. Reading the routes as if the attributes grant access rather than relax it
produces a long list of false "unauthenticated endpoint" findings.

### And one about measuring

Do not measure this app on a working copy without checking it against a fresh
clone first. An earlier pass of this report was taken on a checkout hundreds of
commits behind `origin/master`: it reported ten untested controllers that in
fact have tests, put 45 % of `lib/` in 2023 or earlier when the real figure is
different, and recorded a failing test suite that is green on master. Counting
"dead" methods without treating tests as callers likewise put the figure near
sixty when twenty survive the check.

---

## What has been done

Listed so that the next reader can tell what this document has already
accounted for.

### In [#2127](https://github.com/nextcloud/social/pull/2127)

| Item | Outcome |
|---|---|
| Source maps in the release tarball | Excluded. The tarball was ~22 MB of maps against 6.7 MB of bundles. |
| Three disagreeing packaging lists | One list. `build-package.sh` calls `make appstore`; `.nextcloudignore` deleted (krankerl is not installed). 19 phantom entries dropped, and `cypress.config.ts`, `vitest.config.js`, `patches/`, `deploy.sh` no longer ship. |
| The `elliptic` stub | Replaced with an `overrides` pin on the real `^6.6.1`. The stub claimed to be version 6.6.2, which has never existed on the registry. |
| Cypress husk | Deleted: 8 files, 1 config, 4 npm scripts, 6 packages, 0 tests. |
| Unused npm dependencies | `ical.js`, `uuid`, `cypress-wait-until`, `@nextcloud/cypress` dropped; `buffer` and `webpack-dev-server` declared. |
| Dead code | 3 files and 20 methods with no caller anywhere, 4 dead config constants, an 80-line commented-out block, 4 obsolete Psalm suppressions. |
| Repair steps re-scanning on every upgrade | `EncryptPrivateKeys`, `HashClientSecrets` and `BackfillRemoteVisibility` now carry markers. `RenameDocumentLocalCopy`, `@deprecated` since 0.7.x, is unregistered and deleted. |
| Missing indexes | `social_actor.user_id` and the four unindexed `trend_*` windows. |
| Status-id collisions | Width 1e6 -> 1e9, `rand()` -> `random_int()`, and a collision is retried instead of silently dropping the post. |
| `IInitialStateService` | Migrated to `IInitialState` in four controllers. |
| `Cron\Queue` | Given the wall-clock budget `QueueController` already had. |
| The `AP` static registry | Resolved lazily instead of by an `AP::init();` at file scope, and held in a private static behind an accessor. |
| Unreachable controller methods | Six deleted from `LocalController`. |
| Two unbounded loops | `NotificationService::clear()` and `MigrationService::refollowLocalFollowers()` bounded. |
| The rest of the unbounded work | Closed in [#2132](https://github.com/nextcloud/social/pull/2132), with the N+1 behind `accounts/relationships` and the missing transaction around `StreamRequest::save()`. See [Performance.md](Performance.md), which tracks that category. |
| `tests/stub.phpstub` | `QueryBuilder::SELECT` was the string `'select'`; Doctrine assigns the int `0`, so 28 comparisons were verified against fiction. Fixed and pinned by a test. |
| Psalm 5 -> 6 | Now runs on supported PHP. Found five real type defects and five orphaned access-control attributes. |
| Stylelint 15 -> 17 | Seven deprecated CSS declarations removed. |
| Constructor promotion | 107 -> 148 of 204, and 264 redundant `@param` tags removed with it. |
| `HtmlSanitizer`, `RemoteAddress` | Moved from `lib/Tools/` to `lib/Security/`. |

### Since then

| Item | Outcome |
|---|---|
| ESLint 8 (end of life) | [#2129](https://github.com/nextcloud/social/pull/2129): ESLint 10, `@nextcloud/eslint-config` 9, `.eslintrc.js` -> `eslint.config.mjs`. The config's bundled plugins made the `eslint-plugin-n`/`-import`/`-vue` direct dependencies redundant. The new ruleset found 16 unread catch bindings, three dead assignments, three unused Vuex parameters and a literal `v-bind`, all fixed. The ~1,200 formatting reports were deferred, not adopted — see [Frontend](#frontend). |
| Nine dependabot bumps that conflicted on `package-lock.json` | [#2130](https://github.com/nextcloud/social/pull/2130): `vue` 3.5.42, `webpack` 5.110, `jsdom` 30, `vue-router` 4 -> 5 with `vite` 8 behind it, `@nextcloud/dialogs` 7.5. One behaviour change (an absent optional route param is now `undefined`, not `''`) is asserted by a test. |
| `CacheFeaturedCollections` repair step | Added with a version marker from the start, so the repair-step pattern fixed in #2127 was not reintroduced. |

---

## Rough order of value for what is left

1. Stop `ExtendedQueryBuilder` extending the private core `QueryBuilder` —
   composition over the public `IQueryBuilder`, then retire the rest of
   `lib/Tools/`.
2. Give `tag`, `language`, `updated`, `quote` and `quoteAuthorization` real
   columns.
3. Replace `Tools\Model\Request` and `NCRequest` with `IClientService`, with
   interop testing behind it.
4. Retire the 18 uncalled Custom Local API routes on a deprecation cycle, and
   the five `getStream*()` / `getTimeline*_dep()` methods with them.
5. Vuex -> Pinia, mixins -> composables.
6. PHPUnit 10+, which means making 81 data providers static across 43 files.
7. Finish the floor raise in code: `composer.json` platform and `php`
   constraint, `nextcloud/ocp` on the matching stable branch, the
   `MissingOverrideAttribute` suppression, `IAppConfig`, attribute routes.
   Mechanical, and the only item here where doing nothing leaves the declared
   range untested.
8. Turn on the nineteen deferred ESLint rules, as its own reformatting change.
9. Finish the three `showTemporary` calls and the `profile.js` compatibility
   branch — an afternoon, listed here so it is not forgotten.
10. Squash the 2022–2023 migration block, once that era has test coverage.
11. Finish the l10n round trip.

---

## Reproducing the measurements

```bash
# The support floors, and the places that should agree with them
grep -oE '(php|nextcloud) min-version="[^"]+" max-version="[^"]+"' appinfo/info.xml
grep -nE '"php": |platform' composer.json | head -3
grep -n 'MissingOverrideAttribute' psalm.xml
grep -rn 'php min-version\|nextcloud-version-matrix' .github/workflows/*.yml | wc -l

# Sizes
find lib -name '*.php' | wc -l && find lib -name '*.php' -exec cat {} + | wc -l
find src -type f \( -name '*.js' -o -name '*.vue' \) | wc -l

# Age of the code (needs a full clone, not a shallow one)
git ls-files lib | grep '\.php$' | while read f; do
  git blame --line-porcelain -- "$f" | grep '^committer-time '
done | python3 -c "
import sys, datetime, collections
c = collections.Counter(datetime.datetime.fromtimestamp(int(l.split()[1]), datetime.UTC).year for l in sys.stdin)
t = sum(c.values()); [print(y, c[y], '%.1f%%' % (100*c[y]/t)) for y in sorted(c)]"

# Legacy PHP constructs (all should be 0)
grep -rnE '\barray\(|\blist\(|strftime|utf8_encode|\beach\(|create_function' lib/ --include='*.php' | wc -l

# Docblock route annotations (0) versus attributes
grep -rn '^\s*\* @\(NoAdminRequired\|PublicPage\|NoCSRFRequired\)' lib/ | wc -l
grep -rn '#\[\(NoAdminRequired\|PublicPage\|NoCSRFRequired\)\]' lib/ | wc -l

# The private QueryBuilder edge
grep -rn 'OC\\DB\\QueryBuilder\\QueryBuilder\|OC::\$server' lib/ --include='*.php'

# Untyped properties
grep -rnE '^\s*(var|private|protected|public|static|readonly)(\s+(static|readonly))*\s+\$\w+' lib --include='*.php'

# @deprecated methods that still have callers
grep -rn '@deprecated' lib --include='*.php' | wc -l
grep -rn '\->verify(' lib --include='*.php' | wc -l          # ACore::verify
grep -rn 'leftJoinStreamAction(' lib --include='*.php' | grep -v 'function ' | wc -l

# Constructor promotion adoption
python3 - <<'PY'
import re, glob
p = n = 0
for f in glob.glob('lib/**/*.php', recursive=True):
    for m in re.finditer(r'function\s+__construct\s*\((.*?)\)\s*[:{]', open(f).read(), re.S):
        a = m.group(1)
        if re.search(r'\b(private|protected|public|readonly)\s', a): p += 1
        elif a.strip(): n += 1
print('promoted', p, 'of', p + n)
PY

# Custom Local API routes with no caller in src/
python3 - <<'PY'
import re, glob
routes = [m.groups() for l in open('appinfo/routes.php')
          if (m := re.search(r"'name' => 'Local#(\w+)'.*?'url' => '([^']+)'", l))]
src = ''.join(open(p, errors='replace').read() for p in glob.glob('src/**/*', recursive=True)
              if p.endswith(('.js', '.vue')))
for name, url in routes:
    if url.split('{')[0].rstrip('/').lstrip('/') not in src:
        print('uncalled:', url)
PY

# PHPUnit 10 cost: instance data providers
grep -rnE 'public function \w*[pP]rovide\w*\(' tests | wc -l

# Deferred ESLint rules (20: the nineteen, plus the Vue 2 multiple-root rule)
grep -c "'off'" eslint.config.mjs

# Source maps that would ship (expect 1: the Makefile excludes them)
grep -c 'js/\*\.map' Makefile

# Translation coverage
grep -rhoE "\b[tn]\('social',\s*'[^']*'" src | sort -u | wc -l
python3 -c "import json,glob; print(sorted((len(json.load(open(f))['translations']), f) for f in glob.glob('l10n/*.json'))[-3:])"

# Suites
composer test:unit && composer psalm && npm run lint && npm run stylelint && npm test
```
