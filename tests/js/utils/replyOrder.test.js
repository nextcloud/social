/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { orderReplies } from '../../../src/utils/replyOrder.js'

const reply = (id, likes) => ({ id, favourites_count: likes })
const ids = (replies) => replies.map((status) => status.id)

describe('the order of the replies under a post', () => {
	const replies = [reply('1', 2), reply('2', 9), reply('3', 2), reply('4', 0)]

	it('is their order in time, oldest first, unless asked otherwise', () => {
		expect(ids(orderReplies(replies, 'oldest'))).toEqual(['1', '2', '3', '4'])
		expect(ids(orderReplies(replies, 'anything'))).toEqual(['1', '2', '3', '4'])
	})

	it('is newest first when asked', () => {
		expect(ids(orderReplies(replies, 'newest'))).toEqual(['4', '3', '2', '1'])
	})

	it('is the most liked first, equal ones in time, when asked for the top', () => {
		expect(ids(orderReplies(replies, 'top'))).toEqual(['2', '1', '3', '4'])
	})

	/** With the counts hidden the server sends none, and the conversation reads as it happened. */
	it('is the order in time when nothing was counted', () => {
		expect(ids(orderReplies([{ id: '1' }, { id: '2' }], 'top'))).toEqual(['1', '2'])
	})

	it('leaves the list it was given as it was', () => {
		const given = [...replies]
		orderReplies(given, 'top')
		orderReplies(given, 'newest')

		expect(ids(given)).toEqual(['1', '2', '3', '4'])
	})
})
