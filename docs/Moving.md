<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Moving to Aloha Social, and moving away

One page for the whole move, in the order it happens. Each button it names
is described on its own in the [User Guide](User-Guide.md#settings) and
each route in the [API reference](API.md#migration); this is the order, and
what does not move.

## What a move is, on the fediverse

A move carries **followers**, never posts. Your old server tells every server
that knows you to follow your new account instead; your posts stay where they
were written, under the old server's addresses. That is how Mastodon does it
too, and it is the right design: a post is signed by the server it was written
on, and nobody else can re-issue it.

Two things follow from that. Your new account can **copy** your old posts, as
new posts of its own, so your profile here is not empty — but they are copies,
nothing is sent to anybody, and the replies and boosts on the originals stay on
the originals. And the old server has to be **running** for any of this: it
sends the move, and it serves your posts and the people you follow. An account
on a server that is gone cannot be moved; only a follows file exported in time
survives it.

The same happens in the other direction when somebody *you* follow moves:
their old server sends the move here, this server checks that the new account
names the old one, follows the new account on your behalf, and shows the
redirect on the old account's profile from then on.

## Moving to Aloha Social

Three steps, two of them here.

1. **Make your account here**, on the setup screen. On its third step,
   *Already somewhere else?*, type the handle of your old account —
   `@you@mastodon.example` — and press **Move here**. You can do the same
   later, at the top of **Settings → Migration**, where you are first shown
   who that account is and what its server lets us read.

   What happens: this account is marked as also being the old one (the
   `alsoKnownAs` the old server will ask for), and in the background everyone
   the old account follows is followed from here and its public posts are
   written here as yours, dated when you wrote them, with their pictures.
   Nothing is sent to anybody. The list on the Migration page shows where the
   run has got to. Posts you write on the old account after that are not
   picked up by anything: **Copy newer posts**, under the finished run, runs
   the copy again and brings over only what is new.

2. **If the old server hides your follows or your posts** — Mastodon does
   when you turned on *Hide your social graph*, Pixelfed always does — the
   page says so, and the file imports further down are for that: upload the
   old server's `following_accounts.csv` (Pixelfed: `pixelfed-following.json`)
   and, for the posts, its archive. Blocks, mutes, lists, bookmarks and blocked
   domains come the same way.
   Every upload runs in the background too.

3. **On the old server, move your followers here.** On Mastodon that is
   *Preferences → Account → Move to a different account*; enter your handle
   here. The Migration page shows it once the run is done. Your old server
   then tells every server that knows you to follow this account instead, and
   you are moved.

Pixelfed cannot do step 3: it has no account migration. Your follows and your
posts come over; your followers have to follow you again.

**From another Aloha Social server, step 3 is here too.** Mastodon has no way
to ask for a move from outside, which is why the last step is always done on
the old server. Another Aloha Social does: once the run is through, the
Migration page offers **Finish the move from here**. You are sent to your old
Nextcloud to log in and to agree — the only permission asked for is to move
the account, nothing else — and the old server moves your followers here.
It checks, as it would for its own button, that this account names the old
one; the thirty days between moves apply there as everywhere.

## Moving away

1. **On the new account, name this one** as an account you also answer to.
   On Mastodon: *Preferences → Account → Moving from a different account*,
   with your handle here.

2. **Here, in Settings → Migration → Move your account away**, type the new
   account's handle, type your own handle to confirm, and give your password
   when asked. Every server that knows you is told to follow the new account
   instead; this account is marked as moved, shows where you went, and refuses
   to post or follow while it stays moved. Your posts stay here.

You can move again after thirty days. **Undo the move** takes the redirect off
and lets you post and follow from here again — but your followers do not come
back by themselves, because their servers acted on the move when it arrived.
Take an export from the same page first if you want a copy of everything.

An administrator can move an account for somebody with
`occ social:account:move`, which skips the thirty days.

## Your Bluesky account

If this server puts you on Bluesky too, your Bluesky account is a second
account of the same person, and it can move on its own.

**Moving it to another Bluesky server.** In *Settings → Migration → Move
your Bluesky account away*, name the server, the handle you want there (one
ending in a domain that server gives out), an e-mail address and a password
for the account there. The account is made there at once; your posts,
pictures and settings are copied in the background; then your account is
pointed at the new server and switched on there. Your followers on Bluesky
follow you there without doing anything.

- **Who controls the account afterwards.** You, with your recovery phrase if
  you made one, and the new server. This server keeps no say. Without a
  recovery phrase only the new server controls the account, and the page
  asks you to confirm that before the move starts.
- **Posts you write here during the move** wait, and go along right before
  your account is pointed at the new server.
- **Missing pictures.** Before the switch the new server is asked whether
  everything arrived. If pictures or videos are missing, the move waits for
  you: copy again, or switch without them.
- **Calling it off.** Until your account is pointed at the new server you can
  call the move off. The account made at the new server stays there,
  switched off; delete it there. Once the switch happened, the move can only
  be finished: *Try again* goes on from where it stopped.
- **Telling your other followers.** You can ask for one public post, or a
  profile field *Also at*, saying where your Bluesky account went. Both are
  ordinary posts and fields, yours to edit or delete.

**Moving your Fediverse account away** leaves the Bluesky account behind on
this server, and a moved account posts nothing more here. So the move asks
what becomes of it: switch it off (the default — it is shown as inactive on
Bluesky; your handle stays yours and Settings can switch it on again), keep
it as it is, or say that it moves too — which is only possible once its own
move above is done or under way. `occ social:account:move` takes the same
choice as `--bluesky`.

## What does not move, whatever you do

- **Posts.** Copied, never moved: see above.
- **Followers from a server that does not implement `Move`.** Pixelfed is the
  one that matters.
- **Your handle.** `@you@old.example` stays on the old server; here you are
  `@you@this.server`. Keeping a handle across a change of domain is a server
  migration, not an account one — see
  [Mastodon-Compatibility.md](Mastodon-Compatibility.md#54-what-would-have-to-be-written).
- **Direct messages and boosts** are left out of the post copy on purpose.
