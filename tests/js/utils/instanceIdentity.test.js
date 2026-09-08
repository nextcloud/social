/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { instanceColour, instanceOf, originOf } from '../../../src/utils/instanceIdentity.js'

describe('instanceOf', () => {
	it('reads the host out of a remote handle', () => {
		expect(instanceOf('alice@example.org')).toBe('example.org')
		expect(instanceOf('alice@sub.example.org')).toBe('sub.example.org')
	})

	it('treats a bare handle as local', () => {
		expect(instanceOf('alice')).toBe('')
	})

	it('lowercases the host, so one instance gets one identity', () => {
		expect(instanceOf('alice@Example.ORG')).toBe('example.org')
	})

	it('takes the last @, as a handle written with a leading one still resolves', () => {
		expect(instanceOf('@alice@example.org')).toBe('example.org')
	})

	it('survives nonsense', () => {
		expect(instanceOf('')).toBe('')
		expect(instanceOf('@')).toBe('')
		expect(instanceOf(undefined)).toBe('')
		expect(instanceOf(null)).toBe('')
		expect(instanceOf(42)).toBe('')
	})
})

describe('instanceColour', () => {
	it('gives one host one colour, every time', () => {
		expect(instanceColour('example.org')).toBe(instanceColour('example.org'))
	})

	it('gives different hosts different colours', () => {
		const hosts = ['mastodon.social', 'chaos.social', 'example.org', 'nextcloud.com', 'fosstodon.org']
		const colours = new Set(hosts.map(instanceColour))

		expect(colours.size).toBe(hosts.length)
	})

	it('produces a usable hsl colour with a hue in range', () => {
		for (const host of ['a', 'example.org', 'a-very-long-hostname.example.co.uk']) {
			const colour = instanceColour(host)
			const hue = Number(colour.match(/^hsl\((\d+),/)[1])

			expect(hue).toBeGreaterThanOrEqual(0)
			expect(hue).toBeLessThan(360)
			// fixed saturation and lightness keep every instance equally loud
			expect(colour).toContain('62%, 52%')
		}
	})

	it('has no colour for a local account', () => {
		expect(instanceColour('')).toBe('')
	})
})

describe('originOf', () => {
	it('describes a remote account', () => {
		expect(originOf('bob@chaos.social')).toEqual({
			instance: 'chaos.social',
			colour: instanceColour('chaos.social'),
			local: false,
		})
	})

	it('describes a local account as local, with no colour to show', () => {
		expect(originOf('alice')).toEqual({ instance: '', colour: '', local: true })
	})
})
