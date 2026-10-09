/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'

/**
 * Every run reads dates in the same zone (see vitest.config.js), so a test
 * that only passes in the author's own zone fails for its author too.
 */
describe('the time zone tests run in', () => {
	const zone = process.env.SOCIAL_TEST_TZ || 'UTC'

	it('is UTC unless SOCIAL_TEST_TZ names another', () => {
		expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe(zone)
	})
})
