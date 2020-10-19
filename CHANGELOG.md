# Changelog


## 0.4.1

- fixing an issue with primary key.


## 0.4.0 (alpha 3.2)

- [global] compat nc20


## 0.3.1 (alpha 3.1)

- [setup] notification during update.
- [setup] fixing some requests. 


## 0.3.0 (alpha 3.0)

- [setup] fully indexed database structure.
- [global] fixing issue with hashtags.
- [global] compat nc19.


## 0.2.101

- [setup] fixing an issue with migration on pgsql


## 0.2.100 (alpha 2.1)

- [setup] it is now possible to create an account using ./occ
- [setup] it is now possible to follow an account using ./occ
- [setup] new command to completely uninstall the app: ./occ social:reset --uninstall 
- [federated] better management of the ID generator.
- [federated] Caching now generate also a thumb version of remote Image document.
- [federated] fixing an issue with hashtags importation
- [global] better parsing on non-latin hashtags
- [global] Attachments (Image) should now be displayed.
- [global] like/unlike on Post.
- [global] new Timeline: Liked post.
- [global] better compatibility with Pleroma.
- [global] fixing an issue on exact result during search. 
- [global] cleaning code.



## 0.2.6

- [global] Improving and fixing streams.


## 0.2.5

- [setup] Fixing migrations (again)


## 0.2.4

- [setup] Rewrite of the announce system
- [setup] Fixing migrations
- [global] Fixing caching from 3rd party instance 
- [global] Fixing signature check
- [global] Fixing local and federated timeline
- [global] More debug logging
- [federated] Managing local and remote host-meta


## 0.2.3

- [global] reverting nextcloud to 0.9.x
- [global] reverting routes


## 0.2.2

- [setup] fixing an issue with empty boolean field during postgresql migration.
- [setup] enlarging some database field.


## 0.2.1

- [setup] fixing an issue with empty creation field during migration.


## 0.2.0 - alpha2


features:

- [global] Boosting Post.
- [global] Delete a Post.
- [UI] Following an account from an external website.
- [federated] Async on incoming request.
- [federated] Caching on incoming request.
- [federated] Caching incoming attachments.
- [federated] limit rights and access to/from the fediverse.


enhancements:

- [global] Complete SQL migration.
- [global] Timeline can now manage multiple type of Stream object.
- [global] More logs.
- [UI] Dark theme.
- [UI] Searching now send only limited request.
- [federated] Caching context content.
- [federated] Outgoing request accepts redirection.
- [federated] Removing an actor should deletes his posts.
- [setup] The app can now works on local address, with no SSL support.
- [setup] The app can be installed in custom apps folder.


bugfixes:

- [bugfix] public post counter now count only public post.



## 0.1.3

- [global] fixing an issue displaying timeline when opening details for a local account.
- [global] provide more details in logs on async failure.
- [global] Cache refreshing on some events (new post, new follow)
- [federated] adding security @context on returned Actor.
- [federated] signature can be 5min old (instead of 30s) to avoid issue on badly configured instance of Nextcloud.
- [federated] webfinger will also check the host of the account.
- [UI] do not clear post field on fail post creation. 


## 0.1.2

- [global] Fix Host Header on proxy setup
- [global] Delete object by objectId, not just by its Type+Id
- [global] Remove the ending slash from Id on webfinger
- [global] rewrite the exception handling during upstream request
- [global] Blind key rotation
- [request] Manage Update/Person and use signature date as creation on Person creation/update
- [request] check the status of queued request before forking process
- [request] fixing Accept header for Diaspora
- [UI] Improve account data handling
- [UI] Fix follow button on profile pages
- [UI] Add profile page for remote users
- [UI] Fix feedback on following the Nextcloud mastodon account


## 0.1.1

- [setup] the app is now displaying an issue with the setup of the httpd only if there is issue.
- [setup] fixing 'not-big-enough' fields in database.
- [setup] it is now possible, using ./occ social:reset, to reset all data from the app and change the base url of the cloud.
- [global] rework on some SQL request
- [global] complete rework on the ActivityPub Parser and ActivityPub Generator.
- [global] The app will now sign every ld-json object. 
- [global] The app will now verify every signed ld-json object. This should fix forwarded Note not being displayed in the streams.
- [global] Unfollowing a user will keep remote instance up-to-date.
- [UI] Some strange behavior while typing message have been fixed.
- [UI] Better display of the Public/Account page.
- [UI] Better UX when using the searchbar to search for a remote account. 
- [bugfix] Home Stream is working even when user is following no one.
- [bugfix] fixing quotes encoding.
- [bugfix] the cache refresher will now avoid ghost account.
- [bugfix] fixing minor issues.



## 0.1.0

- first alpha release
