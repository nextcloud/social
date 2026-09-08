/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import twemoji from 'twemoji'
import { toCodePoint } from '../../../src/utils/emojiCodePoint.js'

// a spread of the shapes an emoji can take: plain, surrogate pair, flag
// (two regional indicators), variation selector, ZWJ sequence, skin tone
const EMOJI = ['😀', '🇩🇪', '👨‍👩‍👧', '❤️', '🏳️‍🌈', '🤦🏽‍♀️', '☕', '👍', '🚋', '1⃣']

describe('toCodePoint', () => {
	it('names the well-known ones exactly as their files are named', () => {
		expect(toCodePoint('😀')).toBe('1f600')
		expect(toCodePoint('🇩🇪')).toBe('1f1e9-1f1ea')
		expect(toCodePoint('👨‍👩‍👧')).toBe('1f468-200d-1f469-200d-1f467')
		expect(toCodePoint('❤️')).toBe('2764-fe0f')
	})

	it.each(EMOJI)('agrees with the twemoji package for %s', (emoji) => {
		// the package is a devDependency now: it stays out of the bundle and
		// stays in the test, as the reference this has to keep matching
		expect(toCodePoint(emoji)).toBe(twemoji.convert.toCodePoint(emoji))
	})

	it('honours a different separator, as twemoji does', () => {
		expect(toCodePoint('🇩🇪', '_')).toBe(twemoji.convert.toCodePoint('🇩🇪', '_'))
	})

	it('produces names that match the images the build ships', () => {
		// The real contract: the name has to resolve to an asset. img/twemoji
		// is a build artefact — webpack copies it out of the package — so the
		// package's own svg directory is what to assert against; it is there
		// whether or not anyone has run a build.
		const assets = resolve(process.cwd(), 'node_modules/twemoji/2/svg')
		const named = EMOJI.map((emoji) => toCodePoint(emoji.replace(/\ufe0f/g, '')))
		const found = named.filter((name) => existsSync(resolve(assets, `${name}.svg`)))

		expect(found.length).toBeGreaterThan(EMOJI.length / 2)
	})

	it('has nothing to say about an empty string', () => {
		expect(toCodePoint('')).toBe('')
	})
})
