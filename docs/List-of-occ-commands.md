<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# List of occ commands

## ./occ social:cache:refresh

Command is used to refresh the cache.
Refreshing the cache means:

- fully recreate Actor Cache for local users
- recreate Actor Cache for remote users with cache older than 24h
- cache document in queue

Cache refreshing is done ever 15 minutes using the cron from Nextcloud


## ./occ social:note:create

Command test to create post.

-  --replyTo[=REPLYTO]  in reply to an existing thread (id of the post)
-  --to[=TO]            mentioning people (tag someone, or recipient for a direct message
-  --type[=TYPE]        type: public (default), followers, unlisted, direct

_ex: ./occ social:note:create --to cult@test.artificial-owl.com --type direct "A message to you"_


## ./occ social:queue:process

Process the request queue.
Only a small part of request are done directly when using the Social app. Most of the requests are done in a background process. This command will try to execute all awaiting requests.


## ./occ social:queue:status

This command returns details about queued requests for a specific command. Decentralized network means that every remote instance of ActivityPub needs to be updated on action that might affect them in one of those way:

- Remote instances with users that are following a user of your instance.
- Reply to a message from another instance.
- Direct message to a user from another instance.

When an action is done, a token is returned. Most of the time this token can be find in the Javascript console log of the browser:

_./occ social:queue:status --token be63ae0e-ecf4-4386-b645-8a41769de6c6_


## ./occ social:reset     

Reset all data from the Social App. This is a destructive action.
After the reset, the command allows the admin to change the base cloud address, which is used to identify your instance of ActivityPub.

![./occ fulltextsearch:test](https://raw.githubusercontent.com/nextcloud/social/master/docs/occ_social-reset.png)
