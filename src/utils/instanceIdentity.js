/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A timeline here is a mix of accounts from many servers, and the only trace
 * of that in the UI used to be the part of the handle after the `@`. Giving
 * every instance a colour of its own makes the spread visible: you learn to
 * recognise where a post came from before reading the handle.
 *
 * The colour is derived from the hostname, so it is the same on every client,
 * every session and every instance — no storage, no coordination.
 */

/** Spread hues around the wheel; the same host always lands on the same one. */
const hash = (value) => {
	let h = 0
	for (let i = 0; i < value.length; i++) {
		h = (h * 31 + value.charCodeAt(i)) % 360360
	}
	return h
}

/**
 * The instance an account belongs to, or '' for a local one.
 *
 * @param {string} acct a handle: `alice` locally, `alice@example.org` remotely
 * @return {string} the hostname, lowercased, or '' when the account is local
 */
export function instanceOf(acct) {
	if (typeof acct !== 'string') {
		return ''
	}

	const at = acct.lastIndexOf('@')
	if (at <= 0) {
		return ''
	}

	return acct.slice(at + 1).toLowerCase()
}

/**
 * A stable colour for one instance, as an `hsl()` string.
 *
 * Saturation and lightness are fixed so that every instance colour carries
 * the same weight next to the theme's own colours, and stays legible on both
 * a light and a dark background.
 *
 * @param {string} host a hostname
 * @return {string} an hsl() colour, or '' for no host
 */
export function instanceColour(host) {
	if (!host) {
		return ''
	}

	return `hsl(${hash(host) % 360}, 62%, 52%)`
}

/**
 * Everything the UI needs to show where an account lives.
 *
 * @param {string} acct a handle
 * @return {{instance: string, colour: string, local: boolean}} the account's origin
 */
export function originOf(acct) {
	const instance = instanceOf(acct)

	return {
		instance,
		colour: instanceColour(instance),
		local: instance === '',
	}
}
