/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The ways the replies directly under a post can be read: as a conversation,
 * oldest first; newest first; or the most liked first, which is what a video's
 * comments open on.
 *
 * @typedef {'oldest'|'newest'|'top'} ReplyOrder
 */

/** @type {ReplyOrder[]} */
export const REPLY_ORDERS = ['oldest', 'newest', 'top']

/**
 * The replies in the order asked for, from their order in time.
 *
 * Most liked is decided over the replies loaded here, by `favourites_count`:
 * the server hands a thread over in one order only. Equal counts keep their
 * order in time, so a reader who hides the counts, whose server sends none,
 * reads the conversation as it happened.
 *
 * @param {import('../types/Mastodon.js').Status[]} replies oldest first
 * @param {ReplyOrder|string} order how to read them
 * @return {import('../types/Mastodon.js').Status[]} a new array; the one passed is not changed
 */
export function orderReplies(replies, order) {
	if (order === 'newest') {
		return [...replies].reverse()
	}
	if (order === 'top') {
		const likes = (status) => Number(status?.favourites_count) || 0

		return replies
			.map((status, index) => ({ status, index }))
			.sort((a, b) => likes(b.status) - likes(a.status) || a.index - b.index)
			.map(({ status }) => status)
	}

	return [...replies]
}
