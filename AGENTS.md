<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AGENTS.md

Aloha Social (app id `social`, namespace `OCA\Social`) is a Nextcloud app that gives every user a Fediverse identity: ActivityPub federation with Mastodon, PeerTube, Pixelfed and others, plus a native AT Protocol (Bluesky) stack. It also serves a Mastodon-compatible client API.

## Where the truth lives

- `docs/Architecture.md` is the single source of truth for the data model, routing, services, federation flows and the frontend. Read the relevant section before changing a subsystem, and update it in the same PR when behaviour changes.
- `docs/API.md` must list exactly the routes the app registers: `tests/DocumentationTest.php` compares them.
- `docs/Technical-Debt.md` records known deviations and legacy code. Add to it rather than silently working around something.
- `docs/Mastodon-Compatibility.md` and `docs/Atproto-Compatibility.md` record where the app deviates from those protocols.

## Commands

```sh
make dev-setup              # npm ci + composer install
npm run build               # production bundle into js/ (committed, see below)
npm run dev | npm run watch
npm run lint | npm run stylelint | npm run typecheck
npm test                    # vitest (jsdom): src/**/*.test.js and tests/js/**
npx vitest run tests/js/components/FirstRun.test.js        # one file
npx vitest run -t 'follows a whole starter pack'            # one test by name

composer run cs:check | composer run cs:fix | composer run psalm
composer run test:unit                                      # PHPUnit, no server needed
composer run test:unit -- --filter TranslatableStringsTest  # one test class or method
```

- The unit suite (`tests/phpunit.xml`) runs standalone: OCP comes from the `nextcloud/ocp` dev dependency and anything needing a booted server is mocked.
- `composer run test:integration` boots a real Nextcloud. The app must live at `apps/social` inside an installed server checkout, which is what the `phpunit-*` workflows set up.
- `composer run test:interop` and the `interop-*` workflows run against real peers (Mastodon, PeerTube, Pixelfed, Loops, Bluesky) in containers. `npm run test:e2e` runs Playwright against a running instance.

## Architecture

- **Backend layering:** `lib/Controller` → `lib/Service` (all business logic) → `lib/Db`. Database access goes through hand-written `*Request` / `*RequestBuilder` query classes, not QBMapper entities. ActivityPub objects are `lib/Model`, parsed by `ImportService` (`AP::getItemFromData()`) and handled per activity type in `lib/Interfaces`.
- **Federation:** outgoing activities are signed (`HttpSignatureService`, `SignatureService`) and queued for delivery; `lib/Cron` drains the delivery and inbound queues. Read "Delivering at scale" in the architecture doc before touching delivery.
- **AT Protocol:** `lib/Atproto` is a self-contained stack (Identity, Repository, Firehose, AppView, Xrpc, OAuth, Lexicon). Its primitives are pinned by the vectors in `tests/Atproto/fixtures`.
- **Frontend:** Vue 3 with `@nextcloud/vue` 9 and six Pinia stores in `src/store/`. Server state reaches the page as initial state (`serverData`, `currentAccount`) through composables in `src/composables/`. `webpack.common.js` defines several entry bundles that share `js/social-framework.js`; every `Util::addScript()` must load the framework first, except the self-contained `social-filesAction.js`. `tests/js/bundles.test.js` pins this.

## Conventions

- **`js/` is committed and is what ships.** After changing `src/`, rebuild with `npm run build` and commit `js/` with it, including deleted and renamed chunks. CI ("NPM build") rebuilds and fails on any byte difference. Build with Node 24 from an LF checkout: a Windows checkout with CRLF line endings produces different bytes. Source maps are gitignored.
- **Commits:** Conventional Commits with a scope, e.g. `feat(atproto): …`, `fix(social): …`, `chore(release): …`. Commit with `git commit -s` (DCO).
- **Licensing (REUSE):** every new file needs SPDX headers. Use `SPDX-FileCopyrightText: <year> Aloha Social contributors` with `SPDX-License-Identifier: AGPL-3.0-or-later`, except files in `.github/workflows/`, which are `MIT`. Leave existing headers as they are. Files that cannot carry a header get an entry in `REUSE.toml`.

## Translations

Translations are managed on Transifex at `alohasocial/social-3` (`.tx/config`). `l10n.yml` extracts the strings and pushes the source; `l10n-pull.yml` pulls translations back weekly as a PR. Never edit `l10n/` by hand: the next sync overwrites it. The extractor (Nextcloud's translationtool, i.e. xgettext) only sees strings written in a particular shape:

- Call `t('social', '…')` and `n('social', '…', '…', count)`. Import them as `import { translate as t, translatePlural as n } from '@nextcloud/l10n'`. Calls named `translate()` or `translatePlural()` are never extracted.
- In Vue templates, keep each `t()` / `n()` call on a single line. A multi-line template call is not extracted: move it into a computed property.
- The message must be a string literal, not a variable or template string with expressions.
- One singular has exactly one plural. Two `n()` calls with the same singular and different plurals collapse into one entry, and the other call loses its translation. The same message may not appear both as `t()` and as the singular of an `n()`.
- `tests/TranslatableStringsTest.php` checks part of this. When in doubt, extract locally (`php translationtool.phar create-pot-files`) and look for your string in `translationfiles/templates/social.pot`.

## Nextcloud Contribution Policy

All contributions generated or assisted by this agent must fully comply with:

- **[AI Contribution Policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md)** - the primary reference for AI-specific rules, covering disclosure, author accountability, communication, security, licensing, code quality, and autonomous agent behavior.
- **[Contribution Guidelines](https://github.com/nextcloud/.github/blob/master/CONTRIBUTING.md)** - covering testing requirements, the Developer Certificate of Origin (DCO), license headers, conventional commits, and translations. These apply in full to all contributions regardless of how they were produced.

### What this agent must always do

- Add an `Assisted-by: AGENT_NAME:MODEL_VERSION` git trailer to every commit containing AI-assisted content.
- Ensure every pull request includes a disclosure of AI tool use in the PR description.
- Produce focused, scoped pull requests that address exactly one concern. Do not touch unrelated files or introduce incidental refactors.
- Verify all dependencies against actual package registries before suggesting them. Do not use hallucinated or unverified package names.
- Write code comments that document the code, never the process that produced it:
  - Comments describe what the code does - method signatures, behavior, and constraints the code itself cannot express (e.g. a non-obvious invariant or workaround).
  - Never add comments that document progress, decisions, or changes (e.g. "changed X to Y", "as requested", "this fixes ...", "previously this did ..."). That belongs in the commit message or PR discussion; in the code it goes stale and becomes misleading.
  - Do not narrate self-explanatory code. If the code is readable without a comment, omit the comment.
  - Keep comments brief - short and simple, matching the comment density of the surrounding code.
- Reuse existing helper functions and utilities instead of re-implementing their logic inline. When fixing a flawed pattern, fix every occurrence of it across the changed code, not only the instance that was pointed out.
- Run permission and access-control checks before the operation they guard, never after it and never only in the UI layer.
- When adding or changing user-facing functionality, wire it up in every context where the affected component is used - the default authenticated view, public share pages, and embedded contexts such as the Smart Picker and reference widgets. When emitting new events, verify that every consumer of the component subscribes to and handles them.
- Explicitly inform the contributor when any action they are about to take, or have taken, would violate the AI Contribution Policy or the Contribution Guidelines. Do not silently proceed. State which rule is at risk and what the contributor should do instead.
- Warn the contributor if a pull request is growing too large. A PR approaching several thousand lines of changed code is a signal that it should be split into smaller, focused PRs. Suggest a logical split before the PR is opened, not after.
- Recommend opening a ticket for discussion before starting implementation whenever a feature or change is sufficiently complex - for example when it touches multiple subsystems, requires architectural decisions, or the right approach is not yet clear. A ticket allows maintainers and the contributor to align on direction before code is written, avoiding wasted effort on a PR that may be rejected or require fundamental rework.

### What this agent must never do

- Open issues, submit pull requests, post review comments, or send security reports autonomously. Every contribution must be reviewed and submitted by a human.
- Add `Signed-off-by` tags to commits. Only the human contributor can certify the Developer Certificate of Origin.
- Generate or submit security reports without independent human verification. Report verified vulnerabilities via [HackerOne](https://hackerone.com/nextcloud), not as GitHub issues.
- Write PR descriptions, review comments, or issue reports on behalf of the contributor. These must be in the contributor's own words.
- Fully automate the resolution of issues labeled [`good first issue`](https://github.com/issues?q=org%3Anextcloud+label%3A%22good+first+issue%22) or similar beginner-friendly labels.
- Submit code that has not been reviewed and cleaned up by the contributor. Dead code, redundant logic, excessive comments, malformed or garbled characters (e.g. `�` replacement characters), and unrelated changes must be removed before submission.
