#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Builds the appstore tarball.
#
# This used to carry its own copy of the Makefile's rsync exclusion list. Two
# lists meant two sources of truth, and they had already drifted: both still
# excluded a `cypress.json` that no longer existed while neither excluded the
# `cypress.config.ts` that did, so dev config shipped to users. The Makefile's
# `appstore` target is what the release workflow runs, so it is the only list.

set -e
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec make appstore
