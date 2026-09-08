/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import eventBus from './eventBus.js'

/**
 * Keyboard shortcuts, in the vocabulary every other Fediverse client uses:
 * j/k to move through posts, l/b/r to act on the one in focus, n to compose,
 * ? for the list.
 *
 * Nothing fires while the viewer is typing — in a field, a contenteditable
 * composer, or with a modifier held — because a shortcut that eats a keystroke
 * mid-sentence is worse than no shortcut at all.
 */

/** @type {Array<{keys: string[], event: string, label: string}>} */
export const SHORTCUTS = [
	{ keys: ['j'], event: 'shortcut:next', label: t('social', 'Next post') },
	{ keys: ['k'], event: 'shortcut:previous', label: t('social', 'Previous post') },
	{ keys: ['l', 'f'], event: 'shortcut:like', label: t('social', 'Like the post in focus') },
	{ keys: ['b'], event: 'shortcut:boost', label: t('social', 'Boost the post in focus') },
	{ keys: ['r'], event: 'shortcut:reply', label: t('social', 'Reply to the post in focus') },
	{ keys: ['o', 'Enter'], event: 'shortcut:open', label: t('social', 'Open the post in focus') },
	{ keys: ['n'], event: 'shortcut:compose', label: t('social', 'Write a new post') },
	{ keys: ['g'], event: 'shortcut:home', label: t('social', 'Go to the home timeline') },
	{ keys: ['?'], event: 'shortcut:help', label: t('social', 'Show these shortcuts') },
]

const EDITABLE = ['input', 'textarea', 'select']

/**
 * Whether a key press is the viewer typing rather than commanding.
 *
 * @param {KeyboardEvent} event the press
 * @return {boolean} true when the press must be left alone
 */
export function isTyping(event) {
	if (event.ctrlKey || event.metaKey || event.altKey) {
		return true
	}

	const target = event.target
	if (!target || typeof target.tagName !== 'string') {
		return false
	}

	return EDITABLE.includes(target.tagName.toLowerCase())
		|| target.isContentEditable === true
}

/**
 * @param {KeyboardEvent} event the press
 * @return {string} the event to publish, or '' when the key means nothing here
 */
export function eventFor(event) {
	if (isTyping(event)) {
		return ''
	}

	const shortcut = SHORTCUTS.find(({ keys }) => keys.includes(event.key))

	return shortcut === undefined ? '' : shortcut.event
}

/**
 * Starts listening for shortcuts.
 *
 * @return {Function} call to stop listening again
 */
export function listenForShortcuts() {
	const handler = (event) => {
		const name = eventFor(event)
		if (name === '') {
			return
		}

		event.preventDefault()
		eventBus.emit(name)
	}

	window.addEventListener('keydown', handler)

	return () => window.removeEventListener('keydown', handler)
}
