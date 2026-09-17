/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, test, vi } from 'vitest'
import { getCurrentUser } from '@nextcloud/auth'
import { ownAvatarUrl } from './avatar.js'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'alice' })),
}))

describe('ownAvatarUrl', () => {
	beforeEach(() => {
		getCurrentUser.mockReturnValue({ uid: 'alice' })
		delete window.oc_userconfig
	})

	test('carries the version the server reports for the picture', () => {
		window.oc_userconfig = { avatar: { version: 7, generated: false } }

		expect(ownAvatarUrl(64)).toMatch(/\/avatar\/alice\/64\?v=7$/)
	})

	test('asks for the size it is given', () => {
		window.oc_userconfig = { avatar: { version: 3 } }

		expect(ownAvatarUrl(32)).toMatch(/\/avatar\/alice\/32\?v=3$/)
	})

	test('defaults to 64 pixels', () => {
		expect(ownAvatarUrl()).toMatch(/\/avatar\/alice\/64\?v=/)
	})

	test('falls back to version 0 when nothing says otherwise', () => {
		expect(ownAvatarUrl(64)).toMatch(/\?v=0$/)
	})

	test('answers empty for a page with nobody logged in', () => {
		getCurrentUser.mockReturnValue(null)

		expect(ownAvatarUrl(64)).toBe('')
	})
})
