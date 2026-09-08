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
- 📌 **Pinned posts** — pin up to five of your own posts to the top of your profile (`pin`/`unpin` on the status-action endpoint, `?pinned=true` on the account statuses route). Pins are published in the actor's `featured` collection, so other Fediverse servers show them too; pinned posts of *remote* accounts are not fetched.
- 🖼️ **Profiles** — avatar, uploadable banner/header image, a profile description (`note`) and up to four editable **profile metadata fields** (the name/value table under the bio), federated as `PropertyValue` attachments on the actor and shown for remote accounts too.
- 🌐 **Federation** — signed HTTP delivery of `Create`, `Update`, `Delete`, `Like`, `Announce`, `Follow`, `Accept` and `Undo` activities, an outbound request queue and a stream queue for resolving incoming objects, both drained by background jobs and by `occ social:queue:process`.
- 🔔 **Live timelines with notify_push** — when the [notify_push](https://github.com/nextcloud/notify_push) app is installed, new timeline entries reach open web clients as push events and polling drops to a five-minute safety net; without it the client polls every 30 seconds.
- 🔎 **Discovery & search** — WebFinger lookups, remote actor resolution and caching, search over known accounts and hashtags, and **full-text search of visible posts** through Nextcloud's unified search (own posts, public content and messages addressed to you; a plain database substring match, no external search engine needed).
- 🔗 **Link previews** — a post that links somewhere gets a preview card (OpenGraph, with the plain title/description as fallback) read by a background job, so nothing waits for a stranger's web server. The fetch is HTTP(S)-only on every hop, refuses local addresses, is size- and time-capped and obeys the instance access list. Cards are never federated — like Mastodon, every instance reads the page itself.
- 🧹 **Retention** — remote statuses older than `retention_days` (default: disabled) that no local user interacted with are pruned together with their cached attachments, from cron or `occ social:stream:prune`; local content is never touched. Configurable in the Social section of the administration settings.
- 🛡️ **Instance access list** — an allow-list or deny-list of remote hosts, enforced on incoming activities and outgoing requests. Managed with `occ social:fediverse`; see [docs/OCC-Commands.md](docs/OCC-Commands.md) for the details and its limits.
- 🔑 **Mastodon-compatible API** — the Mastodon client API's core surface plus OAuth 2 authorization: third-party clients can log in, read every timeline, post (with media and polls), follow/unfollow, favourite/boost/bookmark, search (`/api/v2/search`), manage follow requests and report. No streaming endpoint or push subscriptions — clients poll. See [docs/API.md](docs/API.md) for exactly which routes exist.

### 🚧 Not implemented yet

These are absent from the code today, not merely rough edges:

- **Blocking and muting** are supported: block an account to sever the relationship in both directions and hide it everywhere (federated as a `Block` activity unless `occ config:app:set social federate_blocks --value 0`); mute an account to hide it from your timelines — and optionally notifications — without it ever knowing. Exposed over the Mastodon API (`/api/v1/accounts/{id}/block|unblock|mute|unmute`, `/api/v1/blocks`, `/api/v1/mutes`).

- **Reporting** is supported: `POST /api/v1/reports` files a report, incoming federated `Flag` activities are stored the same way, admins are notified and review reports (and manage the Fediverse access list) in the Social section of the administration settings. Reports are never forwarded to the reported account's instance.
- **Locked accounts / approvable follow requests** are supported: `PATCH /api/v1/accounts/update_credentials` with `locked` toggles `manuallyApprovesFollowers`; incoming follows towards a locked account stay pending (with a `follow_request` notification) until the owner authorizes or rejects them via `/api/v1/follow_requests` (`lib/Interfaces/Object/FollowInterface.php`).
- **Profile metadata fields** are supported: up to four name/value pairs, edited on your own profile page or through `PATCH /api/v1/accounts/update_credentials` with `fields_attributes`, stored on the local actor row, federated as `PropertyValue` attachments and read back from remote actors (`lib/Model/ActivityPub/Actor/Person.php`). They carry no link verification — `verified_at` is always `null`.
- **Polls**: incoming federated polls (`Question` objects) show their options, counts and expiry in every timeline and can be voted on — votes federate to the poll's author as ActivityPub vote notes, authoritative counts come back as `Update{Question}` (`lib/Service/PollService.php`). Creating own polls is not supported. **No bookmarks or lists.**
- **Media attachments** cover images (JPEG, PNG, GIF, WebP — images get a resized preview and a blurhash), video (MP4, WebM, QuickTime) and audio (MP3, MP4/AAC, OGG/Opus, WAV, FLAC); see `filterMimeTypes()` in `lib/Service/CacheDocumentService.php` for the exact list. Video and audio are stored as-is (no transcoding, no thumbnail — the player is the preview) and remote copies respect the `max_size` app setting (default 10 MB). No document/file attachments.
- **Custom emoji from other instances render** — `Emoji` tags on remote statuses and actors survive the cache (via the stored wire source) and are served in the `emojis` field of status and account entities; the web client shows them inline in content and display names. The instance has no custom emoji of its own: `/api/v1/custom_emojis` returns an empty list (`lib/Controller/ApiController.php`, `customEmojis()`).
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
