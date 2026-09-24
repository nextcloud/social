/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The `max_id` the server put in its `Link: …; rel="next"` header.
 *
 * A list whose cursor is not an id of the rows it returns — a conversation
 * paged on its latest message, a follow request paged on the request rather
 * than the account — can only be continued from there, never from the last
 * row on screen.
 *
 * @param {object} headers the response headers
 * @return {string} the cursor, or '' when the server said this is the last page
 */
export function nextCursor(headers) {
	const link = headers?.link ?? headers?.Link ?? ''
	for (const part of String(link).split(',')) {
		if (!/;\s*rel\s*=\s*"?next"?/.test(part)) {
			continue
		}

		const url = part.match(/<([^>]*)>/)?.[1]
		const cursor = url?.match(/[?&]max_id=([^&]*)/)?.[1]
		if (cursor) {
			return decodeURIComponent(cursor)
		}
	}

	return ''
}
