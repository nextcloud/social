# Nextcloud Social 🚀✨

Nextcloud Social is an ActivityPub app that connects your Nextcloud account to the Fediverse. Your instance acts as a lightweight federated social server: each user gets a `Person` actor, can write and edit posts, follow accounts on other servers, and like, boost and reply to what they receive.

![Screenshot](img/screenshot.png)

It is a partial implementation of ActivityPub and of the Mastodon client API — enough for posting, following and reading timelines, but far from feature parity with Mastodon. See "Not implemented yet" below before deploying it as someone's only Fediverse client.

## 🔧 Features

- 🧭 **Timelines** — Home, Local, Global (federated), Direct messages, Liked posts, Notifications, per-account and per-hashtag timelines.
- ✍️ **Composer** — write posts and replies, pick a visibility (public, unlisted, followers-only, direct), insert emoji, and attach images. `@mentions` and `#hashtags` typed by hand are extracted from the text and turned into real recipients and tags.
- ✏️ **Edit posts** — edit your own local posts inline; the change is saved and federated as an ActivityPub `Update` (`lib/Service/PostService.php`, `editPost()`).
- 🗑️ **Delete posts** — delete your own posts.
- 👍 🔁 💬 **Post actions** — like/unlike, boost/unboost (`Announce`) and reply.
- 👥 **Following** — follow and unfollow local and remote accounts, and browse followers/following lists.
- 🖼️ **Profiles** — avatar, uploadable banner/header image and a profile description (`note`). Editable structured profile fields are *not* supported (see below).
- 🌐 **Federation** — signed HTTP delivery of `Create`, `Update`, `Delete`, `Like`, `Announce`, `Follow`, `Accept` and `Undo` activities, an outbound request queue and a stream queue for resolving incoming objects, both drained by background jobs and by `occ social:queue:process`.
- 🔔 **Live timelines with notify_push** — when the [notify_push](https://github.com/nextcloud/notify_push) app is installed, new timeline entries reach open web clients as push events and polling drops to a five-minute safety net; without it the client polls every 30 seconds.
- 🔎 **Discovery & search** — WebFinger lookups, remote actor resolution and caching, and search over known accounts and hashtags.
- 🧹 **Retention** — remote statuses older than `retention_days` (default: disabled) that no local user interacted with are pruned together with their cached attachments, from cron or `occ social:stream:prune`; local content is never touched. Configurable in the Social section of the administration settings.
- 🛡️ **Instance access list** — an allow-list or deny-list of remote hosts, enforced on incoming activities and outgoing requests. Managed with `occ social:fediverse`; see [docs/OCC-Commands.md](docs/OCC-Commands.md) for the details and its limits.
- 🔑 **Mastodon-compatible API** — a subset of the Mastodon client API plus OAuth 2 authorization, so some third-party clients can talk to the app. See [docs/API.md](docs/API.md) for exactly which routes exist.

### 🚧 Not implemented yet

These are absent from the code today, not merely rough edges:

- **Blocking and muting** are supported: block an account to sever the relationship in both directions and hide it everywhere (federated as a `Block` activity unless `occ config:app:set social federate_blocks --value 0`); mute an account to hide it from your timelines — and optionally notifications — without it ever knowing. Exposed over the Mastodon API (`/api/v1/accounts/{id}/block|unblock|mute|unmute`, `/api/v1/blocks`, `/api/v1/mutes`).

- **Reporting** is supported: `POST /api/v1/reports` files a report, incoming federated `Flag` activities are stored the same way, admins are notified and review reports (and manage the Fediverse access list) in the Social section of the administration settings. Reports are never forwarded to the reported account's instance. `pin`/`unpin` are still accepted by the status-action endpoint and silently ignored (`lib/Service/ActionService.php`).
- **Locked accounts / approvable follow requests** are supported: `PATCH /api/v1/accounts/update_credentials` with `locked` toggles `manuallyApprovesFollowers`; incoming follows towards a locked account stay pending (with a `follow_request` notification) until the owner authorizes or rejects them via `/api/v1/follow_requests` (`lib/Interfaces/Object/FollowInterface.php`).
- **No profile metadata fields.** `fields` is always exported as an empty array (`lib/Model/ActivityPub/Actor/Person.php`), so the profile "Website" row never appears and there is nothing to edit.
- **No polls, bookmarks or lists.** The Mastodon type definitions in `src/types/Mastodon.js` mention polls, but no poll, bookmark or list feature exists on either side.
- **Images only for attachments.** The file picker is `accept="image/*"` (`src/components/Composer/Composer.vue:10`) and the server only keeps `image/jpeg`, `image/gif` and `image/png` (`lib/Service/CacheDocumentService.php`, `filterMimeTypes()`). No video, audio or document attachments.
- **No link previews** and no custom emoji: `/api/v1/custom_emojis` returns an empty list (`lib/Controller/ApiController.php`, `customEmojis()`).
- **No status translation.** The `translate` action returns the post unchanged (`lib/Service/ActionService.php`).
- **Media uploads** go through `POST /api/v2/media` (or v1) in `lib/Controller/ApiController.php`: jpeg/gif/png, with alt text via `description`, editable with `PUT /api/v1/media/{id}` and attachable to statuses via `media_ids`.

## 📦 Quickstart (install & develop)

1. Clone this repository into your Nextcloud `apps/` directory.
2. Install the dependencies and build the frontend:

```bash
cd /var/www/nextcloud/apps/social
composer install     # PHP dependencies
npm ci
npm run build        # production bundle into js/
```

3. Enable the app in Nextcloud (`occ app:enable social`) and check the install with
   `occ social:check:install`.
4. While working on the UI, use `npm run dev` for a development build or
   `npm run watch` to rebuild on change. The `Makefile` wraps the same scripts
   (`make build-js`, `make build-js-production`, `make watch-js`, `make lint`).
5. To produce a release archive, run `./build-package.sh`. It runs
   `composer install --no-dev`, `npm run build`, copies the app without the dev
   files and writes `build/artifacts/social.tar.gz`. `make appstore` builds the same
   archive through the Makefile; despite the `sign_dir` name it only stages and tars,
   it does not sign anything.

## 🖼️ Banner / Header upload — Troubleshooting

Banner/header uploads work: the image is stored in the app's document cache, the
local actor's cached `header` is updated, and the change is federated as an actor
`Update` (`lib/Controller/LocalController.php`, `uploadBanner()`). A banner can also
be set from a URL. Only JPEG, GIF and PNG survive the mime filter.

If an uploaded banner does not appear immediately:

- Reload the profile page (clear the browser cache if necessary).
- Check the server log for `[LocalController] uploadBanner failed` and for
  `Failed to federate banner change`, which is only a warning and does not undo the
  local upload.
- Re-fetch the account data, or run `occ social:cache:refresh`, to refresh the cached
  actor.

## ✅ Tests

Backend and frontend unit tests run without a Nextcloud server or database.

```bash
composer install            # PHPUnit + OCP interface stubs
composer run test:unit      # PHP: vendor/bin/phpunit -c tests/phpunit.xml

npm ci
npm test                    # JS: vitest run (tests/js/** and src/**/*.test.js)
npm run test:coverage       # with a coverage report in coverage/js
```

PHP tests live in `tests/` mirroring `lib/` (`lib/Service/PostService.php` →
`tests/Service/PostServiceTest.php`). Everything a class needs is mocked; code that
reaches the container statically finds a `TestContainer` behind `\OC::$server`
(see `tests/Helper/TestContainer.php`). Frontend tests use Vitest with
`@vue/test-utils` and jsdom; `tests/js/setup.js` provides the Nextcloud globals
(`t`, `n`, `OC`, `OCA`, `localStorage`, router webroots).

A second PHP suite in `tests/Integration/` runs against a real Nextcloud and
database — migrations, query SQL and storage boundaries the mocks cannot reach.
CI runs it on SQLite, MySQL and PostgreSQL; locally, point it at an installed
server:

```bash
NEXTCLOUD_ROOT=/path/to/nextcloud composer run test:integration
```

See `tests/Integration/README.md` for what it covers.

## 🛠️ Contributing

- Contributions welcome — open a pull request and run the build and tests locally
  first (`npm run lint`, `npm test`, `composer run test:unit`).
- Reset local Social data for development with:

```bash
occ social:reset
```

  This prompts twice and then empties every Social table. `occ social:reset
  --uninstall` additionally drops the tables, migrations, background jobs and app
  config. See [docs/OCC-Commands.md](docs/OCC-Commands.md) for all commands.

## License

See the repository's license files in the `LICENSES/` directory.
