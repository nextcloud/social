# Technical debt and legacy code

What in this app is old, borrowed or load-bearing in a way nobody would choose
today, and what it would cost to change. Written for whoever has to decide
where refactoring effort goes.

**Verified against:** app version 0.15.1, 2026-09-12. Every number below was
measured on that tree rather than carried over.

Like [Performance.md](Performance.md), this file is **not** enforced by
`tests/DocumentationTest.php`: its claims are structural rather than checkable
against a route table, so nothing fails when it goes stale. Re-measure before
quoting it.

## The database layer is built on a private core class

`lib/Tools/` is a re-namespaced copy of the `daita/my-small-php-tools` library —
32 PHP files, vendored into the app rather than required through Composer, with
no upstream reference left in the tree. It is not dead weight: it is the
foundation of the data layer.

```
lib/Tools/Db/ExtendedQueryBuilder.php:16   use OC\DB\QueryBuilder\QueryBuilder;
lib/Tools/Db/ExtendedQueryBuilder.php:29   class ExtendedQueryBuilder extends QueryBuilder …
```

`OC\DB\QueryBuilder\QueryBuilder` is **server-internal**, not public API. All 44
classes in `lib/Db/` sit on that inheritance chain, and `QBMapper`/`Entity` —
the API the server actually offers apps — appear nowhere in `lib/`.

This is the largest breakage risk in the app. A refactor of the server's query
builder that respects its public contract can still break every database call
here, and the failure would arrive as a fatal on a Nextcloud upgrade rather than
as a deprecation notice. Moving to `QBMapper` is a large piece of work touching
every `*Request` class; the first step that is worth doing on its own is to stop
extending the private class — the app uses a handful of conveniences on top of
`IQueryBuilder`, and those can be a wrapper rather than a subclass.

## Age of the code

Lines of `lib/` by the year they were last touched, from `git blame`:

| Year | Lines | Share |
|------|-------|-------|
| 2018 | 8,702 | 11.8% |
| 2019 | 9,085 | 12.3% |
| 2020 | 4,150 | 5.6% |
| 2021 | 11 | 0.0% |
| 2022 | 7,264 | 9.8% |
| 2023 | 4,014 | 5.4% |
| 2024 | 1,122 | 1.5% |
| 2025 | 51 | 0.1% |
| 2026 | 39,627 | 53.5% |

**45% of `lib/` was last touched in 2023 or earlier**, and nearly a quarter of
it dates from the app's first two years. The 2026 half is the recent federation
and client-API work; it is disproportionately the part with tests.

## Dead code

Three files in `lib/Tools/` are referenced by nothing outside themselves —
`Db/RequestBuilder.php`, `Traits/TFileTools.php`, `Traits/TNCSetup.php`, 376
lines together. They can go today. Several more have one or two callers each and
would fold into their callers.

## The `elliptic` stub

`package.json` pins `"elliptic": "file:patches/elliptic-6.6.2.tgz"`, and that
tarball is a **15-line stub** that replaces the elliptic-curve library with
nothing:

```js
rand: function() { return Buffer.alloc(32) }
```

A random-number generator that returns 32 zero bytes, and `ec`/`eddsa`
constructors that do nothing. It is presented as a fix for a CVE in `elliptic`.

It is safe *today*, for reasons that are all accidents: `elliptic` is a
devDependency only, it is absent from every built bundle in `js/`, and the only
thing keeping it in the dependency tree at all is unused Cypress tooling. But
the stub is indistinguishable from a working library to anything that imports
it, and the day something in the front end does, it will produce keys that are
all zeroes rather than fail. Removing the Cypress dependency chain removes the
need for the stub; short of that, the stub should fail loudly instead of
returning a plausible value.

## Test coverage of migrations

33 migration classes, 17 test files under `tests/Migration/`. The untested ones
are mostly the 2022–2023 era — including the original schema — where a mistake
surfaces as a failed `occ upgrade` on somebody's instance. See
[Performance.md](Performance.md) for two specific hazards in that set (a
PostgreSQL `NOT NULL` column added without a default, and indexes left unnamed).

## Two jobs doing the same work under different rules

`QueueController` gives its drain a wall-clock budget (`MAX_DURATION`) and stops
when it runs out. `Cron\Queue`, which drains the same queues on every cron run,
has no budget at all: it stops when the batch is done or when something kills
it. The controller's caution was added because a request must return; the job
has the same problem with a longer fuse, and no reason for the difference.

## A trap worth knowing before you audit this app

Nextcloud controller methods are **admin-required by default** —
`SecurityMiddleware` enforces it unless the method carries `#[NoAdminRequired]`
or `#[PublicPage]`. The *absence* of an attribute is the guard, not a missing
one. Reading the routes as if the annotations grant access rather than relax it
produces a long list of false "unauthenticated endpoint" findings.
