# Changelog


### 0.1.2

- [global] Fix Host Header on proxy setup
- [global] Delete object by objectId, not just by its Type+Id
- [global] Remove the ending slash from Id on webfinger
- [global] rewrite the exception handling during upstream request
- [global] Blind key rotation
- [request] Manage Update/Person and use signature date as creation on Person creation/update
- [request] check the status of queued request before forking process
- [request] fixing Accept header for Diaspora


### 0.1.1

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



### 0.1.0

- first alpha release
