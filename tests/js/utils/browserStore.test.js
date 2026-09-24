/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getCurrentUser } from '@nextcloud/auth'
import { forgetUnscoped, userKey } from '../../../src/utils/browserStore.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => null) }))

describe('what this app keeps in the browser', () => {
	beforeEach(() => {
		localStorage.clear()
		getCurrentUser.mockReturnValue(null)
	})

	it('keeps each account\'s under its own name', () => {
		getCurrentUser.mockReturnValue({ uid: 'alice' })
		const alice = userKey('social.thing')

		getCurrentUser.mockReturnValue({ uid: 'bob' })

		expect(userKey('social.thing')).not.toBe(alice)
	})

	// Signed out there is no reader to keep anything for, and nothing written
	// then is worth separating.
	it('uses the bare name when nobody is signed in', () => {
		expect(userKey('social.thing')).toBe('social.thing')
	})

	it('removes what was kept before any of this', () => {
		localStorage.setItem('social.thing', 'from before')

		expect(forgetUnscoped('social.thing')).toBe(true)
		expect(localStorage.getItem('social.thing')).toBeNull()
	})

	// A private window, or site data blocked: not being able to tidy up is no
	// reason to take the page down.
	it('says so rather than throwing when the store cannot be reached', () => {
		vi.spyOn(localStorage, 'removeItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(forgetUnscoped('social.thing')).toBe(false)
	})
})
