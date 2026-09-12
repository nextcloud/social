# Technical debt and legacy code

What in this app is old, borrowed or load-bearing in a way nobody would choose
today, what is simply dead, and what it would cost to change. Written for
whoever has to decide where refactoring effort goes.

**Verified against:** app version 0.17.0, 2026-09-12, after the wave described
in [What has been done](#what-has-been-done). Every number was measured on that
tree rather than carried over, and
[Reproducing the measurements](#reproducing-the-measurements) gives the command
for each.

**The support floors are whatever `appinfo/info.xml` declares.** Nothing else in
the repository holds them: the PHPUnit, lint and appstore workflows read the PHP
and Nextcloud ranges out of that file, through
`icewind1991/nextcloud-version-matrix` and a direct `grep php min-version`, so
changing two attributes there changes what CI tests against. They now say **PHP
8.3–8.5 and Nextcloud 35–36**, and `composer.json`, `psalm.xml` and the server
API stubs agree with them rather than contradicting them.

Like [Performance.md](Performance.md), this file is **not** enforced by
`tests/DocumentationTest.php` beyond its title: its claims are structural rather
than checkable against a route table, so nothing fails when it goes stale.
Re-measure before quoting it. Query and scalability behaviour lives in
`Performance.md`; the schema is described in [Architecture.md](Architecture.md).

---

## The shape of what is left

The app is 77,200 lines of PHP across 418 files in `lib/`, and 17,762 lines of
JavaScript and Vue across 81 files in `src/`.

| Theme | Severity | Size |
|---|---|---|
| The hand-rolled HTTP request model in front of the OCP client | Medium | 856 LOC |
| The superseded half of the Custom Local API | Medium | 18 routes |
| Every occ command extends a private core class | Medium | 20 commands |
| Routes as an array rather than attributes | Low | 202 routes |
| The 2022–2023 migration block, now testable | Low | 1,654 LOC |
| Translation catalogue covers under 40 % of source strings | Low | — |
| PHPUnit 12 | Low | ~3,100 stub migrations |

The largest item in every previous version of this document — a vendored toolkit
whose query builder extended a private core class — is gone, and with it the
argument that `lib/Tools/` is load-bearing. What is left of that directory is
helpers and traits: 3,495 lines that no longer reach outside `OCP\`, and one
model (`Request`) that is genuinely worth replacing rather than merely old.

**One name from outside `OCP\` is left in `lib/`,** and it is in the next
section. `\OC::$server`, `OC\SystemConfig`, `OC\DB\Connection`,
`OC\DB\SchemaWrapper`, `OC\DB\QueryBuilder\QueryBuilder`,
`OC\User\NoUserException` and every `Doctrine\` class are gone from lib/; the
matches that remain for those are sentences in docblocks explaining what used
to be there.

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

Measured before this wave, which touched most of `lib/Tools/` and every file
with an overriding method; the 2022 share in particular is now smaller than the
table says. Run it on a complete checkout: `git blame` can only attribute lines
it has history for, so a shallow or out-of-date clone silently understates the
recent share.

---

## Every occ command extends a private core class

All twenty commands in `lib/Command/` extend `OC\Core\Command\Base`, which
lives in the server's `core/` and carries no stability promise — the same shape
of dependency the query builder had, with the same failure mode: a signature
change upstream is a fatal on somebody's `occ`, with no deprecation first.

It is a smaller problem than the query builder was, because the blast radius is
the command line rather than every database call, and because what `Base`
provides is easy to name: `parent::configure()` adds the `--output` option, and
21 call sites use `writeArrayInOutputFormat()` and its siblings to honour it.
The public alternative is Symfony's own `Command`, which the server itself is
moving towards, plus a small trait of this app's own for the three output
formats — the app already writes tables by hand in several of these commands.

Doing it means deciding what `occ social:* --output=json` prints, which is a
published interface of its own. That is why it is listed rather than done.

## The federation transport is modelled twice

`lib/Tools/Model/Request` and `NCRequest` are 856 lines describing an HTTP
request — protocol, host, port, path, headers, cookies, query-string flavour,
timeouts, whether errors are allowed — which `CurlService` then translates into
options for `OCP\Http\Client\IClient`. It is an OCP client wrapped in a pre-OCP
abstraction: the app is not making its own HTTP calls, it is making its own
description of them first.

Nine services build an `NCRequest`, and one of them is `SignatureService`, which
signs outbound activities over the request's method, path and headers. That is
what makes this the most delicate item in the report rather than the largest.
A wrong header name, a path normalised differently, a header written in another
order, and signatures still verify locally while every peer rejects them —
and nothing in the test suite talks to a real peer.

So it wants a change of its own with interop testing behind it, against a real
Mastodon and a real Pixelfed, not a refactor folded into a cleanup wave. It is
listed here at medium severity because the code works; what it costs is that
every change to outbound HTTP has to be made in two places.

---

## Two API generations in one URL namespace

The app serves two API designs, and they share the `/api/v1/` prefix.

- **Mastodon-compatible** — `ApiController` (2,873 lines), `ListController`,
  `FilterController` and others, through `StreamService::getTimeline(ProbeOptions)`.
- **Custom local** — `LocalController` (1,036 lines), through the five
  `@deprecated` `StreamService::getStream*()` methods and the five
  `StreamRequest::getTimeline*_dep()` query methods behind them.

So `/api/v1/stream/home` (local: `since`/`limit` cursor, default page size 5, no
`Link` header, `{status, result}` envelope) sits directly beside
`/api/v1/timelines/home` (Mastodon: `max_id`/`since_id`, page size 20, `Link`
header, bare entity).

**18 of the 31 `Local#` routes have no caller anywhere in `src/`**, including all
seven `/api/v1/stream/*` endpoints — the ones still backed by the `_dep` query
methods. They are marked deprecated in [API.md](API.md) with the Mastodon route
to use instead, and kept rather than removed because they are a published
surface. Retiring them retires that whole query layer; it needs a deprecation
cycle and a release note, which is a decision about the app's compatibility
promise rather than a cleanup.

### Routes are still an array

199 of the 202 routes in `appinfo/routes.php` could be `#[FrontpageRoute]` and
`#[ApiRoute]` attributes on the methods they belong to. The attributes are
`@since 29.0.0`, so the old Nextcloud 28 floor ruled them out; the floor is 35
now and nothing blocks them.

It is still last on the list, and one detail is worth knowing before starting:
`tests/DocumentationTest.php` derives the documented route table by `require`-ing
`appinfo/routes.php` and reading the array. Moving the routes to attributes
without rewriting that test to reflect over the controllers would leave the API
documentation checked against an empty list — the test would pass while
asserting nothing. The URL would move next to the method, which is the whole
benefit, and 202 opportunities for a silent typo in a published path is the
cost.

---

## Schema and migrations

`Performance.md` covers query behaviour and the index and schema-shape hazards.
What follows is the debt in the migration set itself.

All 33 schema steps use the frozen prefix `Version1000Date`, so only the date
orders them:

| Era | Steps |
|---|---|
| 2022-11-18 | 1 (creates all 14 original tables, 1,451 lines) |
| 2023-02 to 2023-04 | 3 (all repairs of the 2022 one) |
| 2026-06-11 | 1 (drops 14 legacy `social_3_*` tables) |
| 2026-09-07 onward | 28 |

There are numbering gaps at `20260911000003`, `000012` and `000015`–`000019`,
and most steps were authored in a six-day window.

Two squash candidates are clean: the 2022 creation plus its three 2023 repairs
(1,654 lines, two of them chasing the same `social_cache_actor` primary-key
defect), and `Version1000Date20260908000001`, which widens a column introduced
one day earlier in the same burst.

`Version1000Date20260611000001` drops fourteen `social_3_*` tables that **no
other file in the tree ever creates** — the creating migrations were deleted and
only the drop survives, so it is dead weight on every fresh install. The legacy
naming split it addresses is finished: there is no `social_a2_*` prefix
anywhere, and all 32 current tables are both read and written.

**Squashing is no longer blocked, and is still not done.** The stated blocker was
that the 2022–2023 era had no test coverage, so a mistake would surface as a
failed `occ upgrade` rather than a red build; `tests/Migration/InitialSchemaTest`
and `SchemaRepairs2023Test` now record what those four steps produce between
them, which is exactly what a squash has to preserve. What remains is not a
coverage problem: squashing rewrites the upgrade path for every existing
instance, and that is a release decision.

### Two columns written and never read

`social_stream_dest.id` and `social_stream_tag.id` are autoincrement surrogate
keys. The new tests record why they exist: both tables shipped in 2022 with no
primary key at all, only a unique index over the columns that identify a row.
Neither `id` appears in the column lists in `CoreRequestBuilder` nor in the
select lists of their request builders. These are the two highest-insert-rate
side tables in the app, and every row pays for a sequence and an index write
nothing reads. The keys are still worth having — this is a note on the cost.

---

## Frontend

**Still Options API.** There are `setup()` blocks now, because the composables
need them, but no `<script setup>` and no wholesale move to composition. That is
a style question rather than debt: the components are consistent with each other.

**JSDoc typedefs that nothing checks.** `src/types/ActivityPub.js` and
`src/types/Mastodon.js` (237 lines) are imported as types by 22 files — up from
ten a week ago, so the habit is spreading — but there is no root `tsconfig.json`
and no `checkJs`, so nothing validates them against the real API shapes. Turning
on `checkJs` for `src/types/` and its importers is the smallest useful step.

**Three ESLint rules are switched off**, and `eslint.config.mjs` says why next to
each. Sorting imports (247 reports) detaches the comments that explain the
side-effect imports; renaming components (8 reports) changes what templates say.
Neither is formatting, which is why they were not taken with the rest.

---

## Toolchain

| Tool | Pinned | Status |
|---|---|---|
| `nextcloud/ocp` | `dev-stable35` | Matches the declared minimum, so analysis checks this app against the oldest server it claims to support. |
| PHPUnit | `^11.5` | Current major minus one. See below. |
| Psalm | `^6.17` | Current, running on supported PHP, no suppressions for the app's own code. |
| ESLint | 10.10.0 with `@nextcloud/eslint-config` 9 | Current, flat config, three rules deliberately off. |
| Stylelint | `^17.15` | Current. |
| Vitest / jsdom | 5.0 / 30.0 | Current. |
| webpack / vue-router / vite | 5.110 / 5.3 / 8.3 | Current. |
| `node-polyfill-webpack-plugin` | exactly `4.0.0` | **Blocked upstream.** `@nextcloud/webpack-vue-config` 6.3.2 peer-pins it; 4.1.0 needs a release there first. |

**PHPUnit 12 is the one version behind, and the gap is measured.** Under 12 this
suite reports about 3,100 notices — "no expectations were configured for the
mock object, consider a test stub" — which is a suite-wide `createMock` to
`createStub` migration, and it needs roughly 2 GB to run where 11 needs 90 MB.
Nothing in `tests/` blocks it otherwise: providers are static, metadata is
attributes rather than annotations, and the `onConsecutiveCalls()` calls 12
removes are gone. It is a day of mechanical work with no correctness payoff,
which is why it is last.

`terser-webpack-plugin` is a direct devDependency because webpack 5.110 stopped
hoisting it where `@nextcloud/webpack-vue-config` expects to find it. Worth
knowing that the build depends on the shared webpack config's internal layout in
more places than that one.

---

## Remaining odds and ends

**32 `@deprecated` markers in `lib/`, 20 of them on methods with live callers**,
led by `Request::getUrl` (55 callers, deprecated in favour of `getPath()` by
toolkit version 19), `CoreRequestBuilder::leftJoinStreamAction` (17) and
`limitToId` (12). Most of them are in the two places this report keeps pointing
at: the request model and `CoreRequestBuilder`. A marker with fifty-five callers
is not a plan; it is a note that a plan was intended.

**`CoreRequestBuilder` still reimplements ten helpers** that also exist on
`ExtendedQueryBuilder` — one copy takes `IQueryBuilder &$qb` by reference, the
other is a method on the builder — and
`CoreRequestBuilder::leftJoinCacheActors()` and
`SocialCrossQueryBuilder::leftJoinCacheActor()` are two implementations of one
join. Now that the builder is a plain object over `IQueryBuilder`, collapsing
the two is a mechanical change; it was not one while the builder was also a
private core class.

**Constructor promotion is at 150 of 206 (73 %).** The remaining 56 are the ones
an automated pass should not touch: constructors that do real work in the body,
that forward a parameter to a parent as well as storing it, or whose property
name differs from the parameter.

---

## Translations

The frontend contains 295 distinct translatable strings. The best-covered
locales (`de`, `de_DE`, `en_GB`) carry 135. The median locale across 98
languages carries 21, and 47 carry fewer than 20. The strings `Quote`,
`Bookmark`, `Scheduled`, `Announcement`, `Follow requests` and `Photos` appear
in **zero** translation files.

The cause is diagnosed in the header of `.github/workflows/l10n.yml`: nothing in
the repository ever extracted the source strings, so nothing was ever pushed for
translation. `.tx/config` is correct as written — `translationfiles/` is what
the extractor produces, not something the repository holds. The workflow now
exists; the catalogue has not caught up, and that is a Transifex round trip
rather than a code change.

---

## What is not debt

Each item here is a search that does not need repeating.

**Classes from outside `OCP\`: none.** No `OC\DB\QueryBuilder\QueryBuilder`, no
`\OC::$server`, no `OC\SystemConfig`, no `Doctrine\DBAL\Query\QueryBuilder`. The
only matches are docblock sentences explaining what was removed.

**Untyped properties: none.** All 35 were typed, including six mutable public
statics in `TArrayTools` that were constants in everything but name.

**Deprecated server APIs: none.** `IConfig`'s app-value methods (deprecated in
29) and user-value methods (deprecated in 33) are gone in favour of `IAppConfig`
and `IUserConfig`; `IConfig` is used only for system values, which are not
deprecated.

**Vue 2 leftovers: none.** `Vue.prototype`, `new Vue(`, `$listeners`,
`$set`/`$delete`, `.sync`, `filters:`, `beforeDestroy`, `functional: true` and
`slot-scope` all return zero hits in `src/`. The only occurrences anywhere are
the regexes in `tests/js/vue3.test.js`, which holds each at zero. Vuex is gone
too.

**PHPDoc route annotations: none.** Access control is 100 % PHP 8 attributes,
with a regression test asserting the old form is not reintroduced.

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

**Unlinted code: none.** `npm run lint` covers `src`, `tests/js`, the hand-written
`js/social-adminSettings.js` and the root configuration; `npm run stylelint`
covers `src` and `css`.

**Dead frontend code: none.** The import graph over all 81 files in `src/`
resolves, and there are no unused exports.

**Dead model classes: none** (70 checked). **Dead exception classes: none** (46
checked). **Unused controllers: none** (21 checked).

**Repair steps that re-scan on every upgrade: none.** All four carry a version
marker in app config and return early.

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

### In [#2127](https://github.com/nextcloud/social/pull/2127)

| Item | Outcome |
|---|---|
| Source maps in the release tarball | Excluded. The tarball was ~22 MB of maps against 6.7 MB of bundles. |
| Three disagreeing packaging lists | One list. `build-package.sh` calls `make appstore`; `.nextcloudignore` deleted. |
| The `elliptic` stub | Replaced with an `overrides` pin on the real `^6.6.1`. The stub claimed a version that has never existed on the registry. |
| Cypress husk | Deleted: 8 files, 1 config, 4 npm scripts, 6 packages, 0 tests. |
| Unused npm dependencies | `ical.js`, `uuid`, `cypress-wait-until`, `@nextcloud/cypress` dropped; `buffer` and `webpack-dev-server` declared. |
| Dead code | 3 files and 20 methods with no caller anywhere, 4 dead config constants, an 80-line commented-out block, 4 obsolete Psalm suppressions. |
| Repair steps re-scanning on every upgrade | Markers added; `RenameDocumentLocalCopy` unregistered and deleted. |
| Missing indexes | `social_actor.user_id` and the four unindexed `trend_*` windows. |
| Status-id collisions | Width 1e6 -> 1e9, `rand()` -> `random_int()`, and a collision is retried instead of silently dropping the post. |
| `IInitialStateService` | Migrated to `IInitialState` in four controllers. |
| `Cron\Queue` | Given the wall-clock budget `QueueController` already had. |
| The `AP` static registry | Resolved lazily instead of by an `AP::init();` at file scope. |
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
| ESLint 8 (end of life) | [#2129](https://github.com/nextcloud/social/pull/2129): ESLint 10, `@nextcloud/eslint-config` 9, flat config. |
| Nine conflicting dependabot bumps | [#2130](https://github.com/nextcloud/social/pull/2130): vue 3.5.42, webpack 5.110, jsdom 30, vue-router 5 with vite 8 behind it, dialogs 7.5. |

### In this wave

| Item | Outcome |
|---|---|
| **The five post fields with no column** | `tag`, `language`, `updated`, `quote` and `quoteAuthorization` are columns on `social_stream`, written where a row is written and read with a per-field fallback to the stored JSON, with a repair step backfilling existing rows behind a version marker. `language` is indexed, which is what a language filter needs. |
| **The private core class under the whole data layer** | `ExtendedQueryBuilder` holds a builder from `IDBConnection::getQueryBuilder()` and delegates all 60 `IQueryBuilder` methods instead of extending `OC\DB\QueryBuilder\QueryBuilder`. The `\OC::$server` reach that fed its private parent is gone, and so are the four `Doctrine\DBAL\Query\QueryBuilder` imports that existed only to read four constants. Nextcloud 35 had already broken the old arrangement by adding `forUpdate()` to the interface. |
| **The support floors** | PHP 8.1 -> 8.3, Nextcloud 28 -> 35, with `composer.json`'s platform pin and constraint following. |
| `nextcloud/ocp` two years stale | `dev-master` at a 2024-10-23 commit -> `dev-stable35`. |
| `#[\Override]` unavailable | 343 methods carry it; the psalm suppression that named the 8.1 floor is gone. |
| `IConfig` deprecated methods | `IAppConfig` and `IUserConfig` in five files. |
| PHPUnit 9 (end of life) | 11.5, via 10. All 112 data providers static, 127 annotations to attributes, 11 `withConsecutive()` sites rewritten, config migrated. |
| Test doubles drifting from the interfaces they double | `FakeConnection` completed; ten hand-rolled anonymous table classes replaced by one `FakeTable`; the test container serves an anonymous session because `Response` resolves one on every render from 35 onward. |
| Vuex 4, no Pinia | Five Pinia stores, `mapStores` at the call sites, `vuex` removed. |
| Three mixins, four spellings of one import | Three composables in `src/composables/`. |
| Dead pre-Nextcloud-25 profile branch | Deleted. |
| `OC.Notification.showTemporary` | `@nextcloud/dialogs`, imported lazily so the Vue-free admin bundle stays 28 KiB rather than 761 KiB. |
| Nineteen deferred ESLint rules | Sixteen adopted, ~1,100 reports fixed. Three stay off with the reason next to each. |
| Unlinted code | The hand-written admin script and the root configuration are linted; `css/` is stylelinted. |
| 35 untyped properties | Zero. Six mutable public statics became constants. |
| The 2022–2023 migrations, untested | `InitialSchemaTest` and `SchemaRepairs2023Test`. |
| Private core classes elsewhere in lib/ | `OC\DB\Connection` (a dead import), `OC\DB\SchemaWrapper` (two `occ social:reset` paths, now `IDBConnection::tableExists()`/`dropTable()`), `Doctrine\DBAL\Schema\SchemaException` (the OCP one exists) and `OC\User\NoUserException` (thrown by this app at this app, now its own). Six analysis suppressions went with them. |
| Two real defects found on the way | An instance's `local` flag was bound as a string because a parameter type was passed to the wrong function; `Profile.vue`'s "User not found" panel could never appear because one query was being asked twice under two names. |

---

## Rough order of value for what is left

1. Replace `Tools\Model\Request` and `NCRequest` with `IClientService`, with
   interop testing behind it.
2. Take the twenty occ commands off `OC\Core\Command\Base`, once what
   `--output=json` prints is settled.
3. Collapse the ten helpers `CoreRequestBuilder` and `ExtendedQueryBuilder` both
   implement, and the two implementations of the cache-actor join.
4. Retire the 18 uncalled Custom Local API routes on a deprecation cycle, and
   the five `getStream*()` / `getTimeline*_dep()` methods with them.
5. Finish the l10n round trip.
6. `checkJs` for the JSDoc typedefs, so the 22 files importing them are checked
   against the API shapes.
7. PHPUnit 12, which means `createMock` -> `createStub` across the suite.
8. Squash the 2022–2023 migration block, if the upgrade-path rewrite is
   acceptable.
9. Routes as attributes, and `DocumentationTest` rewritten to read them.

---

## Reproducing the measurements

```bash
# The support floors, and the places that should agree with them
grep -oE '(php|nextcloud) min-version="[^"]+" max-version="[^"]+"' appinfo/info.xml
grep -nE '"php": |platform' composer.json | head -3
grep -rn 'php min-version\|nextcloud-version-matrix' .github/workflows/*.yml | wc -l

# Sizes
find lib -name '*.php' | wc -l && find lib -name '*.php' -exec cat {} + | wc -l
find src -type f \( -name '*.js' -o -name '*.vue' \) | wc -l

# Classes from outside OCP\ (expect only docblock prose)
grep -rn 'OC\\DB\\QueryBuilder\\QueryBuilder\|OC::\$server\|OC\\SystemConfig\|Doctrine\\DBAL\\Query' lib --include='*.php'

# Untyped properties (expect 0)
grep -rnE '^\s*(var|private|protected|public|static|readonly)(\s+(static|readonly))*\s+\$\w+' lib --include='*.php' | wc -l

# Deprecated server config APIs (expect 0)
grep -rn 'config->getAppValue\|config->setAppValue\|config->getUserValue\|config->setUserValue' lib --include='*.php' | wc -l

# @deprecated methods that still have callers
grep -rn '@deprecated' lib --include='*.php' | wc -l
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

# Data providers that are not static (expect 0)
grep -rnE 'public function \w+Provider\(' tests | wc -l

# ESLint rules this codebase does not adopt
grep -c "': 'off'" eslint.config.mjs

# Translation coverage
grep -rhoE "\b[tn]\('social',\s*'[^']*'" src | sort -u | wc -l
python3 -c "import json,glob; print(sorted((len(json.load(open(f))['translations']), f) for f in glob.glob('l10n/*.json'))[-3:])"

# Suites
composer test:unit && composer psalm && composer lint \
  && vendor/bin/php-cs-fixer fix --dry-run \
  && npm run lint && npm run stylelint && npm test && npm run build
```
