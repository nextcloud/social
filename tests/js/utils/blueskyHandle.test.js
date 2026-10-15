/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { blueskyHandle, blueskyLabel } from '../../../src/utils/blueskyHandle.js'

/*
 * The page's half of the labels tests/Atproto/Identity/HandleLabelFixturesTest.php
 * pins on the PHP side: every username in the fixture has to come out as the
 * label `HandleMapper::label()` gives it, or the preview promises a handle
 * the account will not get.
 */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Atproto/Identity/fixtures/handle-labels.json'), 'utf8'))

describe('blueskyLabel', () => {
	it.each(fixture.map(({ username, label }) => [username, label]))('maps %j as the server does', (username, label) => {
		expect(blueskyLabel(username)).toBe(label)
	})

	it('lowers only the capitals PHP lowers', () => {
		// the Kelvin sign lowers to "k" in JavaScript, and is a byte PHP does not know
		expect(blueskyLabel('Kelvin')).toBe('elvin')
		expect(blueskyLabel('İstanbul')).toBe('stanbul')
	})

	it('reads a missing name as the server reads an empty one', () => {
		expect(blueskyLabel(undefined)).toBe('user')
	})
})

describe('blueskyHandle', () => {
	it('puts the label under the host', () => {
		expect(blueskyHandle('Frank_K', 'Social.Example.org')).toBe('frank-k.social.example.org')
	})
})
