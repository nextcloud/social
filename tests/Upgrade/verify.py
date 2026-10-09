#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Reads back, through the client API of the upgraded app, what seed.sh made
# with the old release: every post where it was, with its like, boost, reply
# and picture, the follow still standing, and the followers-only post still
# hidden from a reader who is not logged in. Exits non-zero on the first thing
# missing.
#
#   NC_URL=http://localhost:8080 python3 tests/Upgrade/verify.py

import base64
import json
import os
import sys
import urllib.error
import urllib.request

NC_URL = os.environ.get('NC_URL', 'http://localhost:8080')
SEED_STATE = os.environ.get('SEED_STATE', '/tmp/social-upgrade-seed.json')
PASSWORD = 'Upgrade-Test-2026!'
API = NC_URL + '/index.php/apps/social/api/v1'


def get(url, user=None):
    request = urllib.request.Request(url, headers={'OCS-APIRequest': 'true', 'Accept': 'application/json'})
    if user is not None:
        token = base64.b64encode(f'{user}:{PASSWORD}'.encode()).decode()
        request.add_header('Authorization', 'Basic ' + token)
    try:
        with urllib.request.urlopen(request) as response:
            return response.status, response.headers.get('Content-Type', ''), response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get('Content-Type', ''), error.read()


def get_json(url, user=None):
    status, _, body = get(url, user)
    check(status == 200, f'GET {url} answered {status}: {body[:300]!r}')
    return json.loads(body)


failures = []


def check(condition, message):
    if not condition:
        failures.append(message)
        print('FAIL ' + message)
    return condition


def find(statuses, text):
    return next((s for s in statuses if text in s.get('content', '')), None)


seed = json.load(open(SEED_STATE))

alice = get_json(API + '/accounts/lookup?acct=alice', 'bob')
relationship = get_json(API + '/accounts/relationships?id[]=' + str(alice['id']), 'bob')
check(relationship and relationship[0].get('following') is True, f'bob no longer follows alice: {relationship}')

home = get_json(API + '/timelines/home?limit=40', 'bob')
for key in ('public', 'followers', 'reply', 'media'):
    check(find(home, seed[key]) is not None, f'the {key} post is missing from bob\'s home timeline')

public = find(home, seed['public'])
if public is not None:
    check(public.get('favourited') is True, 'bob\'s like of the public post is gone')
    check(public.get('reblogged') is True, 'bob\'s boost of the public post is gone')
    check(any(t.get('name') == 'upgradeseed' for t in public.get('tags', [])), f'the hashtag is gone: {public.get("tags")}')
    # the totals, as a reader who is not logged in sees them: a logged-in
    # reader has them hidden by default
    totals = get_json(API + '/statuses/' + str(public['id']))
    for count in ('favourites_count', 'reblogs_count', 'replies_count'):
        check(totals.get(count, 0) >= 1, f'the public post\'s {count} is {totals.get(count)}')
    context = get_json(API + '/statuses/' + str(public['id']) + '/context', 'bob')
    check(find(context.get('descendants', []), seed['reply']) is not None, 'the reply is no longer threaded under the public post')

media = find(home, seed['media'])
if media is not None and check(len(media.get('media_attachments', [])) == 1, f'the picture is gone: {media.get("media_attachments")}'):
    attachment = media['media_attachments'][0]
    check(attachment.get('type') == 'image', f'the attachment is no longer an image: {attachment.get("type")}')
    check(attachment.get('description') == 'a blue square', f'the attachment lost its description: {attachment.get("description")}')
    status, content_type, body = get(attachment['url'], 'bob')
    check(status == 200 and content_type.startswith('image/') and len(body) > 0,
          f'the picture does not load: {status} {content_type} at {attachment["url"]}')

followers = find(home, seed['followers'])
if followers is not None:
    check(followers.get('visibility') == 'private', f'the followers-only post is now {followers.get("visibility")}')
    status, _, body = get(API + '/statuses/' + str(followers['id']))
    check(status != 200 or seed['followers'].encode() not in body, 'the followers-only post is readable without logging in')

public_profile = get_json(API + '/accounts/' + str(alice['id']) + '/statuses?limit=40')
check(find(public_profile, seed['public']) is not None, 'the public post is missing from alice\'s profile for a reader who is not logged in')
check(find(public_profile, seed['followers']) is None, 'the followers-only post is on alice\'s profile for a reader who is not logged in')

if failures:
    print(f'{len(failures)} check(s) failed after the upgrade')
    sys.exit(1)
print('everything seeded before the upgrade is still there')
