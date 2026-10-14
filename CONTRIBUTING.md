<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Contributing to Aloha Social

Thank you for considering it. The short version:

- **Read [AGENTS.md](AGENTS.md) first.** It is written for coding agents and
  for people alike: how the app is built, where things live, the commit
  format, the licence headers, how translations are extracted, and the rules
  for AI-assisted contributions.
- **Security problems go to HackerOne**, never to the issue tracker — see
  [SECURITY.md](SECURITY.md).
- **Sign off every commit** (`git commit -s`). The
  [Developer Certificate of Origin](https://developercertificate.org/) is how
  you certify that you may contribute the change under the AGPL-3.0-or-later
  licence; only you can add that line. A commit written with the help of an AI
  tool also carries an `Assisted-by:` trailer (AGENTS.md says how).
- **Conventional commits** with a scope: `fix(federation): …`,
  `feat(atproto): …`, `docs(admin): …`. One concern per pull request.
- **Tests come with the change.** A fix comes with a test that fails without
  it; the unit suite is `composer run test:unit`, the integration suite needs
  a Nextcloud (`tests/Integration/README.md`), and `src/` changes are tested
  with `npm test`. `tests/DocumentationTest.php` keeps `docs/` in step with
  the code, so a new command, route, table, job or configuration key needs
  its row in the documentation in the same pull request.
- **Before pushing:** `composer run psalm`, `vendor/bin/php-cs-fixer fix`,
  `npm run lint`, `npm run typecheck`. CI runs the same checks, plus the
  browser tests and, on a pull request labelled `full-ci`, the whole matrix.
- **`js/` is committed** and rebuilt from `src/` with `npm run build`; commit
  the rebuilt bundle with the source change, or CI fails on the difference.
- **Releases** are described in [docs/Releasing.md](docs/Releasing.md).

Bugs and feature requests go in the
[issue tracker](https://github.com/AlohaSocial/social/issues); the templates
there ask for what a reproduction needs. For a change that touches several
subsystems or needs a design decision, open an issue to talk it through
before writing the code.
