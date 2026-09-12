# Nextcloud Social 🚀✨

Nextcloud Social is an ActivityPub app that connects your Nextcloud account to the Fediverse. Your instance acts as a lightweight federated social server: each user gets a `Person` actor, can write and edit posts, follow accounts on other servers, and like, boost and reply to what they receive.

![Screenshot](img/screenshot.png)

It is a partial implementation of ActivityPub and of the Mastodon client API — enough for posting, following and reading timelines, but far from feature parity with Mastodon. See "Not implemented yet" below before deploying it as someone's only Fediverse client.

## 🔧 Features

- 🧭 **Timelines** — Home, Local, Global (federated), Direct messages, Liked posts, Notifications, per-account and per-hashtag timelines.
- ✍️ **Composer** — write posts and replies, pick a visibility (public, unlisted, followers-only, direct), insert emoji, and attach images. `@mentions` and `#hashtags` typed by hand are extracted from the text and turned into real recipients and tags.
- 📊 **Polls** — write one in the composer (up to four options, single or multiple choice, 30 minutes to a week), and view and vote on federated ones. Votes travel to the poll's author as ActivityPub vote notes, incoming votes are counted, and the new totals federate back as `Update{Question}` (`lib/Service/PollService.php`).
- 🖼️ **Media attachments** — images (JPEG, PNG, GIF, WebP — each gets a resized preview and a blurhash), video (MP4, WebM, QuickTime) and audio (MP3, MP4/AAC, OGG/Opus, WAV, FLAC); `filterMimeTypes()` in `lib/Service/CacheDocumentService.php` is the exact list. Video and audio are stored as-is (no transcoding, no thumbnail — the player is the preview), and remote copies respect the `max_size` app setting (default 10 MB). Clients upload through `POST /api/v2/media` (or v1), with alt text via `description`, editable with `PUT /api/v1/media/{id}` and attached to a status with `media_ids`.
- 😀 **Custom emoji from other instances** — `Emoji` tags on remote statuses and actors survive the cache (via the stored wire source) and are served in the `emojis` field of status and account entities; the web client shows them inline in content and display names.
- ✏️ **Edit posts** — edit your own local posts inline; the change is saved and federated as an ActivityPub `Update` (`lib/Service/PostService.php`, `editPost()`).
- 🗑️ **Delete posts** — delete your own posts.
- ⚠️ **Content warnings** — put a warning on a post in the composer and the body is folded away behind it until a reader asks to see it (it is not even in the page until then). Carried as the ActivityPub object's `summary` and as `spoiler_text` on the client API, so warnings written elsewhere in the Fediverse are honoured here and vice versa.
- 👍 🔁 💬 **Post actions** — like/unlike, boost/unboost (`Announce`) and reply.
- 👥 **Following** — follow and unfollow local and remote accounts, and browse followers/following lists.
- 📌 **Pinned posts** — pin up to five of your own **public or unlisted** posts to the top of your profile (`pin`/`unpin` on the status-action endpoint, `?pinned=true` on the account statuses route). Pins are published in the actor's `featured` collection, which anyone on the internet may read, so nothing with a narrower audience can go into it — a followers-only or direct post is refused. Pins of *remote* accounts arrive as `Add`/`Remove` activities; their `featured` collection is never fetched.
- 🖼️ **Profiles** — avatar, uploadable banner/header image, a profile description (`note`) and up to four editable **profile metadata fields** (the name/value table under the bio), federated as `PropertyValue` attachments on the actor and shown for remote accounts too.
- 🌐 **Federation** — signed HTTP delivery of `Create`, `Update`, `Delete`, `Like`, `Announce`, `Follow`, `Accept` and `Undo` activities, an outbound request queue and a stream queue for resolving incoming objects, both drained by background jobs and by `occ social:queue:process`.
- 🔁 **Inbox forwarding** — a reply to one of your posts that arrives from a stranger's instance is passed on to your followers, so everyone reading the thread sees the same one. Forwarded untouched and only when the reply carries its author's linked-data signature, so the servers receiving it verify the original author rather than trusting this one; private posts and their replies are never fanned out.
- 🔔 **Live timelines with notify_push** — when the [notify_push](https://github.com/nextcloud/notify_push) app is installed, new timeline entries reach open web clients as push events and polling drops to a five-minute safety net; without it the client polls every 30 seconds.
- 🔎 **Discovery & search** — WebFinger lookups, remote actor resolution and caching, search over known accounts and hashtags, and **full-text search of visible posts** through Nextcloud's unified search (own posts, public content and messages addressed to you; a plain database substring match, no external search engine needed).
- 📈 **Trending hashtags** — the tags used most on the instance, counted per window (1 h to 10 days) by the same cron job that maintains the hashtag index, listed in the app's sidebar and served as Mastodon `Tag` entities at `/api/v1/trends/tags`.
- 🔖 **Bookmarks** — bookmark any post from its menu and read them back under Bookmarks in the sidebar. Purely local, never federated.
- 🔗 **Link previews** — a post that links somewhere gets a preview card (OpenGraph, with the plain title/description as fallback) read by a background job, so nothing waits for a stranger's web server. The fetch is HTTP(S)-only on every hop, refuses local addresses, is size- and time-capped and obeys the instance access list. Cards are never federated — like Mastodon, every instance reads the page itself.
- 🧹 **Retention** — remote statuses older than `retention_days` (default: disabled) that no local user interacted with are pruned together with their cached attachments, from cron or `occ social:stream:prune`; local content is never touched. Configurable in the Social section of the administration settings.
- 🧹 **Accounts are deleted together** — removing a Nextcloud user takes their Fediverse account with it: the actor is tombstoned, what belongs to it is dropped, and a `Delete` is federated so the servers that cached it drop their copies too. Previously the Social account outlived the user, kept resolving over WebFinger and kept receiving deliveries.
- 🔔 **An unread badge that counts** — the Notifications entry in the sidebar shows how many arrived since you last looked, instead of the hard-coded zero it used to show. The position is a Mastodon **marker** (`/api/v1/markers`), kept per timeline on the server, so clearing it on your phone clears it here; `/api/v1/notifications/unread_count` serves the number.
- 🖼️ **Alt text you can actually write** — every attachment in the composer has a description field, and a post carrying an undescribed one says so before it is sent (a nudge, never a refusal). The app has always rendered other servers' alt text; it could not produce any of its own until now.
- ♿ **Usable without a mouse or without sight** — every post is an `article` named after its author, timelines carry a heading, `j`/`k` moves the keyboard rather than only a highlight, the composer is a named text box with a visible focus ring, attachments and the post timestamp are real buttons, like and boost are single toggles that report their state (and do not throw away the focus of whoever pressed them), and every dialog has a name.
- 🩺 **Federation health** — the administration settings show what the outbound queue is doing: how many deliveries are waiting, how many keep failing, which instances they are stacked up against and how close each is to being given up on (a delivery is abandoned after 15 attempts, previously without a word to anyone). `occ social:queue:status` prints the same summary.
- ⚖️ **Moderation that can act** — a report used to be something an admin could mark handled and nothing more. Each one now carries **Silence**, **Suspend** and **Lift**. Silencing keeps an account reachable for the people who follow it and takes it out of the public and global timelines, changes no data and is undone by lifting; suspending deletes what the account posted here, drops its cached actor and refuses everything it sends afterwards (lifting stops the refusal, it does not bring the posts back — the confirmation says so). Single posts can be removed too.
- 🚫 **Blocking and muting** — block an account to sever the relationship in both directions and hide it everywhere (federated as a `Block` activity unless `occ config:app:set social federate_blocks --value 0`); mute one to hide it from your timelines — and optionally your notifications — without it ever knowing. Both are done from an account's profile menu, **Settings → Blocked and muted accounts** in the app's sidebar lists them with unblock/unmute inline, and the Mastodon API carries them (`/api/v1/accounts/{id}/block|unblock|mute|unmute`, `/api/v1/blocks`, `/api/v1/mutes`).
- 🚩 **Reporting** — `POST /api/v1/reports` files a report, incoming federated `Flag` activities are stored the same way, admins are notified, and reports are reviewed in the Social section of the administration settings. With `forward` set, a report about a remote account is also delivered to the instance that hosts it — anonymised, signed as this server rather than as the person who filed it, because they are reporting an account on the very instance that would otherwise receive their handle.
- 🔒 **Locked accounts and approvable follow requests** — `PATCH /api/v1/accounts/update_credentials` with `locked` toggles `manuallyApprovesFollowers`; an incoming follow towards a locked account stays pending (with a `follow_request` notification) until the owner authorizes or rejects it via `/api/v1/follow_requests` (`lib/Interfaces/Object/FollowInterface.php`).
- 🛡️ **Instance access list** — an allow-list or deny-list of remote hosts, enforced on incoming activities and outgoing requests. Managed with `occ social:fediverse`; see [docs/OCC-Commands.md](https://github.com/nextcloud/social/blob/master/docs/OCC-Commands.md) for the details and its limits.
- 🗓️ **Scheduled posts** — write now, publish later: `scheduled_at` on `POST /api/v1/statuses` (at least five minutes ahead) stores the post instead of publishing it, `/api/v1/scheduled_statuses` lists, moves and cancels what is waiting, and a background job publishes each one when its time comes.
- 🧹 **Domain blocks that clean up** — blocking an instance used to stop only the *next* request. Adding one to the deny list now also removes what it already sent: its accounts, their posts, the follows in both directions and the deliveries still queued towards it (`occ social:domain:purge` runs or finishes the same work by hand). What is deleted is gone — unblocking lets the instance reach you again, it does not bring anything back.
- 📋 **Lists** — group the accounts you follow and read them as their own timeline (`/api/v1/lists`, `/api/v1/timelines/list/{id}`). A list is private to whoever made it.
- 🔑 **Mastodon-compatible API** — the Mastodon client API's core surface plus OAuth 2 authorization: read every timeline, post (with media and polls), follow/unfollow, favourite/boost/bookmark, search (`/api/v2/search`), manage follow requests and report. **Third-party Mastodon clients cannot reach it yet**: every route is served under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base, so a client asked for your domain looks for `/api/v1/...` and finds nothing. Serving those paths at the domain root is the one thing standing between this and stock clients — see [docs/Mastodon-Compatibility.md](https://github.com/nextcloud/social/blob/master/docs/Mastodon-Compatibility.md). No streaming endpoint or push subscriptions either — clients poll. See [docs/API.md](https://github.com/nextcloud/social/blob/master/docs/API.md) for exactly which routes exist.

### 🚧 Not implemented yet

These are absent from the code today, not merely rough edges:

- **No status translation.** The `translate` action returns the post unchanged (`lib/Service/ActionService.php`).
- **No document or file attachments.** Images, video and audio only — anything else is refused by `filterMimeTypes()` (`lib/Service/CacheDocumentService.php`).
- **No custom emoji of this instance's own.** Emoji from other servers render; `/api/v1/custom_emojis` returns an empty list (`lib/Controller/ApiController.php`, `customEmojis()`).
- **No streaming API and no push subscriptions.** Third-party clients poll. (The web client does get live timelines when [notify_push](https://github.com/nextcloud/notify_push) is installed — that is a Nextcloud channel, not a Mastodon one.)
- **No link verification on profile fields.** The four name/value pairs federate as `PropertyValue` attachments, but nothing is checked, so `verified_at` is always `null`.
- **A remote account's existing pins never arrive.** An `Add`/`Remove` sent while the account is known here is applied (`lib/Interfaces/Activity/FeaturedCollection.php`), so pins made from now on show up; nothing ever fetches a remote actor's `featured` collection, so whatever was pinned before this instance heard of the account stays invisible here.
- **No focal points on attachments.** `focus` is accepted by the media endpoints and discarded.

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
   archive through the Makefile, but installs from the lock files (`npm ci`,
   `composer install`) rather than resolving dependency versions no CI job has run,
   and refuses to package when `js/social-adminSettings.js` or `js/.htaccess` is
   missing — both are committed files rather than webpack output, and the target
   used to delete `js/` wholesale before building. Despite the `sign_dir` name it
   only stages and tars, it does not sign anything.

## 🧭 "`.well-known/webfinger` isn't properly set up!" — Troubleshooting

That banner has two quite different causes, and the app now tells them apart.

The first is the one it names: the server does not answer `/.well-known/webfinger`.
Follow the [documented redirects](https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL).

The second is that **Social is set up for a different address than the server now
uses**. Social reads `overwrite.cli.url` once, the first time the app is opened, and
builds every account id, post id and WebFinger answer from that stored copy
(`social.cloud_url`). Change the server's URL afterwards and the two drift apart in
silence: WebFinger answers for a host nobody asks about, and the app blames
`.well-known` when `.well-known` is fine.

Social reports the mismatch with both addresses but will not correct it, because the
stored address is baked into every id already written. Either point
`overwrite.cli.url` back at the address Social knows, or accept the rename and run
`occ social:reset --uri=<new address>`, which deletes everything Social holds. It
asks twice; add `--force` to run it from a script (without it, `--no-interaction`
refuses rather than quietly doing nothing).

To see the two values:

```bash
occ config:app:get social cloud_url
occ config:system:get overwrite.cli.url
```

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
`tests/Service/PostServiceTest.php`). Everything a class needs is mocked; the
server code that resolves a service statically — `OCP\Server::get()`, which
`Response` itself calls on every render — finds a `TestContainer` instead, which
knows a silent logger and a session with nobody in it and raises a named error
for anything else (see `tests/Helper/TestContainer.php`). Migration steps are
given a `FakeTable` that records the schema they ask for rather than building it
(`tests/Migration/`). Frontend tests use Vitest with
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
  config. See [docs/OCC-Commands.md](https://github.com/nextcloud/social/blob/master/docs/OCC-Commands.md) for all commands.
- [docs/Mastodon-Compatibility.md](https://github.com/nextcloud/social/blob/master/docs/Mastodon-Compatibility.md)
  answers how close this is to Mastodon in the three senses that can mean —
  whether its clients work, whether peers can tell the difference, and whether an
  instance could move onto it.
  [docs/Mastodon-Roadmap.md](https://github.com/nextcloud/social/blob/master/docs/Mastodon-Roadmap.md)
  is the backlog that follows from it: everything still between this app and a
  full replacement, in tiers, with what each item actually fixes.
- Before picking up refactoring work, read
  [docs/Technical-Debt.md](https://github.com/nextcloud/social/blob/master/docs/Technical-Debt.md)
  — what in the app is old, borrowed or load-bearing, and what changing it would
  cost — and
  [docs/Performance.md](https://github.com/nextcloud/social/blob/master/docs/Performance.md),
  which lists the query and scalability problems that are still open and the
  ones that have been fixed. Neither is checked by a test, so re-verify a claim
  before acting on it and update the file in the same change as the code.

## License

See the repository's license files in the `LICENSES/` directory.
