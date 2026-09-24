/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { nextCursor } from '../../../src/utils/linkHeader.js'

describe('nextCursor', () => {
	it('reads the max_id of the next page', () => {
		expect(nextCursor({
			link: '<https://cloud.example/api?limit=2&max_id=17-abc>; rel="next", <https://cloud.example/api?min_id=19-def>; rel="prev"',
		})).toBe('17-abc')
	})

	it('is empty on the last page, or without a header at all', () => {
		expect(nextCursor({ link: '<https://cloud.example/api?min_id=19>; rel="prev"' })).toBe('')
		expect(nextCursor({})).toBe('')
		expect(nextCursor(undefined)).toBe('')
	})

	it('decodes what the query string encoded', () => {
		expect(nextCursor({ Link: '<https://cloud.example/api?max_id=a%2Fb>; rel="next"' })).toBe('a/b')
	})
})
