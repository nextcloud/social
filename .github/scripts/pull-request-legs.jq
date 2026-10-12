# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The PHPUnit legs a pull request runs, picked from the sparse matrix of
# icewind1991/nextcloud-version-matrix: the newest stable server branch, and
# each server named in $extra, on the newest PHP the matrix pairs it with.
# The nightly run takes the whole matrix instead.
#
#   jq -c --argjson extra '["master"]' -f .github/scripts/pull-request-legs.jq <<< "$MATRIX"

def version: split(".") | map(tonumber);

([.include[]."server-versions" | select(startswith("stable"))]
  | max_by(ltrimstr("stable") | tonumber)) as $stable
| .include |= (
    [.[] | select(."server-versions" | IN($stable, $extra[]))]
    | group_by(."server-versions")
    | map(max_by(."php-versions" | version)))
