/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'

import { scrollOffset, scroller } from '../../../src/utils/scroller.js'

/** @return {Element} the app's content column, added to the page */
function column() {
	const element = document.createElement('div')
	element.id = 'app-content-vue'
	document.body.appendChild(element)

	return element
}

describe('scroller', () => {
	afterEach(() => {
		document.querySelector('#app-content-vue')?.remove()
		Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true })
	})

	it('finds the column the app renders into', () => {
		const element = column()
		expect(scroller()).toBe(element)
	})

	it('answers null before the app has rendered', () => {
		expect(scroller()).toBeNull()
	})

	it('measures the column, not the window', () => {
		// Nextcloud gives an app a fixed viewport: `window.scrollY` stays 0
		// however far down the column the reader is
		const element = column()
		element.scrollTop = 800

		expect(scrollOffset()).toBe(800)
		expect(window.scrollY).toBe(0)
	})

	it('falls back to the window where there is no column', () => {
		Object.defineProperty(window, 'scrollY', { value: 120, configurable: true, writable: true })

		expect(scrollOffset()).toBe(120)
	})
})
