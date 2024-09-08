<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# 3rd party fediverse clients

A decent number of clients for Mastodon and other Fediverse network are available on almost all devices/OS. This is a list of tested ones.


|         | Config | Auth | Timeline | Posting | Account |
|---------|---------------|-------|-----------|---------|---------|
||
| **Desktop** |
| Hyperspace | :negative_squared_cross_mark:
| Mast (Mac) |
| Mastonaut (Mac) |
| TheDesk | :heavy_check_mark: | :negative_squared_cross_mark:
| Tootle (Linux) | :negative_squared_cross_mark:
| Whalebird |  :negative_squared_cross_mark:
||
| **Android** |               |       |           |         |         |
| AndStatus | :negative_squared_cross_mark: |
| Asap | :negative_squared_cross_mark: |
| Avalanche | :heavy_check_mark: | :heavy_check_mark: |  :heavy_check_mark:
| Fedilab | :negative_squared_cross_mark: |
| Mammut | :heavy_check_mark: | :heavy_check_mark: |  :heavy_check_mark:
| Subway Tooter | :negative_squared_cross_mark: |
| Tusky | :negative_squared_cross_mark: |
| Twidere | :heavy_check_mark: | :heavy_check_mark: | :heavy_check_mark:
||
| **iOS** |
| Amaroq | :heavy_check_mark: | :negative_squared_cross_mark:
| Fedi | :negative_squared_cross_mark:
| iMast | :negative_squared_cross_mark:
| Librem Social | :heavy_check_mark: | :heavy_check_mark: |  :heavy_check_mark:
| Mast |
| Mercury | :negative_squared_cross_mark:
| Oyakodon | :negative_squared_mark:
| Roma | :negative_squared_cross_mark:
| Toot! |
| Tootle | :negative_squared_cross_mark:
||
| **Web** |
| Cuckoo+ | :negative_squared_cross_mark:
| Halcyon | :negative_squared_cross_mark:
| Pinafore | :heavy_check_mark: | :negative_squared_cross_mark:
||
| **SailfishOS** |
| Tooter |

_This list is not complete and only report which apps have been tested with Nextcloud Social._

## Configuration

Nextcloud Social, being an app for Nextcloud, is hosted in the `apps/` folder and not at the root of the domain meaning the path to the app needs to be configured:
When prompted, use `your-nextcloud/apps/social` as the address of the remote instance of Fediverse.

However, as shown in the table above, some clients will not allow you to add a path to the address of the instance.
