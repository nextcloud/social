<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testing the API

## Creating the App

This is the first request a 3rd party client would need to send:

```
curl -X POST https://your.nextcloud.com/index.php/apps/social/api/v1/apps \
  -d "client_name=curl&redirect_uris=https://test.example.net/oauth/redirected&scopes=write+read&website=https://test.example.net"
```

should returns:
>    ```
>{
>       "id": 1,
>       "name": "curl",
>       "website": "https://test.example.net",
>       "scopes": "write read",
>       "client_id": "zahscLgi9rZp5SpiOuXGHoqZGAziMhlXVgmTM4Fl",
>       "client_secret": "xcDAPQLISsAw4UKqrut1OarDuCXf3IzOJxXQesHs"
>}
>    ```



## Authorize App, identify Account

Open a browser and go to the generated URL using `client_id`:


> https://your.nextcloud.com/index.php/apps/social/oauth/authorize?response_type=code&scope=write+read&redirect_uri=https://test.example.net/oauth/redirected&client_id=zahscLgi9rZp5SpiOuXGHoqZGAziMhlXVgmTM4Fl


After authentication, using the credentials of your Nextcloud account, you will be redirected to `https://test.example.net/oauth/redirected?code=VcIgHmSYPYrgrHyM8kRDxf4Gz-dJOuoNBuEz9mlZtw4`



## Obtain token

Once you have a `code`:

```
curl -X POST https://your.nextcloud.com/index.php/apps/social/oauth/token \
 -d "client_id=zahscLgi9rZp5SpiOuXGHoqZGAziMhlXVgmTM4Fl&redirect_uri=https://test.example.net/oauth/redirected&client_secret=xcDAPQLISsAw4UKqrut1OarDuCXf3IzOJxXQesHs&grant_type=authorization_code&code=VcIgHmSYPYrgrHyM8kRDxf4Gz-dJOuoNBuEz9mlZtw4"
```

result will be:

>    ```
>{
>       "access_token": "7UnD7f1fbMUUGqRalX0cTSW5H-Ion40_at560DsvG1w",
>       "token_type": "Bearer",
>       "scope": "write read",
>       "created_at": 1600354593
>}
>    ```


## Testing the API

- A first request to check the app:

```
curl https://your.nextcloud.com/index.php/apps/social/api/v1/apps/verify_credentials \
  -H "Authorization: Bearer 7UnD7f1fbMUUGqRalX0cTSW5H-Ion40_at560DsvG1w"
```

should returns

>    ```
>{
>      "name": "curl",
>      "website": "https://test.example.net"
>}
>    ```


- Check the account:

```
curl https://your.nextcloud.com/index.php/apps/social/api/v1/accounts/verify_credentials \
  -H "Authorization: Bearer 7UnD7f1fbMUUGqRalX0cTSW5H-Ion40_at560DsvG1w"
```

should returns

>    ```
>{
>      "id": "42",
>      "username": "cult",
>      "acct": "cult",
>      "display_name": "cult",
>      "locked": false,
>      "bot": false,
>      "discoverable": false,
>      "group": false,
>      "created_at": "2020-09-15T13:45:07.000Z",
>      "note": "",
>      "url": "https://your.nextcloud.com/index.php/apps/social/@cult",
>      "avatar": "https://your.nextcloud.com/index.php/documents/avatar/a7ad599c-499a-4680-9f01-e7f57fbea631",
>      "avatar_static": "https://your.nextcloud.com/index.php/documents/avatar/a7ad599c-499a-4680-9f01-e7f57fbea631",
>      "header": "https://your.nextcloud.com/index.php/documents/avatar/a7ad599c-499a-4680-9f01-e7f57fbea631",
>      "header_static": "https://your.nextcloud.com/index.php/documents/avatar/a7ad599c-499a-4680-9f01-e7f57fbea631",
>      "followers_count": 2,
>      "following_count": 0,
>      "statuses_count": 12,
>      "last_status_at": "2020-09-15",
>      "source": {
>        "privacy": "public",
>        "sensitive": false,
>        "language": "en",
>        "note": "",
>        "fields": [],
>        "follow_requests_count": 0
>      },
>      "emojis": [],
>      "fields": []
>}
>    ```
