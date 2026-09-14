/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLanguage } from '@nextcloud/l10n'
import {
	POST_LANGUAGES,
	defaultLanguage,
	isLanguageCode,
	languageName,
	rememberLanguage,
	rememberedLanguage,
} from '../../../src/utils/postLanguage.js'

describe('defaultLanguage', () => {
	beforeEach(() => {
		localStorage.clear()
	})

	afterEach(() => {
		setLanguage('en')
		vi.restoreAllMocks()
	})

	it('is what Nextcloud is set to', () => {
		setLanguage('de')
		expect(defaultLanguage()).toBe('de')
	})

	/**
	 * `de_DE` and `en_GB` say how the interface is spelled. Mastodon's
	 * per-language filters are keyed by the plain `de`, so that is what a post
	 * is tagged with.
	 */
	it('drops the region of a locale', () => {
		setLanguage('pt_BR')
		expect(defaultLanguage()).toBe('pt')
		setLanguage('en-GB')
		expect(defaultLanguage()).toBe('en')
	})

	it('falls back to English where Nextcloud says nothing usable', () => {
		setLanguage('')
		expect(defaultLanguage()).toBe('en')
	})
})

describe('rememberedLanguage', () => {
	beforeEach(() => {
		localStorage.clear()
	})

	it('is empty until a language has been chosen', () => {
		expect(rememberedLanguage()).toBe('')
	})

	it('is the last language chosen', () => {
		rememberLanguage('fr')
		expect(rememberedLanguage()).toBe('fr')
	})

	it('ignores a stored value that is not a language code', () => {
		localStorage.setItem('social.lastLanguage', 'Deutsch')
		expect(rememberedLanguage()).toBe('')
	})

	it('refuses to store something that is not a language code', () => {
		rememberLanguage('not a language')
		expect(localStorage.getItem('social.lastLanguage')).toBeNull()
	})

	/**
	 * Reading and writing site data throws outright in a private window, and
	 * that is no reason to have no composer.
	 */
	it('survives a browser that refuses site data', () => {
		const getItem = vi.spyOn(localStorage, 'getItem').mockImplementation(() => {
			throw new Error('denied')
		})
		const setItem = vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(rememberedLanguage()).toBe('')
		expect(() => rememberLanguage('de')).not.toThrow()

		getItem.mockRestore()
		setItem.mockRestore()
	})
})

describe('the languages on offer', () => {
	it('are two-letter codes, each once', () => {
		expect(POST_LANGUAGES.every(isLanguageCode)).toBe(true)
		expect(new Set(POST_LANGUAGES).size).toBe(POST_LANGUAGES.length)
		expect(POST_LANGUAGES).toContain('en')
		expect(POST_LANGUAGES).toContain('de')
	})

	it('are named in the reader\'s own language', () => {
		setLanguage('en')
		expect(languageName('de')).toBe('German')
		setLanguage('de')
		expect(languageName('de')).toBe('Deutsch')
		setLanguage('en')
	})

	it('shows a code nobody knows as the code rather than as nothing', () => {
		expect(languageName('qqq')).toBe('qqq')
	})
})

describe('isLanguageCode', () => {
	it('accepts a language tag and nothing else', () => {
		expect(isLanguageCode('de')).toBe(true)
		expect(isLanguageCode('nan')).toBe(true)
		expect(isLanguageCode('d')).toBe(false)
		expect(isLanguageCode('de-DE')).toBe(false)
		expect(isLanguageCode('DE')).toBe(false)
		expect(isLanguageCode(null)).toBe(false)
		expect(isLanguageCode(42)).toBe(false)
	})
})
