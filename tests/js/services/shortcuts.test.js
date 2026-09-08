/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SHORTCUTS, eventFor, isTyping, listenForShortcuts } from '../../../src/services/shortcuts.js'
import eventBus from '../../../src/services/eventBus.js'

const press = (key, target = document.body, extra = {}) => ({
	key,
	target,
	preventDefault: vi.fn(),
	ctrlKey: false,
	metaKey: false,
	altKey: false,
	...extra,
})

describe('isTyping', () => {
	it('leaves fields alone', () => {
		for (const tag of ['input', 'textarea', 'select']) {
			expect(isTyping(press('j', document.createElement(tag)))).toBe(true)
		}
	})

	it('leaves the composer alone, which is a contenteditable div', () => {
		const composer = document.createElement('div')
		composer.isContentEditable = true

		expect(isTyping(press('j', composer))).toBe(true)
	})

	it('leaves anything with a modifier to the browser', () => {
		expect(isTyping(press('j', document.body, { ctrlKey: true }))).toBe(true)
		expect(isTyping(press('j', document.body, { metaKey: true }))).toBe(true)
		expect(isTyping(press('j', document.body, { altKey: true }))).toBe(true)
	})

	it('treats a press on the page itself as a command', () => {
		expect(isTyping(press('j'))).toBe(false)
	})
})

describe('eventFor', () => {
	it('maps the keys other clients use', () => {
		expect(eventFor(press('j'))).toBe('shortcut:next')
		expect(eventFor(press('k'))).toBe('shortcut:previous')
		expect(eventFor(press('l'))).toBe('shortcut:like')
		expect(eventFor(press('f'))).toBe('shortcut:like')
		expect(eventFor(press('b'))).toBe('shortcut:boost')
		expect(eventFor(press('r'))).toBe('shortcut:reply')
		expect(eventFor(press('n'))).toBe('shortcut:compose')
		expect(eventFor(press('?'))).toBe('shortcut:help')
	})

	it('ignores keys it does not claim', () => {
		expect(eventFor(press('q'))).toBe('')
		expect(eventFor(press('Escape'))).toBe('')
	})

	it('ignores everything while typing', () => {
		expect(eventFor(press('j', document.createElement('input')))).toBe('')
	})

	it('gives every shortcut a label to show in the help sheet', () => {
		for (const shortcut of SHORTCUTS) {
			expect(shortcut.label.length).toBeGreaterThan(0)
			expect(shortcut.keys.length).toBeGreaterThan(0)
		}
	})
})

describe('listenForShortcuts', () => {
	let stop

	afterEach(() => {
		stop?.()
		eventBus.all.clear()
	})

	it('publishes the shortcut and takes the key', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:like', heard)
		stop = listenForShortcuts()

		const event = new KeyboardEvent('keydown', { key: 'l', cancelable: true })
		window.dispatchEvent(event)

		expect(heard).toHaveBeenCalledTimes(1)
		expect(event.defaultPrevented).toBe(true)
	})

	it('leaves a key it does not claim to the page', () => {
		stop = listenForShortcuts()

		const event = new KeyboardEvent('keydown', { key: 'q', cancelable: true })
		window.dispatchEvent(event)

		expect(event.defaultPrevented).toBe(false)
	})

	it('stops listening when told to', () => {
		const heard = vi.fn()
		eventBus.on('shortcut:next', heard)
		listenForShortcuts()()

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j' }))

		expect(heard).not.toHaveBeenCalled()
	})
})
