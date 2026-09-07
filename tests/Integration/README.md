<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration tests (run against a real Nextcloud + database)

Unlike the unit suite (`tests/`), which stubs OCP and needs no server, these
tests resolve real services from the container and hit the actual database, so
they exercise the migration, the query SQL and the unique constraints that mocks
cannot reach:

- `Db/ActorRelationRequestTest` — block/mute storage: the migration ran, prim
  hashing, the unique index, and every query the feature relies on.
- `Db/StreamFilterTest` — runs the real timeline queries with a viewer and
  block/mute rows present, so `SocialLimitsQueryBuilder::filterHiddenActors()`
  actually joins; asserts the anti-join is valid SQL at each hidden-actor level.

## Status

These are **not yet wired into CI**. Wiring them means adding a `test:integration`
script to `composer.json` (the `phpunit-*` workflows run it only when that script
exists) whose bootstrap boots the server. A first attempt booting via
`require lib/base.php` tripped `OC\Config`'s "leading content" check in the CI
environment; the bootstrap needs validating against a live instance before it is
re-enabled, so the feature is not blocked on it. Run locally against a dev
instance with:

    vendor/bin/phpunit -c tests/Integration/phpunit.xml
