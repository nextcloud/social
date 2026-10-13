/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { focusedElement, returnFocusTo } from '../../../src/utils/focus.js'

describe('focus', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	/** @return {HTMLButtonElement} a button on the page */
	function button() {
		const element = document.createElement('button')
		document.body.appendChild(element)

		return element
	}

	it('remembers what has the focus, and nothing when that is the page itself', () => {
		expect(focusedElement()).toBeNull()

		const opener = button()
		opener.focus()

		expect(focusedElement()).toBe(opener)
	})

	it('gives the focus back to the opener', () => {
		const opener = button()
		const other = button()
		opener.focus()
		other.focus()

		expect(returnFocusTo(opener)).toBe(true)
		expect(document.activeElement).toBe(opener)
	})

	it('leaves the focus alone when the opener is gone or was never there', () => {
		const other = button()
		other.focus()
		const gone = button()
		gone.remove()

		expect(returnFocusTo(gone)).toBe(false)
		expect(returnFocusTo(null)).toBe(false)
		expect(returnFocusTo(undefined)).toBe(false)
		expect(document.activeElement).toBe(other)
	})
})
