# Nextcloud Social 🚀✨

Nextcloud Social is an ActivityPub app that connects your Nextcloud account to the Fediverse. Your instance acts as a lightweight federated social server: each user gets a `Person` actor, can write and edit posts, follow accounts on other servers, and like, boost and reply to what they receive.

![Screenshot](img/screenshot.png)

It is a partial implementation of ActivityPub and of the Mastodon client API — enough for posting, following and reading timelines, but far from feature parity with Mastodon. See "Not implemented yet" below before deploying it as someone's only Fediverse client.

## 🔧 Features

- 🧭 **Timelines** — Home, Local, Global (federated), Direct messages, Liked posts, Notifications, per-account and per-hashtag timelines. The three a reader moves between all day — **My Feed**, **Local** and **Global** — are a switcher above the posts rather than three sidebar entries, because changing between them is something you do while reading rather than somewhere you navigate to: one pill slides between them, arrow keys move through them, and **Photos** and **Videos** each carry the same switcher, so the pictures — or the videos — of the people you follow, of this instance and of the whole fediverse are one click apart.
- 👤 **Posts, Photos, Videos** — a profile carries the same switcher over what of an account to read, and each tab is a question asked of the server (`only_media`, plus a `media_type` extension) rather than a filter of the page already on screen. The tab decides how it is drawn, too: Posts is a list of what they wrote, Photos and Videos are grids of what they showed.
- ✍️ **New post, first** — the sidebar's call to action is a button rather than a row among the places to go: the primary colour across the sidebar, a lift under the pointer, and a light that crosses it once. Every bit of that movement is off for a reader whose system asks for reduced motion.
- 👤 **Your account at the bottom** — the More menu hangs off your own portrait and the name you publish under, the way every other Nextcloud app ends its sidebar, with **My profile** first behind it.
- ⚙️ **Your account, in Settings** — the name you publish under, whether people have to ask before they follow you (the switch the Follow requests page points at), whether other servers may suggest you and index your public posts, whether this is an automated account, and the audience every new post starts with. Only what you changed is sent, so a display name your Nextcloud gets from elsewhere is never written back. The default audience is what the composer opens with, unless you are answering a post — a reply goes where the post it answers went.
- 📋 **Lists, made and filled here** — the same Settings page makes a list, renames it, deletes it and puts people in or takes them out, searching for whoever you want to add. The lists your Nextcloud groups give you are shown with their members, and stay read-only: the group decides who is in those. The sidebar follows along.
- 📦 **Migration** — a page of its own behind your account: **Export** takes your profile, follows, followers, blocks, mutes, bookmarks, likes and every post you have written as a zip (your private key is deliberately not in it), **Import** reads one back — including an archive from `occ user:export` — and a third section brings your follows over from Mastodon, Pixelfed, GoToSocial or Akkoma via their `following_accounts.csv`. It says plainly which networks cannot be imported from, and leaves a whole-account `Move` to `occ social:account:alias` and `occ social:account:move`, since that federates and cannot be undone.
- ✍️ **Composer** — write posts and replies, pick a visibility (public, unlisted, followers-only, direct), insert emoji, and attach images. `@mentions` and `#hashtags` typed by hand are extracted from the text and turned into real recipients and tags. Say **what language** the post is in (a two-letter badge in the toolbar, starting from your Nextcloud language and remembering your last choice), send it **later** with the clock, and set the **focal point** of a picture by clicking where the subject is. A **reply starts addressed to everyone in the conversation** — the author and everyone their post mentioned, never yourself — rather than to the one person you answered.
- 📊 **Polls** — write one in the composer (up to four options, single or multiple choice, 30 minutes to a week), and view and vote on federated ones. Votes travel to the poll's author as ActivityPub vote notes, incoming votes are counted, and the new totals federate back as `Update{Question}` (`lib/Service/PollService.php`).
- 🖼️ **Media attachments** — images (JPEG, PNG, GIF, WebP, AVIF, and **HEIC/HEIF** from an iPhone, transcoded to JPEG on the way in where the server has Imagick with libheif), video (MP4, WebM, QuickTime) and audio (MP3, MP4/AAC, OGG/Opus, WAV, FLAC); `filterMimeTypes()` in `lib/Service/CacheDocumentService.php` is the exact list. Up to **ten** per post. Video and audio are stored as-is — **no transcoding** — but a video gets a **poster frame** where the server has ffmpeg, so a page of videos can be scrolled without downloading one, and `/media/{uuid}` answers **byte ranges**, so a video can actually be seeked. Video has a ceiling of its own (`max_video_size`, 2 GB) rather than the 10 MB `max_size` a picture is held to, because a video is streamed to storage a chunk at a time and never held in memory; the largest uploads go through `POST /api/v1/media/from-file`, where the Files app has already done the chunking. Clients upload through `POST /api/v2/media` (or v1), with alt text via `description`, editable with `PUT /api/v1/media/{id}` and attached to a status with `media_ids`. A **focal point** (`focus`, Mastodon's `x,y`) says where the subject is, so a square crop keeps it in frame; it is set in the composer by clicking or dragging a crosshair over the picture (or with the arrow keys), and federates as `focalPoint`.
- 🔒 **Metadata stripped on upload** — a photo off a phone carries an Exif block, and that block routinely carries the coordinates it was taken at. Every uploaded picture has its Exif, XMP, IPTC and comment blocks removed before it is stored or federated (`lib/Service/ImageMetadataService.php`). Done on the container rather than by re-encoding, so nothing loses a generation of quality, and the ICC colour profile is deliberately kept. A photo that says it is rotated is turned the right way up first, since the orientation tag goes with the rest.
- 🎨 **Filters** — eight mild adjustments in the composer, previewed live and baked into the copy that is posted.
- 🔳 **Profile grid** — a profile draws as a grid of squares or as a timeline, whichever you last chose. The crop follows each picture's focal point.
- 📡 **Videos federate as videos** — a post that is one video goes onto the wire as an ActivityPub `Video` rather than a `Note`, which is the only object PeerTube (and every other video-native server) ingests: until now a Social instance had, from their side, no videos at all. The Mastodon-shaped `attachment` is published alongside it, so nothing that rendered before renders worse. On by default; `occ config:app:set social publish_video_objects --value 0` turns it off.
- 🎬 **Videos, and PeerTube** — a **Videos** entry under Photos, showing what was posted to watch rather than to look at: your own instance's video posts and the videos from the PeerTube channels anyone here follows. A PeerTube `Video` is a shape nothing else on the fediverse sends — the title is in `name`, the description is markdown, the actual file is one of a dozen links in `url`, and it is attributed to two actors at once — and all of it is read (`lib/Service/PeerTubeService.php`), so a federated video arrives with its title, its channel, its thumbnail and something to play. The video itself is **streamed from the instance that holds it, never copied here**: a two-hour talk is not something to mirror onto somebody's Nextcloud. It is proxied rather than played from the origin directly, so the page keeps `media-src 'self'` and nobody's IP address reaches a server they never chose to talk to; the thumbnail *is* mirrored, which is what lets the timeline be scrolled without fetching a frame of anything.
- 📤 **Share to Social, from Files** — select a picture or a video in the Files app (or up to ten of them), pick *Share to Social* from its menu, and the composer opens with them already attached. Nothing is uploaded a second time: the composer takes them by path, the same way its own "Add from Files" does. The script that puts the entry into Files is the one bundle of this app built without the shared framework, so a Files page pays a few KB for the menu entry and nothing for a composer it may never open.
- 📬 **Delivery status on your own posts** — a post's menu says where it got to: delivered to which servers, still waiting or being sent, failing and how many times, or given up on. The delivery queue keeps a finished request for seven days so the question can be answered, then purges it. When a post does not show up on Mastodon, this is where you look instead of wondering.
- 🪪 **An account only when you ask for one** — opening the app used to create your fediverse identity on the spot (a key pair, a WebFinger-resolvable handle derived from your user id) with nobody asked. The first visit is now a question: create an account here, with a **handle you choose** (yours to take if it is your own user id or nobody else's, and not another user's id — it cannot be changed later, so it is chosen first), or **name the account you already have** on Mastodon or elsewhere, which goes on your Nextcloud profile where colleagues find it on Discover, and creates nothing here.
- 👋 **A first-run introduction** — the first time somebody opens the app, in place of a paragraph about beta: four short steps that answer what a new account actually asks. Your address, with a copy button; who to follow — the **people on your Nextcloud** who have said where they are on the fediverse, and the starter packs, each followable from the card; **bring your follows** by uploading the `following_accounts.csv` any Mastodon-like server exports, into the same import Settings offers; and a button that puts the caret in the composer. Every step can be skipped and the whole thing is gone for good once closed.
- ✅ **Verified profile links** — a profile field that names a web page gets the tick when that page links back to the profile with `rel="me"`, the way Mastodon verifies one. Checked by the background job for this instance's accounts and with the details refresh for remote ones, once a day per account; the page is fetched through the same guarded client as everything else a user can point the server at (http(s) only, no local hosts unless the administrator allowed them, size-capped), and only the page's own `<a>`/`<link>` tags are read. `verified_at` on the Account entity is when the page was first seen linking back.
- 📌 **Remote pins arrive** — a remote account's pinned posts used to show up only if it pinned something *after* this instance met it; the `featured` collection is now read with the account's details refresh, so a profile shows what it has pinned, embedded posts by the account are stored when this instance does not hold them yet, and pins it has taken down come down here too.
- 📱 **Fits a phone** — under 600px the face moves inside the card and the card takes the width of the screen, the composer's toolbar wraps instead of pushing Post off the edge, a post's page gives up the column it kept for an avatar that is no longer beside it, and the first thing on a page starts below the sidebar toggle rather than under it.
- 🔗 **Links land where they should** — a link to a post or a profile opens **inside the app** when you are signed in, instead of the logged-out public page with its "Get your own free account" banner and a Follow button that started the remote-follow flow for somebody you could have followed with one click. A fediverse permalink, whose last segment is not the id the app uses, resolves to the same post. A **search can be reloaded**, bookmarked or sent to somebody. An address that names nobody is a **404 that says so** rather than a server error. And pressing **Back out of a post puts you back where you were reading** — the timeline you had loaded is still loaded, and the page is drawn before the scroll offset is restored, so the browser has somewhere to put you.
- 🔗 **Links unfurl** — paste a link to a post or a profile here into a Talk message, a Text document or a Deck card and it becomes a card: the author, the text, the first picture. Only what anyone could read is rendered — public and unlisted posts, and profiles — because the card is cached once for everyone who sees the link; a followers-only post stays a plain link.
- 🕘 **In the Activity app** — a follow, a mention, a boost or a favourite of yours is an entry on the Activity page as well as a bell, and goes into the Activity digest mail for those who turn that on there. Activity's own notifications for it stay off: the bell is the bell.
- 🧭 **Discover** — who to follow and what is being looked at, in one page: suggestions, **starter packs**, trending pictures and trending hashtags. Suggestions include **the people you share this Nextcloud with**: every Nextcloud profile has a `fediverse` field, this app writes your handle into it when it is empty, and reads everyone else's — so a new account is shown its colleagues before any algorithm has anything to say. A starter pack is a named handful of accounts with a "follow everyone" button — the answer to the one question a new account has that no algorithm can give, since suggestions work off a follow graph you are not yet part of. The shipped packs are the official accounts of the projects this app federates with; an administrator curates their own with the `starter_packs` app setting.
- 📱 **Pixelfed's own routes** — the `/api/v2/config` bootstrap its app reads on launch and the `v1.1` discover namespace, so a client built for Pixelfed finds what it looks for. Every limit in the config is derived from the one the server enforces, so a client is never told a ceiling the server does not keep.
- 🗂️ **Collections** — albums you curate out of your own posts, in an order you choose, public or followers-only. Local: a peer sees the posts, which it already had.
- ⏳ **Stories** — a picture that stops existing after a day. Shown to your followers and nobody else, never federated, and swept by a background job as well as filtered out of every read.
- 📍 **Places** — say where a post was taken. **No geocoder is involved**: a place is one this instance has already seen or one you name yourself, because sending somebody's location to a third party at the moment they are deciding whether to publish it is the failure the Exif stripping exists to prevent.
- 😀 **Custom emoji from other instances** — `Emoji` tags on remote statuses and actors survive the cache (via the stored wire source) and are served in the `emojis` field of status and account entities; the web client shows them inline in content and display names.
- ✏️ **Edit posts** — edit your own local posts inline; the change is saved and federated as an ActivityPub `Update` (`lib/Service/PostService.php`, `editPost()`).
- 🗑️ **Delete posts** — delete your own posts.
- ⚠️ **Content warnings** — put a warning on a post in the composer and the body is folded away behind it until a reader asks to see it (it is not even in the page until then). Carried as the ActivityPub object's `summary` and as `spoiler_text` on the client API, so warnings written elsewhere in the Fediverse are honoured here and vice versa.
- 👍 🔁 💬 **Post actions** — like/unlike, boost/unboost (`Announce`) and reply.
- 🧵 **Replies read as a conversation** — under a post, each reply is followed by the replies to it, oldest first, stepped in by how deep it sits (up to four levels) with a line down its side. They were one flat list, newest first, so a reply to a reply landed above what it answered and nothing said which reply it answered. A reply whose parent is not on the page — deleted, or a branch this instance holds only part of — keeps its place in time at the top level, with its own replies under it.
- 💬 **A post's own page** — a post opened from a timeline gets a page of its own: the post, a reply box already under it and pointed at it, who boosted and who favourited it (the faces behind the counts, from `reblogged_by`/`favourited_by`), and the details a card leaves out — when exactly, to whom, in what language, whether it has been edited since, and a link to the original for a post from another server. When the thread shown is shorter than the post's own reply count, the page says so rather than presenting what this server holds as the whole conversation.
- 👥 **Following** — follow and unfollow local and remote accounts, and browse followers/following lists.
- 📌 **Pinned posts** — pin up to five of your own **public or unlisted** posts to the top of your profile (`pin`/`unpin` on the status-action endpoint, `?pinned=true` on the account statuses route). Pins are published in the actor's `featured` collection, which anyone on the internet may read, so nothing with a narrower audience can go into it — a followers-only or direct post is refused. Pins of *remote* accounts arrive as `Add`/`Remove` activities; their `featured` collection is never fetched.
- 🖼️ **Profiles** — avatar, uploadable banner/header image, a profile description (`note`) and up to four editable **profile metadata fields** (the name/value table under the bio), federated as `PropertyValue` attachments on the actor and shown for remote accounts too.
- 🌐 **Federation** — signed HTTP delivery of `Create`, `Update`, `Delete`, `Like`, `Announce`, `Follow`, `Accept` and `Undo` activities, an outbound request queue and a stream queue for resolving incoming objects, both drained by background jobs and by `occ social:queue:process`.
- 🔁 **Inbox forwarding** — a reply to one of your posts that arrives from a stranger's instance is passed on to your followers, so everyone reading the thread sees the same one. Forwarded untouched and only when the reply carries its author's linked-data signature, so the servers receiving it verify the original author rather than trusting this one; private posts and their replies are never fanned out.
- 🔔 **Live timelines with notify_push** — when the [notify_push](https://github.com/nextcloud/notify_push) app is installed, new timeline entries reach open web clients as push events and polling drops to a five-minute safety net; without it the client polls every 30 seconds.
- 🔎 **Discovery & search** — WebFinger lookups, remote actor resolution and caching, search over known accounts and hashtags, and **full-text search of visible posts** through Nextcloud's unified search (own posts, public content and messages addressed to you; a plain database substring match, no external search engine needed).
- 📈 **Trending hashtags** — the tags used most on the instance, counted per window (1 h to 10 days) by the same cron job that maintains the hashtag index, listed in the app's sidebar and served as Mastodon `Tag` entities at `/api/v1/trends/tags`. Discover ranks them in full: pick the window and the list is re-ranked over it — the busiest hour and the busiest ten days are different lists, not one list relabelled — with each tag's share of the busiest one drawn beside its count, and a Follow button on every row.
- 🔖 **Bookmarks** — bookmark any post from its menu and read them back under Bookmarks in the sidebar. Purely local, never federated.
- 🔗 **Link previews** — a post that links somewhere gets a preview card (OpenGraph, with the plain title/description as fallback) read by a background job, so nothing waits for a stranger's web server. The fetch is HTTP(S)-only on every hop, refuses local addresses, is size- and time-capped and obeys the instance access list. Cards are never federated — like Mastodon, every instance reads the page itself.
- 🧹 **Retention** — remote statuses older than `retention_days` (default: disabled) that no local user interacted with are pruned together with their cached attachments, from cron or `occ social:stream:prune`; local content is never touched. Configurable in the Social section of the administration settings.
- 🧹 **The cache does not grow for ever** — a remote account is cached the first time this server sees it, and nothing used to remove one. Cached accounts nobody here follows, that follow nobody here, that wrote no post stored here and have nothing pending are now evicted with their avatars after `cache_actor_days` (default: 180; `0` disables), from the cache cron. Anything swept is fetched again the moment it is needed. `occ social:media:usage` says what the media is costing and how much of it is somebody else's — uploads here against files cached off other servers, attachments against avatars.
- 🧹 **Accounts are deleted together** — removing a Nextcloud user takes their Fediverse account with it: the actor is tombstoned, what belongs to it is dropped, and a `Delete` is federated so the servers that cached it drop their copies too. Previously the Social account outlived the user, kept resolving over WebFinger and kept receiving deliveries.
- 🔔 **The Nextcloud bell** — a mention, a favourite, a boost, a new follower, a follow request, an edit of a post you boosted, a poll you voted in closing and a post from an account whose bell you rang all reach Nextcloud's own notifications (and its mail digest), each linking into this app's page for the post or the account rather than out to the remote server. A **follow request carries Accept and Decline** on the bell entry itself; answering it there takes the entry down.
- 🔔 **A notifications page, not a list of everything** — a row of filters over the page (All, Mentions, Favourites, Boosts, Follows, Polls, Edits) asked of the server as Mastodon's `exclude_types` and remembered by the browser; a run of likes or boosts of the same post, and a run of new followers, drawn as **one card** with the faces stacked and the post quoted once, rather than the same post quoted twelve times; and a **New** line where you left off, from the same server-side marker every other client keeps. The page marks itself read after it has been in front of you for two seconds, not the instant it renders — the old behaviour cleared the badge on your phone for notifications nobody had seen.
- 🖼️ **ALT, everywhere a picture is** — a picture that carries a description is badged wherever it is drawn, and pressing the badge shows the description. It was on the media-first mosaic only; the same picture in an ordinary card had its alt text in an attribute and nowhere a sighted reader could get at it.
- 🔔 **An unread badge that counts** — the Notifications entry in the sidebar shows how many arrived since you last looked, instead of the hard-coded zero it used to show. The position is a Mastodon **marker** (`/api/v1/markers`), kept per timeline on the server, so clearing it on your phone clears it here; `/api/v1/notifications/unread_count` serves the number.
- 📏 **The composer keeps the server's limits, not its own** — the character counter and the attachment ceiling are read from `/api/v1/instance` (`configuration.statuses`) instead of being written into the frontend. They had drifted: the counter refused a post at 500 characters where the server takes **5000**, so four fifths of what this instance allows could not be typed into it.
- 🖼️ **Alt text you can actually write** — every attachment in the composer has a description field, and a post carrying an undescribed one says so before it is sent (a nudge, never a refusal). The app has always rendered other servers' alt text; it could not produce any of its own until now.
- ♿ **Usable without a mouse or without sight** — every post is an `article` named after its author, timelines carry a heading, `j`/`k` moves the keyboard rather than only a highlight, the composer is a named text box with a visible focus ring, attachments and the post timestamp are real buttons, like and boost are single toggles that report their state (and do not throw away the focus of whoever pressed them), and every dialog has a name.
- 🩺 **Federation health** — the administration settings show what the outbound queue is doing: how many deliveries are waiting, how many keep failing, which instances they are stacked up against and how close each is to being given up on (a delivery is abandoned after 16 attempts, previously without a word to anyone). `occ social:queue:status` prints the same summary.
- 🩺 **Setup checks where an administrator already looks** — Administration → Overview now carries four checks of its own: whether `.well-known/webfinger` answers for an account here, whether the address Social builds every id from is still the one the server reports, whether the job that delivers everything this instance sends has run lately, and whether anything in the outbound queue is stuck. Each says what to do and links to the [admin guide](https://github.com/nextcloud/social/blob/master/docs/Admin.md). `occ social:check:install` runs the same four and exits non-zero if any of them fails, so a deployment script can ask. Before this, an administrator who never opened Social found out that nobody could follow anyone here when a user asked.
- ⚙️ **Server settings with an interface** — the contact address, the instance description, the upload ceilings, the inbox rate limit, secure mode, whether the block list is published and whether self-signed certificates are accepted are all in a **Server** card in the Social settings. Every one of them existed as an app config key that only `occ config:app:set` could write, which is where most of them stayed. The card is for administrators; a group the section was delegated to moderates and does not see it.
- 🧾 **An audit trail for moderation** — suspending, silencing, lifting, taking a post down and blocking or unblocking an instance are recorded with the moderator who did it, and go into the server's audit log through core's `admin_audit` app. Lifts and takedowns used to leave a log line that named nobody.
- 🩺 **Federation health** — the administration settings show what the outbound queue is doing: how many deliveries are waiting, how many keep failing, which instances they are stacked up against and how close each is to being given up on (a delivery is abandoned after 15 attempts, previously without a word to anyone) — and, separately, which instances have been **given up on** in the last seven days, which was the one state nothing reported at all. `occ social:queue:status` prints the same summary, and `occ social:queue:retry --instance HOST` puts one host's deliveries back in the queue once the reason they failed has been dealt with.
- ⚖️ **Moderation that can act** — a report used to be something an admin could mark handled and nothing more. Each one now carries **Silence**, **Suspend** and **Lift**. Silencing keeps an account reachable for the people who follow it and takes it out of the public and global timelines, changes no data and is undone by lifting; suspending deletes what the account posted here, drops its cached actor and refuses everything it sends afterwards (lifting stops the refusal, it does not bring the posts back — the confirmation says so). Single posts can be removed too.
- 🚫 **Blocking and muting** — block an account to sever the relationship in both directions and hide it everywhere (federated as a `Block` activity unless `occ config:app:set social federate_blocks --value 0`); mute one to hide it from your timelines — and optionally your notifications — without it ever knowing. A mute asks how long for: until you lift it, or an hour, a day, seven days or thirty, and a profile says when a timed one runs out. Both are offered on an account's profile **and in the menu of any post they wrote**, which is usually where you decided; **Settings → Blocked and muted accounts** in the app's sidebar lists them with unblock/unmute inline, and the Mastodon API carries them (`/api/v1/accounts/{id}/block|unblock|mute|unmute`, `/api/v1/blocks`, `/api/v1/mutes`).
- 🗒️ **A private note about somebody** — a few words on another person's profile, for you alone: where you met them, what they write about. It never leaves this server, the account it is about is never told, and no other instance sees it (`POST /api/v1/accounts/{id}/note`, read back as `note` on the relationship).
- 🚩 **Reporting** — `POST /api/v1/reports` files a report, incoming federated `Flag` activities are stored the same way, admins are notified, and reports are reviewed in the Social section of the administration settings. With `forward` set, a report about a remote account is also delivered to the instance that hosts it — anonymised, signed as this server rather than as the person who filed it, because they are reporting an account on the very instance that would otherwise receive their handle.
- 🔒 **Locked accounts and approvable follow requests** — `PATCH /api/v1/accounts/update_credentials` with `locked` toggles `manuallyApprovesFollowers`; an incoming follow towards a locked account stays pending (with a `follow_request` notification) until the owner authorizes or rejects it via `/api/v1/follow_requests` (`lib/Interfaces/Object/FollowInterface.php`).
- 🛡️ **Instance access list** — an allow-list or deny-list of remote hosts, enforced on incoming activities and outgoing requests. Managed with `occ social:fediverse`; see [docs/OCC-Commands.md](https://github.com/nextcloud/social/blob/master/docs/OCC-Commands.md) for the details and its limits.
- 🗓️ **Scheduled posts** — write now, publish later: the clock in the composer picks a time at least five minutes ahead, the Post button becomes **Schedule**, and **Settings** lists what is waiting with a way to cancel one. Underneath: `scheduled_at` on `POST /api/v1/statuses` stores the post instead of publishing it, `/api/v1/scheduled_statuses` lists, moves and cancels what is waiting, and a background job publishes each one when its time comes.
- 🧹 **Domain blocks that clean up** — blocking an instance used to stop only the *next* request. Adding one to the deny list now also removes what it already sent: its accounts, their posts, the follows in both directions and the deliveries still queued towards it (`occ social:domain:purge` runs or finishes the same work by hand). What is deleted is gone — unblocking lets the instance reach you again, it does not bring anything back.
- 📋 **Lists** — group the accounts you follow and read them as their own timeline (`/api/v1/lists`, `/api/v1/timelines/list/{id}`), from the **Lists** section of the sidebar or any Mastodon client. A list is private to whoever made it. They are made, renamed, deleted and filled in **Settings → Lists**, or from **Add to list** on anybody's profile.
- 👥 **Your Nextcloud groups, as lists** — every Nextcloud group you are in is a list of yours, automatically: "Design", "Berlin office", whatever the group is called, holding the group's members who have a Social account. Nobody builds it and nobody maintains it — membership follows the group the moment somebody is added or removed, a renamed group renames its list, a deleted group takes its lists with it, and a background pass reconciles what the events did not see. A group list shows you only what you could see anyway (the list timeline applies the same visibility rules as your feed), nothing is followed by being in one, and nothing federates: a list is a view, not a relationship. Groups larger than 500 members get no list — that would be the Local timeline under another name. The `List` entity carries the group as `nextcloud_group`, and a group list's title and membership cannot be edited through the API (a **422** says why); leaving the group is what removes it.
- 🔑 **Mastodon-compatible API** — the Mastodon client API's core surface plus OAuth 2 authorization: read every timeline, post (with media and polls), follow/unfollow, favourite/boost/bookmark, search (`/api/v2/search`), manage follow requests and report. **Third-party Mastodon clients cannot reach it yet**: every route is served under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base, so a client asked for your domain looks for `/api/v1/...` and finds nothing. Serving those paths at the domain root is the one thing standing between this and stock clients — see [docs/Mastodon-Compatibility.md](https://github.com/nextcloud/social/blob/master/docs/Mastodon-Compatibility.md). No streaming endpoint or push subscriptions either — clients poll. See [docs/API.md](https://github.com/nextcloud/social/blob/master/docs/API.md) for exactly which routes exist.

### 🚧 Not implemented yet

These are absent from the code today, not merely rough edges:

- **No status translation.** The `translate` action returns the post unchanged (`lib/Service/ActionService.php`).
- **No custom emoji of this instance's own.** Emoji from other servers render; `/api/v1/custom_emojis` returns an empty list (`lib/Controller/ApiController.php`, `customEmojis()`).
- **No streaming API and no push subscriptions.** Third-party clients poll. (The web client does get live timelines when [notify_push](https://github.com/nextcloud/notify_push) is installed — that is a Nextcloud channel, not a Mastodon one.)

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

Administration → Overview reports both of these — and two more things that break
federation quietly — without anybody having to open Social. See
[docs/Admin.md](https://github.com/nextcloud/social/blob/master/docs/Admin.md)
for the whole setup, every configuration key and what to watch.

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
npm run typecheck           # tsc over the plain-JS half of src/, against src/types/
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

### Browser tests

`tests/e2e/` drives a real Nextcloud with the app installed, in Chromium,
through [Playwright](https://playwright.dev): sign in, open the app, write a
post and see it in the feed, walk the Discover page, switch scopes, open a
group list. Nothing is mocked, and the bundle under test is the committed one
in `js/`. `.github/workflows/e2e.yml` sets up a throwaway server for every pull
request; against an instance of your own:

```
npx playwright install chromium
E2E_BASE_URL=https://cloud.example E2E_USER=alice E2E_PASSWORD=… E2E_GROUP="Design" npm run test:e2e
```

`E2E_GROUP` is optional — the display name of a Nextcloud group the account is
in, for the list test; without it that test is skipped.

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
- [docs/Admin.md](https://github.com/nextcloud/social/blob/master/docs/Admin.md)
  is the administrator's guide: what federation needs before it works, every app
  configuration key with its meaning and default, the sections of the
  administration page, how moderation is recorded, and the occ commands by task.
- [docs/Mastodon-Compatibility.md](https://github.com/nextcloud/social/blob/master/docs/Mastodon-Compatibility.md)
  answers how close this is to Mastodon in the three senses that can mean —
  whether its clients work, whether peers can tell the difference, and whether an
  instance could move onto it. Its last section is the backlog that follows:
  everything still between this app and a full replacement, in tiers, with what
  each item actually fixes and whether it is done.
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
