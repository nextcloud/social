/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { registerProfileSection } from '../../../src/services/profileSections.js'

describe('registerProfileSection', () => {
	it('registers immediately when the Profile app already created its registry', () => {
		const section = { id: 'social-profile-section' }
		const registerSection = vi.fn()

		expect(registerProfileSection({ ProfileSections: { registerSection } }, section)).toBe(true)
		expect(registerSection).toHaveBeenCalledOnce()
		expect(registerSection).toHaveBeenCalledWith(section)
	})

	it('registers synchronously when the Profile app creates its registry later', () => {
		const profile = {}
		const section = { id: 'social-profile-section' }
		const registerSection = vi.fn()

		expect(registerProfileSection(profile, section)).toBe(true)
		profile.ProfileSections = { registerSection }

		expect(registerSection).toHaveBeenCalledOnce()
		expect(registerSection).toHaveBeenCalledWith(section)
		expect(profile.ProfileSections).toHaveProperty('registerSection', registerSection)
		expect(Object.getOwnPropertyDescriptor(profile, 'ProfileSections')).toMatchObject({
			writable: true,
			value: { registerSection },
		})
	})

	it('leaves a non-configurable registry alone when it cannot be intercepted', () => {
		const section = { id: 'social-profile-section' }
		const registerSection = vi.fn()
		const profile = {}
		Object.defineProperty(profile, 'ProfileSections', {
			configurable: false,
			value: undefined,
		})

		expect(registerProfileSection(profile, section)).toBe(false)
		expect(registerSection).not.toHaveBeenCalled()
	})
})
