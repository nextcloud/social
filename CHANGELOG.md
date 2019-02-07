# Changelog


## 0.1.4

- [federated] the app now accept redirection on outgoing request.
- [global] the app is now compatible with custom apps folder.
- [UI] cache issue on some setup should also be fixed.


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
