#!/bin/sh
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Prints an access token for one GoToSocial account, minted the way a client
# app mints one: an app registration, the account's sign-in on GoToSocial's
# own form, the authorize form answered "Allow", and the out-of-band code
# exchanged at /oauth/token. GoToSocial has no command that hands out a token.
#
#   token.sh BASE_URL EMAIL PASSWORD
#
# curl uses the system's certificate store; set CURL_CA_BUNDLE for another.
set -eu

base=${1%/}
email=$2
password=$3
scopes='read write follow'
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT

field() {
	python3 -c 'import json, sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"
}

app=$(curl -sf -H 'Content-Type: application/json' \
	-d "{\"client_name\": \"interop\", \"redirect_uris\": \"urn:ietf:wg:oauth:2.0:oob\", \"scopes\": \"$scopes\"}" \
	"$base/api/v1/apps")
client_id=$(printf '%s' "$app" | field client_id)
client_secret=$(printf '%s' "$app" | field client_secret)

authorize="$base/oauth/authorize?client_id=$client_id&redirect_uri=urn:ietf:wg:oauth:2.0:oob&response_type=code&scope=$(printf '%s' "$scopes" | tr ' ' '+')"
# the first visit only sets the session the sign-in form continues
curl -sf -c "$jar" -b "$jar" -o /dev/null "$authorize"
curl -sf -c "$jar" -b "$jar" -o /dev/null \
	--data-urlencode "username=$email" --data-urlencode "password=$password" "$base/auth/sign_in"
curl -sf -c "$jar" -b "$jar" -o /dev/null "$authorize"
# "Allow" redirects to /oauth/oob?code=…
location=$(curl -s -c "$jar" -b "$jar" -X POST -o /dev/null -w '%{redirect_url}' "$base/oauth/authorize")
code=${location##*code=}
if [ -z "$code" ] || [ "$code" = "$location" ]; then
	echo "no authorization code from $base (redirected to '$location')" >&2
	exit 1
fi

curl -sf -H 'Content-Type: application/json' \
	-d "{\"grant_type\": \"authorization_code\", \"client_id\": \"$client_id\", \"client_secret\": \"$client_secret\", \"redirect_uri\": \"urn:ietf:wg:oauth:2.0:oob\", \"code\": \"$code\"}" \
	"$base/oauth/token" | field access_token
