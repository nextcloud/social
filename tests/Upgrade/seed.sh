#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Seeds an instance running an older release of the app with the data an
# upgrade must carry over: two accounts, a public post, a followers-only post,
# a reply, a like, a boost, a follow, a post with a picture and a registered
# client app. Writes what it made to $SEED_STATE for verify.py to look for
# after the upgrade.
#
# Only commands and routes every release since 0.10 has are used here, so the
# script runs against the old code. Run from the server root, with the server
# answering at $NC_URL.
#
#   NC_URL=http://localhost:8080 bash tests/Upgrade/seed.sh

set -euo pipefail

NC_URL=${NC_URL:-http://localhost:8080}
SEED_STATE=${SEED_STATE:-/tmp/social-upgrade-seed.json}
PASSWORD='Upgrade-Test-2026!'
API="$NC_URL/index.php/apps/social/api/v1"

occ() {
	php -d memory_limit=1G occ "$@"
}

# the id of the note in a `social:note:create` result
note_id() {
	sed -n '/^object: /,/^token: /p' | sed -e '1s/^object: //' -e '$d' \
		| python3 -c 'import json, sys; print(json.load(sys.stdin)["object"]["id"])'
}

api() {
	local user=$1
	shift
	curl -sf -u "$user:$PASSWORD" -H 'OCS-APIRequest: true' "$@"
}

installed=$(occ config:app:get social installed_version)
before_0_23=false
if [ "$(printf '%s\n0.23.0\n' "$installed" | sort -V | head -1)" != '0.23.0' ]; then
	before_0_23=true
fi

# the app fetches its own avatars and actors over HTTP, from this same host
occ config:system:set allow_local_remote_servers --value true --type boolean
occ config:system:set overwrite.cli.url --value "$NC_URL"
occ config:app:set social cloud_url --value "$NC_URL"
occ config:app:set social social_url --value "$NC_URL/index.php/apps/social/"

for user in alice bob; do
	OC_PASS=$PASSWORD occ user:add --password-from-env "$user"
	if $before_0_23; then
		# these releases fetch the new account's avatar from the host without
		# its port and fail creating it; at -1 the avatar is fetched later
		# instead
		occ user:setting "$user" avatar version -- -1
	fi
	occ social:account:create "$user"
done

marker=$(date +%s)
public_text="Upgrade seed: a public post $marker"
followers_text="Upgrade seed: for followers only $marker"
reply_text="Upgrade seed: a reply $marker"
media_text="Upgrade seed: a picture $marker"

occ social:account:following bob alice --local
public_id=$(occ social:note:create --hashtag upgradeseed alice "$public_text" | note_id)
occ social:note:create --type followers alice "$followers_text" | note_id > /dev/null
occ social:note:create --replyTo "$public_id" bob "$reply_text" | note_id > /dev/null
occ social:note:like bob "$public_id" > /dev/null
occ social:note:boost bob "$public_id" > /dev/null

if $before_0_23; then
	php "$(dirname "${BASH_SOURCE[0]}")/duplicate-follow.php"
fi

picture=$(mktemp -d)/seed.png
php -r '$i = imagecreatetruecolor(32, 32); imagefill($i, 0, 0, imagecolorallocate($i, 30, 120, 200)); imagepng($i, $argv[1]);' "$picture"
media_id=$(api alice -F "file=@$picture;type=image/png" -F 'description=a blue square' "$API/media" \
	| python3 -c 'import json, sys; print(json.load(sys.stdin)["id"])')
# what the release kept of the picture's description, which is what the
# upgrade has to keep (0.10 drops it)
media_description=$(api alice -X POST --data-urlencode "status=$media_text" -d "media_ids[]=$media_id" -d visibility=public \
	"$API/statuses" | python3 -c 'import json, sys; print(json.dumps(json.load(sys.stdin)["media_attachments"][0].get("description")))')

client=$(curl -sf -X POST -d client_name=UpgradeSeed -d redirect_uris=urn:ietf:wg:oauth:2.0:oob -d scopes=read "$API/apps")

python3 - "$SEED_STATE" "$client" "$media_description" <<PY
import json, sys
json.dump({
    "public": "$public_text",
    "public_id": "$public_id",
    "followers": "$followers_text",
    "reply": "$reply_text",
    "media": "$media_text",
    "media_description": json.loads(sys.argv[3]),
    "client": json.loads(sys.argv[2]),
}, open(sys.argv[1], "w"), indent=1)
PY
echo "seeded: $(cat "$SEED_STATE")"
