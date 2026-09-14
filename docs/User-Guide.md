# User guide

How to use Nextcloud Social as a person with an account on this Nextcloud:
getting onto the fediverse, following people, writing and reading posts, and
looking after the account. It describes the web client that comes with the
app; where something is only reachable through the Mastodon-compatible API or
a Mastodon client, it says so.

**Verified against:** app version 0.19.49, `master`, 2026-09-14 — every claim
below was checked against the code of that tree. Menu and button names are
the English strings of the web client.

## Contents

- [Getting an account](#getting-an-account)
- [Finding and following people](#finding-and-following-people)
- [Writing a post](#writing-a-post)
- [Reading](#reading)
- [Managing your account](#managing-your-account)
- [Keyboard shortcuts](#keyboard-shortcuts)
- [What the web client does not do yet](#what-the-web-client-does-not-do-yet)

---

## Getting an account

There is no sign-up. A Nextcloud account is the account: the first time you
open **Social** from the app menu, a fediverse identity is created for you —
depending on the version, straight away or after a short screen asking whether
you want one. Your handle is your Nextcloud user id — or, where that is not a
valid handle, a lower-cased copy of it with anything outside letters, digits,
`_`, `.` and `-` turned into an underscore — and your address is
`@handle@your-nextcloud-host`. Anyone on Mastodon, Pixelfed, PeerTube or any
other server that speaks ActivityPub can follow it.

Your display name and avatar come from your Nextcloud account and are changed
there. The rest of the profile — banner, bio, profile fields — is edited in
Social (see [Managing your account](#managing-your-account)).

On the first visit the app shows a four-step introduction: your address with a
copy button, people to follow, a way to bring the follows you already have from
another server, and a button that puts you in the composer. Every step can be
skipped, and once closed it does not come back.

## Finding and following people

- **Search.** The search box at the top of the sidebar looks through the
  people, hashtags and posts this server knows. Somebody this server has never
  met is found by their full handle, `@user@example.org`: the app resolves it
  over WebFinger and shows the account.
- **Discover** (in the sidebar) has five tabs: **People** (suggestions, with
  the people who share your Nextcloud and have said where they are on the
  fediverse listed first under *On your Nextcloud*), **Starter packs**,
  **Pictures** and **Videos** being looked at, and **Hashtags** that are
  trending, ranked over a window you pick.
- **Starter packs** are named handfuls of accounts with a **Follow everyone**
  button. Two ship with the app — the projects behind the network, and
  photography — and an administrator can curate more.
- **Follow** on a profile, in search results or on a Discover card sends a
  follow request. On an open account it is accepted at once; on a locked
  account the button reads **Requested** until the owner answers. **Unfollow**
  asks you to confirm, because on a locked account you would have to ask
  again.
- **Followed hashtags.** A hashtag timeline (`#tag` in a post, or a trending
  tag) has a follow button; following a tag puts the *public* posts carrying
  it into your home feed. It is not a relationship with anybody, and the tags
  you follow are listed above any hashtag timeline.
- **Coming from another server?** Every Mastodon-like server exports the
  people you follow as `following_accounts.csv`. Upload it in the first-run
  introduction or under **Settings → Migration** and each account is followed
  again from here.

## Writing a post

**New post** at the top of the sidebar, or `n`, puts the caret in the
composer; the composer also sits above most timelines. A post may be up to
5,000 characters, content warning included. What you type is kept as a draft
across a failed post, a reload or a navigation, so nothing is lost to a
network hiccup.

- **Visibility** is chosen with the globe menu next to the post button. The
  choice decides who receives the post on the fediverse, not only who sees it
  here:
  - **Public** — visible to everyone; appears in the Local and Global
    timelines of every server it reaches, and on your profile to anyone.
  - **Unlisted** — visible to everyone who has the link or follows you, but
    kept out of public timelines and discovery features.
  - **Followers** — delivered to your followers only. It cannot be boosted,
    pinned or quoted, because those would carry it to people it was not
    written for.
  - **Direct message** — delivered to the people mentioned in it and nobody
    else. The button will not send a direct post without at least one
    `@mention`.
- **Mentions and hashtags.** Type `@` and the first letters of a name to pick
  an account, or `#` for a hashtag; both are also recognised when typed in
  full. A mention makes that person a recipient of the post; a hashtag makes
  the post part of that tag's timeline everywhere it is delivered.
- **Content warning.** The speech-bubble button adds a warning field. Readers
  see the warning and a **Show more** button; the body stays out of the page
  until they ask. Attachments on a post with a warning are treated as
  sensitive. Warnings written on other servers are honoured here the same way.
- **Attachments.** Up to ten per post: pictures (JPEG, PNG, GIF, WebP, AVIF,
  and HEIC/HEIF from a phone, converted on the way in), video (MP4, WebM,
  QuickTime) and audio (MP3, AAC, OGG/Opus, WAV, FLAC). Drag them in, use
  **Add attachment**, or **Add from Files** to attach something already in
  your Nextcloud without uploading it again — the Files app's own menu also
  has **Share to Social** for the same thing. Every picture has its Exif
  metadata (including the location a phone writes) removed before it is
  stored or sent anywhere. A picture can be given one of eight mild filters,
  previewed live and baked into the copy that is posted.
- **Alt text.** Each attachment has a **Describe this for people who cannot
  see it** field. Posting with an undescribed attachment shows a nudge, never
  a refusal.
- **Polls.** **Add poll** gives two to four options, a **Multiple choice**
  switch and a duration from 30 minutes to 7 days. Readers on any server
  vote; the totals come back and are shown under the poll, with when it
  closes or that it has.
- **Emoji.** The smiley opens a Unicode emoji picker. A custom emoji this
  instance publishes is written by its shortcode, `:name:`, and renders here
  and on other servers.
- **Replying and quoting.** **Reply** under a post opens the composer
  addressed to its author; **Quote** in a post's menu embeds it in yours. Only
  public and unlisted posts held by this server can be quoted, and the quoted
  author receives the post that carries their words.

Your own posts carry more in their `…` menu: **Edit** (saved and sent to the
servers that have the post as an update), **Delete** (removed here, and a
deletion is sent to every server that received it — this cannot be undone),
**Pin to profile** (up to five public or unlisted posts), and **Delivery
status**, which says which servers received the post, which are still being
tried and which gave up.

## Reading

The sidebar is the map:

- **My Feed**, **Local**, **Global** — a switcher above the posts. My Feed is
  what the people and hashtags you follow post; Local is every public post
  written on this Nextcloud; Global is every public post this server has
  received. **Photos** and **Videos** carry the same switcher over posts that
  are pictures, or videos — including videos from PeerTube channels anyone
  here follows.
- **Activities** — your notifications: mentions, favourites, boosts, new
  followers and follow requests, poll results, edits of posts you boosted.
  The sidebar badge counts what arrived since you last looked, and the same
  events reach the Nextcloud bell and its mail digest; a follow request can be
  accepted or declined from the bell itself.
- **Direct messages** — the posts addressed to you and nobody else, with the
  composer already set to direct.
- **Lists** — each list you have is a timeline of its own. Every Nextcloud
  group you belong to (up to 500 members) is a list automatically, kept in
  step with the group. Other lists are made through the API or a Mastodon
  client; the sidebar shows them all.
- **Liked posts** and **Bookmarks** — what you favourited, and what you saved
  with **Bookmark** in a post's menu. Bookmarks are private and never leave
  this server.
- **Statistics** — what you have posted here and what came back: likes,
  boosts and replies per post, your best posts, when your posts do best, and
  which of your hashtags work.

Open a post (its timestamp, `o` or `Enter`) for its own page: the thread, a
reply box, who boosted and favourited it, and the details a card leaves out —
exact time, audience, language, whether it was edited, and a link to the
original on its home server. **Like**, **Boost** and **Reply** sit under every
post; a post's `…` menu adds **Bookmark**, **Open on original instance** and
**Report**.

A post that links somewhere gets a preview card, read by this server rather
than by your browser. With the `notify_push` app installed, new posts arrive
live; without it the page checks every 30 seconds.

## Managing your account

- **Profile.** **My profile** (behind your portrait at the bottom of the
  sidebar) → **Edit profile**: a banner (upload one or give the address of
  one), a bio, and up to four name/value fields shown under it. A field whose
  value is a web page gets a verified tick once that page links back to your
  profile with `rel="me"`. All of it is shared with other servers.
- **Blocking and muting.** Both are in the menu on an account's profile.
  **Block** severs the relationship in both directions, hides the account
  everywhere, and tells the other server (unless the administrator turned
  that off). **Mute** hides the account from your timelines and notifications
  without it ever knowing. **Blocked and muted accounts**, under **Settings**
  in the sidebar, lists both with unblock and unmute inline.
- **Follow requests.** When your account is locked, people asking to follow
  you appear under **Follow requests** in the sidebar — **Accept** or
  **Reject** — and on the Nextcloud bell. Locking the account itself is done
  through the API (`locked` on `PATCH /api/v1/accounts/update_credentials`)
  or a Mastodon client; the web client has no switch for it yet.
- **Reporting.** **Report** in a post's menu sends the post, with an optional
  note, to the moderators of this instance. From the web client it is never
  sent to the reported account or their server.
- **Export and migration.** **Settings → Migration** has three sections.
  **Export** downloads a zip of your profile, follows, followers, blocks,
  mutes, bookmarks, likes and every post you wrote — your private key is
  deliberately not in it. **Import** reads such an archive (or a Nextcloud
  account export) back in without deleting anything; posts in it are listed,
  not published again. The third section imports the follows from another
  network's `following_accounts.csv`, and explains that moving your *followers*
  here is a one-way move that an administrator performs with
  `occ social:account:alias` and `occ social:account:move`.
- **Leaving.** Deleting the Nextcloud user deletes the Social account with
  it: what you posted is dropped and a deletion is sent to the servers that
  saw it.

## Keyboard shortcuts

Press `?` anywhere, or open **Settings → Keyboard shortcuts**, for the list.
Shortcuts are off while you are typing in a field or the composer, and while a
modifier key is held.

| Key | Does |
|---|---|
| `j` | Next post |
| `k` | Previous post |
| `l` or `f` | Like the post in focus |
| `b` | Boost the post in focus |
| `r` | Reply to the post in focus |
| `o` or `Enter` | Open the post in focus |
| `n` | Write a new post |
| `g` | Go to the home timeline |
| `?` | Show these shortcuts |

`Enter` on a focused button or link still presses it — the shortcut only
applies where nothing else claims the key.

## What the web client does not do yet

These exist in the server and are reachable through the Mastodon-compatible
API, but the web client has no page for them: creating and editing **lists**,
**locking** your account, **scheduled posts**, **collections**, **stories** and
**places**. Third-party Mastodon clients cannot connect to this server yet,
because the API is served under the app's own path rather than at the domain
root (see [Mastodon-Compatibility.md](Mastodon-Compatibility.md)); until that
changes, the web client is the client. There is no post translation and no
streaming API.
