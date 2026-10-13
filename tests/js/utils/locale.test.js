/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it } from 'vitest'
import { userLocale } from '../../../src/utils/locale.js'

describe('userLocale', () => {
	afterEach(() => {
		delete globalThis._nc_l10n_locale
		delete globalThis._nc_l10n_language
	})

	it('is the reader\'s Nextcloud locale, as a tag Intl reads', () => {
		globalThis._nc_l10n_locale = 'de_AT'
		globalThis._nc_l10n_language = 'fr'

		expect(userLocale()).toBe('de-AT')
	})

	it('falls back to the language the page is read in when no locale is set', () => {
		globalThis._nc_l10n_language = 'de'

		expect(userLocale()).toBe('de')
	})

	it('leaves the browser to choose when the page says nothing', () => {
		expect(userLocale()).toBeUndefined()
	})

	it('passes over a tag Intl does not accept', () => {
		globalThis._nc_l10n_locale = 'sr@latin'
		globalThis._nc_l10n_language = 'sr'

		expect(userLocale()).toBe('sr')
	})
})
