# Administering Nextcloud Social

What an administrator has to set up, what they can change, and what tells them
something is wrong. For the routes behind the settings page see
[API.md](API.md), for the commands [OCC-Commands.md](OCC-Commands.md), and for
how the pieces fit together [Architecture.md](Architecture.md).

---

## Before it federates

Social is a fediverse server that happens to live inside Nextcloud, so the
things it needs are the things any fediverse server needs: a stable public
address, a `.well-known` answer, and a cron that runs.

**A stable address.** Social copies `overwrite.cli.url` into its own
`cloud_url` the first time somebody opens the app, and builds every account id,
post id and WebFinger answer from that copy. It never reads the system value
again. Set `overwrite.cli.url` — and get it right — before anyone opens Social;
changing the server's address afterwards is the single most expensive mistake
available here, because the old address is inside every id already federated.
See [*the address Social is set up for*](#the-address-social-is-set-up-for).

**`.well-known` redirects.** Another server looking for `@alice@example.com`
asks `https://example.com/.well-known/webfinger`. Nextcloud serves that from
`/index.php/.well-known/webfinger`, and the redirect from the domain root is
something the web server has to do; Nextcloud's
[documented redirects](https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL)
are exactly the ones needed. Without them nobody outside can find an account
here, however well everything inside works.

**Pretty URLs are not required**, but an instance reachable only at
`https://example.com/index.php/apps/social/...` federates under ids with
`index.php` in them, which some peers handle poorly. If `.htaccess`-based URL
rewriting is available, turn it on before the first account is created rather
than after.

**Behind a reverse proxy**, set `trusted_proxies` and `overwritehost`,
`overwriteprotocol` and `overwrite.cli.url` as the Nextcloud documentation
describes. Social signs its outbound requests over the `Host` and `Date`
headers and verifies the signatures on what arrives, so a proxy that rewrites
the host without Nextcloud knowing produces signature failures on both sides
that look like nothing else.

**Cron.** Background jobs must be set to *Cron* (system cron), not *AJAX* and
not *Webcron*. Everything this instance sends leaves through `Cron\Queue`,
which runs every 12 minutes; with AJAX jobs, delivery happens only when
somebody happens to load a page, and on a quiet instance that can be hours.
See [*the delivery job has not run*](#the-delivery-job-has-not-run).

**Outbound HTTP.** The server must be able to reach the rest of the fediverse
over HTTPS. Requests to local and private addresses are refused unless
Nextcloud's `allow_local_remote_servers` is on, which is a development setting
and not one to carry into production.

---

## The setup checks

Social registers four checks in **Administration → Overview**, beside
Nextcloud's own. They are the four things that break federation without
anything else saying so, and each links back to this page.

`occ social:check:install` runs the same four classes (`lib/SetupChecks/`),
prints each with its severity and exits `1` if any of them reports an error, so
a deployment script can run it. `--offline` leaves out the WebFinger probe, the
only one that goes out on the network.

**Social: upload size.** Whether PHP will accept the uploads this app promises
to. Social's `max_size` is in `/api/v1/instance` and in the composer's refusal
message; PHP's `upload_max_filesize` and `post_max_size` are enforced before a
byte reaches this app's code. When the app's number is the larger one, an
upload between the two is refused with **nothing in the log** — the request
never reaches PHP — and the person is told nothing useful. Raise both PHP
values, or lower the app's own in Administration → Social → Server.

**Social: reachable by other servers.** Whether the strict peers will talk to
this instance at all. Federation does not fail all at once: a plain-HTTP
instance, or one on a private address, federates happily with a permissive
server and is refused at the first gate by a strict one. The strictest in
common use is **Pixelfed**, which is also the network a photo server most wants
to reach — it refuses any peer that is not HTTPS on a publicly resolvable name,
before it checks a signature, from its inbox, its delivery and its actor fetch,
with nothing to configure. This is expected on a development or intranet
instance and is a warning rather than an error.

### WebFinger does not answer

Nothing answers `/.well-known/webfinger` for an account of this instance, so no
other server can find anybody here — searches for a local account fail
everywhere else, and follows from outside never arrive.

Two quite different causes, and the check cannot tell them apart from outside:

1. The redirects are missing. Add the
   [documented ones](https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL)
   and reload the web server.
2. Social is set up for a different address than the one the request arrives
   on, so it answers for a host nobody asks about. The next check is about
   that.

The probe is tried at the address Social is configured for, then at the host
the request came in on, then at the server's base URL, and a success is
remembered for an hour. If the instance uses a certificate the server itself
does not trust — a private CA during setup — set `social.checkssl` to `false`
in `config.php` to stop the probe verifying it. That switch is for the probe
only, not for federation.

### The address Social is set up for

Social builds every id from its stored `cloud_url` and the server now reports
something else. Accounts here cannot be found under the address the server
advertises, and every new post carries an id that resolves nowhere.

To see the two values:

```bash
occ config:app:get social cloud_url
occ config:system:get overwrite.cli.url
```

Social reports the mismatch and will not correct it, because the stored address
is inside every id already written. Either point `overwrite.cli.url` back at
the address Social knows, or accept the rename and run `occ social:reset
--uri=<new address>`, **which deletes everything Social holds** — every post,
follow and cached account, local and remote alike.

### The delivery job has not run

`Cron\Queue` is what sends. It is meant to run every 12 minutes; the check
warns once an hour has passed without it, and errors if the job is not
registered at all (disabling and re-enabling the app registers it again).

Nothing posted here leaves the server while it is not running, and nobody is
told: the author sees their post in their own timeline and nowhere else. Check
that background jobs are set to Cron, that the system cron is actually running
`cron.php`, and that no other job is monopolising the runs.

### Deliveries are stuck

Two things in the outbound queue do not move on their own: a delivery the drain
has given up on after every retry (16 attempts over a widening delay, the last
gap fourteen hours), and one it should have come back for a day ago and has
not. The first usually means an instance that is gone or refusing us; the
second means the drain is not draining.

```bash
occ social:queue:status                  # the same summary the settings page shows
occ social:queue:retry --min-tries 16    # give the abandoned ones the full run again
occ social:queue:retry --flush --min-tries 16   # or drop them, for a peer that is gone
```

The **Federation health** section of the Social settings names the instances
the failures are stacked against, with the highest attempt count so far and
when each was last tried.

---

## The administration page

**Administration → Social.** Twelve sections:

- **Reports** — what people here and peers elsewhere have complained about.
  The open ones are the table; the resolved ones are folded away below them and
  read a page at a time when the fold is opened. Fifty to a page, server-side,
  with a *Show more* under each.
- **Activity here** — posts written on this server in the last day and the last
  week, and how many accounts wrote them. Local posts only: a count that
  included what arrived would be a number about other servers and about this
  one's retention setting.
- **Posts waiting to be looked at** — the review queue: the first post of a
  new account, and posts that tripped one of the spam rules. Each row carries
  the text, because a held post is in no timeline and there is nowhere else to
  go and read it. *Publish* sends it out; *Refuse* deletes it and tells its
  author. Two switches at the top turn first-post review and the spam rules on
  and off.
- **Accounts** — every account this instance knows, whether or not anybody has
  complained. Search by username, by handle or by instance, filter by origin
  and by what stands against them, and act on any of them.
- **Refused pictures** — files this instance will not store, named by their
  sha256. The one thing the account-level tools do not do is stop a *file*
  coming back; a refused one is turned away wherever it arrives, an upload here
  or an attachment fetched from another server.
- **What this server is about** — a few named subjects, each a handful of
  hashtags, shown at the top of Explore above the trending lists. Trending on
  a small server is four hashtags and a wedding; this is the part of that page
  chosen rather than counted, and it is what makes Explore look like somewhere
  to start. Nothing is named by default and the section of the page is absent
  until something is.
- **Retention** — how long remote statuses nobody here cares about are kept.
- **Storage** — what is on disk, split into what was posted here (somebody's
  own work, not going anywhere) and what is cached from other servers (what
  Retention removes). Added up by the background job once a day, because
  counting it is one file lookup per stored file; the page says when it was
  measured. `occ social:media:usage` measures it on demand.
- **Federation health** — what the outbound queue is doing.
- **Fediverse access** — the block list or the allow list, the same one `occ
  social:fediverse` manages.
- **Announcements** — a notice every account here is shown once.
- **Server** — the instance-wide settings below, which had no interface at all
  before and could only be set with `occ config:app:set`.

Each section is one settings card, like everywhere else in the administration
settings, and the three things that cannot be taken back — suspending an
account, taking a post down, removing an announcement — ask in a dialog that
says what they will cost before they do it.

The page can be **delegated**: hand the Social section to a group under
*Administration privileges* and that group can moderate without administering
the server. The Server section is the exception — it is not rendered for a
delegate and its endpoint refuses them, because what it holds is a decision
about the server rather than about a report.

---

## Moderating

Reports arrive from two directions: a local user reporting somebody through a
client, and a peer instance sending `POST /api/v1/reports` about one of its
own users' complaints. Both land in the same table, and the administrators (and
the delegated group) are notified.

There are five things a moderator can do to an account, in order of weight:

| Action | What it costs | Reversible |
|--------|---------------|------------|
| **Warn** | Nothing. The account is told there is a problem and everything else stays as it is. | n/a |
| **Mark everything sensitive** | Every post the account makes from now on is marked sensitive, whatever it said. It stays in the timelines and its followers still see it — behind a click. | yes, completely |
| **Silence** | The account leaves the public and global timelines. Whoever deliberately follows it still sees it. | yes, completely |
| **Take down** | One post is deleted. A local post is deleted everywhere it reached; a remote one only here. | no |
| **Suspend** | Everything the account posted here is deleted, its cached actor is dropped, its follows in both directions go, and everything it sends afterwards is refused. A **local** account's suspension is federated as a `Delete`. | the refusal stops; nothing deleted comes back |

*Resolve* marks the complaint handled and changes nothing about the account, so
a report can be closed with any of the four applied, or with none.

**What is recorded.** `social_moderation` holds what stands *now* — one row an
account, replaced by the next decision and gone when it is lifted.
`social_strikes` is the history: a warning, a silence, a suspension, a takedown
and a lift each write a row naming the moderator, and nothing removes one
except the account itself going. The strike count in the account browser counts
what stands against the account, so a lift is in the history and not in the
count.

**The audit log.** Suspending, silencing, lifting, taking a post down and
blocking or unblocking an instance each emit
`OCP\Log\Audit\CriticalActionPerformedEvent`, so with core's `admin_audit` app
enabled they land in the audit log beside "user X was added to group Y", naming
the moderator who acted. Without that app nothing listens and nothing is
written.

**Before anybody sees it.** Two rules hold a post back instead of publishing
it, both on by default:

- **first-post review** (`review_first_post`) holds the first post of an
  account that has published nothing here yet. Accounts here are Nextcloud
  users, so a spammer has to be given an account by this server before they can
  post at all — and when that happens, the cheapest thing that can be done
  about it is that the first thing they write is seen by a person. An instance
  whose accounts are all colleagues turns it off and loses nothing.
- **autospam** (`autospam`) holds a post that trips one of a very short list of
  rules: more than five links in a short post, or more than five mentions from
  an account that nobody follows and that follows nobody. There is no wordlist
  and no score — "seven links" is something a moderator can agree or disagree
  with in a second, and "0.82" is not.

A **direct message is never held**, whatever it says: holding one would put
private correspondence in front of a moderator who was not written to, for a
machine's reason. Nor is a post by an account a decision already stands
against — that account is being dealt with by the decision.

Nothing is published, and nothing is deleted, without somebody pressing
something. A held post is stored as the *request* the client sent, not as a
post: it is in no timeline, no profile, no outbox and no search, not even its
author's, and their own copy of it is in **Settings → Waiting to be looked at**,
where they can take it back. Approving replays the request down the path an
immediate post takes and dates it now. Refusing deletes it, tells the author,
and records a takedown strike. One account may have twenty waiting; past that
its posts are refused outright, because nobody is going to read the fortieth.

**Instances, not accounts.** *Fediverse access* is the instance-wide list.
In block-list mode (the default, `access_type=all_but`) everything on it is
refused, including every subdomain, and blocking a domain queues a purge of
everything it ever sent. In allow-list mode (`none_but`) the list is the only
instances this server will talk to at all. An instance that is a nuisance
rather than a menace can be *silenced* instead with `occ social:fediverse
silence`: its accounts leave the public timelines and stay readable for the
people who follow them.

---

### From a Pixelfed admin app

The administration screens of Pixelfed's official app call Pixelfed's own
`/api/admin/*` routes, and this instance answers them — behind the same gate
as everything above, and through the same service, so a takedown from the app
is the same takedown as one from this page. Users, open reports and the
instances this server federates with are all there; Pixelfed's `unlisted` on
an instance is the silence tier and its `banned` the deny list. Its **autospam**
screen draws this instance's own review queue, with *not spam* publishing a held
post and *delete* refusing it. What this instance does not have — per-account
"unlisted" or "content warning" flags, switches that flip from the app — is
answered as absent or refused with a reason, never as a 200 that changed
nothing. The
routes and their answers are in [API.md](API.md).

## Configuration

Everything below is app configuration under `social`, read and written with:

```bash
occ config:app:get social <key>
occ config:app:set social <key> --value=<value>
```

Nothing here is reachable through core's app-config API: the settings page
writes through its own validated endpoints, and a delegate can write only what
the moderation routes accept.

### Set by the app, not by you

| Key | Default | Meaning |
|-----|---------|---------|
| `cloud_url` | *(empty)* | The base address every id is built from, copied from `overwrite.cli.url` the first time the app is opened. Changing it by hand does not rewrite the ids already issued. |
| `social_url` | *(empty)* | The app's own base URL (`…/apps/social/`), used for profile links, WebFinger and NodeInfo. Derived at the same moment as `cloud_url`. |
| `social_address` | *(empty)* | The hostname accounts are federated under, when it is not the host of `cloud_url`. Only set this if the fediverse address genuinely differs from the Nextcloud host, and only before the first account exists. |
| `service` | `1` | Unused; a leftover of the original installer. |
| `installed_version` | | Written by the upgrade machinery. |
| `media_usage` | *(written by the job)* | The last measurement of what is on disk, as JSON with the moment it was taken. Bookkeeping, not a setting: the walk is a `stat` per stored file and belongs in the cron, so the administration page reads this rather than counting on page load. |
| `polls_swept` | `0` | How far the closed-poll sweep has got, as a timestamp. |
| `story_secret` | *(generated)* | The secret a story's fetch capability is derived from, made the first time a story is published. Changing it invalidates every outstanding capability at once, which is the only revocation it needs: a story lives a day. Never set this by hand. |

### The Server card

These eight are what the **Server** section of the settings page writes. Each
can still be set with `occ`; the page validates the ranges given here.

| Key | Default | Meaning |
|-----|---------|---------|
| `contact_email` | *(empty)* | Who to write to about this instance. Mastodon's `instance.email`: every client reads it on its first request and shows it on the server's about page. Empty until somebody fills it in, which until now most instances never did, because nothing said it existed. |
| `extended_description` | *(empty)* | The long form of what this instance is, for `/api/v1/instance/extended_description`. Up to 10000 characters. |
| `max_size` | `10` | The largest picture or file an upload may be, in MB. 1–10240. |
| `max_video_size` | `2048` | The largest video, in MB. 1–102400. A peer will refuse a great deal less than the ceiling. |
| `image_max_edge` | `0` | The longest edge a **stored** picture may have, in pixels. `0` stores every upload exactly as it arrived — the default, and the only setting that loses nothing: this app strips metadata losslessly and re-encodes only a picture it has to rotate. Set it (480–16384) on an instance where storage costs money or whose people post from a 48-megapixel phone. A picture already inside the ceiling is not re-encoded, because shrinking nothing and losing a generation anyway is the worst of both. |
| `image_quality` | `85` | What a re-encoded picture is stored at, 40–100. Only consulted when `image_max_edge` is set. |
| `video_transcode` | `0` | Whether stored videos are re-encoded to H.264 in an MP4 by a background job. Off by default, because re-encoding is lossy and it is somebody's file — but there is a concrete reason to turn it on: **Pixelfed's default `media_types` accepts `video/mp4` and nothing else**, so every `video/quicktime` posted from here, which is every video straight off an iPhone, is dropped by its inbox without a word to anybody, and Safari will not play WebM. Needs ffmpeg; nothing happens without it. Never runs during an upload: converting a video is minutes rather than the seconds a poster frame takes, so the upload finishes as it always did and the video plays as it is until the job gets to it. One video every quarter of an hour, or `occ social:media:transcode` to work through a backlog now. |
| `video_max_height` | `1080` | The tallest a converted video is written, 240–2160. Only smaller, never larger: a 480p video is left at 480p. Only consulted when `video_transcode` is on. |
| `inbox_throttle` | `300` | Incoming inbox requests allowed per origin host per minute. `0` accepts everything, which is what an instance behind its own rate limiter wants. |
| `secure_mode` | `0` | Refuse ActivityPub fetches that are not signed. Mastodon's secure mode. Turning it on makes this instance invisible to every peer that does not sign what it asks for, and to every anonymous reader; it is a decision about who to federate with, not a hardening step to apply by default. |
| `publish_blocks` | `0` | Publish the deny list on `/api/v1/instance/domain_blocks`, the way Mastodon does, so somebody choosing a server can see who it will not talk to. Whether *this* server wants that read by anybody is a disclosure decision. |
| `allow_self_signed` | `0` | Accept peers whose certificates do not check out. **Development only**: on a server anybody else uses, this hands every federated request to whoever can answer for the address. |

### Moderation and federation

| Key | Default | Meaning |
|-----|---------|---------|
| `access_type` | `all_but` | `all_but` — federate with everyone except the listed instances (block list). `none_but` — federate only with the listed ones (allow list). |
| `access_list` | `[]` | The instances on that list, as a JSON array of hostnames. An entry covers every subdomain of itself. Managed from the settings page and by `occ social:fediverse`. |
| `silenced_list` | `[]` | Instances whose accounts are kept out of the public and global timelines but stay readable for whoever follows them. The middle tier a block does not have. |
| `retention_days` | `0` | Remote statuses older than this that no local user interacted with, follows the author of, or replied below are deleted, with their cached attachments. `0` disables it. Local content is never touched. |
| `federate_blocks` | `1` | Whether a user's own blocks are federated to the blocked account's instance. `0` keeps them local. |
| `publish_video_objects` | `0` | Whether a post that is a video is federated as an ActivityPub `Video` (PeerTube's shape) rather than a `Note` with an attachment. Off by default: Pixelfed's inbox handles only `Note`s and silently drops a `Video`, so with this on no video posted here reaches a Pixelfed follower. Mastodon draws both shapes; PeerTube draws only the `Video`. Turn it on for an instance whose audience is on PeerTube. |
| `rules` | *(empty)* | The instance rules shown by `/api/v1/instance/rules`, one per line. |
| `review_first_post` | `1` | Hold the first post of an account that has published nothing here yet, for a moderator to see before it goes out. |
| `review_posts` | `1` | How many posts an account must have had published before its posts stop being held. `1` is first-post review as it has always meant. An account graduates by having that many posts approved — a person having looked at it that many times, which is the only measure of trust here that is not a guess. Capped at 20. |
| `autospam` | `1` | Hold a post that trips one of the spam rules — more than five links in a short post, or more than five mentions from an account nobody follows and that follows nobody. Nothing is ever refused by the rules, only shown to a person. |

### System configuration

Two `config.php` values, neither documented anywhere else:

| Key | Default | Meaning |
|-----|---------|---------|
| `social.checkssl` | `true` | Whether the WebFinger setup check verifies the certificate it is offered. Set it to `false` on an instance using a certificate the server itself does not trust. It affects the probe only — federation still verifies. |
| `social.tests` | *(unset)* | Enables `GET /apps/social/test/{account}/`, a WebFinger self-test. Leave it unset outside development. |

And one of Nextcloud's own that matters here: `allow_local_remote_servers`,
which must be on for an instance to federate with anything on a private
address. That is a development arrangement.

---

## Commands by task

The full reference is [OCC-Commands.md](OCC-Commands.md); these are the ones an
administrator reaches for.

**Is it working?**

```bash
occ social:check:install            # the four setup checks, plus repairs
occ social:check:install --offline  # the same without the network probe
occ social:queue:status             # what the outbound queue is doing
occ social:details <id>             # who can see one post and where it lands
```

**Delivery is behind**

```bash
occ social:queue:process            # drain it now instead of waiting for cron
occ social:queue:retry              # give failing deliveries the full run of retries again
```

**Moderation**

```bash
occ social:fediverse list                  # the access list; bare, it prints the mode
occ social:fediverse add <instance>        # block it (or allow it, in allow-list mode)
occ social:fediverse remove <instance>
occ social:fediverse silence <instance>    # out of the public timelines, still followable
```

**Housekeeping**

```bash
occ social:stream:prune             # apply retention and purge finished queue rows
occ social:cache:refresh            # re-fetch cached remote accounts
occ social:domain:purge <instance>  # remove what a now-blocked instance sent
```

**Starting over**

```bash
occ social:reset                    # empties every Social table; asks twice
occ social:reset --uninstall        # and drops the tables, jobs and app config
```

---

## What to watch

- **Administration → Overview.** The four checks above are there precisely so
  that an administrator who never opens Social still hears about it.
- **The delivery queue.** A rising count of failing deliveries against one host
  is that instance's problem; a rising count against all of them is this one's.
- **`social.log` / the Nextcloud log.** Signature verification failures on
  inbound requests, and `could not federate` warnings on outbound ones.
- **Disk.** Cached remote attachments are the largest thing this app stores.
  `retention_days` is what bounds them; on an instance that federates widely,
  leaving it at `0` means keeping every picture anybody here ever scrolled
  past.
- **The audit log**, if `admin_audit` is enabled: every moderation decision,
  with the moderator who took it.
