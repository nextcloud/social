/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { fieldLink, profileFields } from '../../../src/utils/profileFields.js'

describe('profileFields', () => {
	it('marks a row the server proved', () => {
		const [row] = profileFields([{
			name: 'Website',
			value: '<a href="https://example.org" rel="me">example.org</a>',
			verified_at: '2026-09-01T10:00:00.000Z',
		}])

		expect(row.href).toBe('https://example.org')
		expect(row.verified).toBe(true)
		expect(row.verifiedAt).toBe('2026-09-01T10:00:00.000Z')
	})

	it('leaves an unproved link alone', () => {
		const [row] = profileFields([{
			name: 'Website',
			value: '<a href="https://example.org">example.org</a>',
			verified_at: null,
		}])

		expect(row.href).toBe('https://example.org')
		expect(row.verified).toBe(false)
	})

	// a server that claims a verification for something that is not a link has
	// nothing to have verified; the tick would be a claim about plain text
	it('refuses a verification on a row that is not a link', () => {
		const [row] = profileFields([{
			name: 'Pronouns',
			value: 'they/them',
			verified_at: '2026-09-01T10:00:00.000Z',
		}])

		expect(row.href).toBe('')
		expect(row.verified).toBe(false)
	})

	it('refuses a verification on a link scheme it would not follow', () => {
		const [row] = profileFields([{
			name: 'Site',
			value: '<a href="javascript:alert(1)">click</a>',
			verified_at: '2026-09-01T10:00:00.000Z',
		}])

		expect(row.href).toBe('')
		expect(row.verified).toBe(false)
	})

	it('says not verified when the server sends no flag at all', () => {
		const [row] = profileFields([{ name: 'Site', value: 'https://example.org' }])

		expect(row.verified).toBe(false)
		expect(row.verifiedAt).toBe('')
	})
})

describe('fieldLink', () => {
	it('finds the address a value names', () => {
		expect(fieldLink('https://example.org/about')).toBe('https://example.org/about')
		expect(fieldLink('my page: http://example.org')).toBe('http://example.org')
	})

	// the same reading ProfileLinkVerifier::linkOf() makes: without a scheme
	// there is nothing the server would fetch, so there is no tick to offer
	it('finds none in a bare domain, an empty value or another scheme', () => {
		expect(fieldLink('example.org')).toBe('')
		expect(fieldLink('')).toBe('')
		expect(fieldLink('she/her')).toBe('')
		expect(fieldLink('javascript:alert(1)')).toBe('')
		expect(fieldLink('ftp://example.org')).toBe('')
	})

	it('stops the address at whitespace, as the server does', () => {
		expect(fieldLink('https://example.org and more')).toBe('https://example.org')
	})
})
