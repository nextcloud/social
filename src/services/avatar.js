/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

/**
 * Where to find the reader's own face.
 *
 * The avatar route answers with `Cache-Control: immutable, max-age=86400`, so a
 * browser that has the picture will not ask again for a day -- not even to
 * revalidate. Uploading a new one in Personal settings therefore changed
 * nothing here until the day was up. The version Nextcloud bumps on every
 * change goes in the address to make it a different picture to the browser.
 *
 * `NcAvatar` does the same thing when it is given a `user`; these callers give
 * it a `url` instead, which it trusts as it stands, so the version has to be
 * here.
 *
 * @param {number} size how many pixels across, as the route wants it
 * @return {string} the address, or an empty string when nobody is logged in
 */
export function ownAvatarUrl(size = 64) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		return ''
	}

	// 0 for an account that never changed its picture, which is what the
	// server reports for one and is a perfectly good cache key.
	const version = window.oc_userconfig?.avatar?.version ?? 0

	return generateUrl('/avatar/{uid}/{size}', { uid, size }) + '?v=' + version
}
