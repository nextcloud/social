/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { nextCursor } from '../../../src/utils/linkHeader.js'

describe('nextCursor', () => {
	it('takes the max_id of the next link and leaves the prev link alone', () => {
		const link = '</x?limit=20&max_id=81>; rel="next", </x?min_id=100>; rel="prev"'

		expect(nextCursor({ link })).toBe('81')
	})

	it('keeps a snowflake cursor exact', () => {
		expect(nextCursor({ Link: '</x?max_id=1789553297940456473>; rel="next"' })).toBe('1789553297940456473')
	})

	it('says there is no next page when there is no header, or only a prev link', () => {
		expect(nextCursor({})).toBe('')
		expect(nextCursor(undefined)).toBe('')
		expect(nextCursor({ link: '</x?min_id=100>; rel="prev"' })).toBe('')
	})
})
