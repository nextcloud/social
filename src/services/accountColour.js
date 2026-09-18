/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A colour for an account, the same one everywhere.
 *
 * Nextcloud gives every account a generated avatar in a colour of its own, and
 * that colour is the only thing about an account a reader recognises before
 * they have read anything. It was doing that work in one place -- the avatar --
 * while the ring around a story, the tint behind a direct message and the edge
 * of a hover card were all the same grey for everybody.
 *
 * What is returned is a hue rather than a colour, and it reaches the page as a
 * custom property. Colours are then built in CSS, where the theme can be asked
 * about: the same hue is a deep ink on a white page and a soft wash on a dark
 * one, and a colour computed here could only be one of the two.
 */

/** Hues that read as an error, kept for errors. */
const RESERVED = [[350, 370], [0, 12]]

/**
 * A stable hue for a handle.
 *
 * FNV-1a over the handle: small, well spread for short strings, and -- unlike
 * anything involving `Math.random` or a counter -- the same answer in every
 * browser, on every page, for ever, which is the whole point. The reds are
 * stepped over so that an account's own colour never reads as a warning.
 *
 * @param {string} acct the handle, with or without a leading @
 * @return {number} a hue in degrees
 */
export function accountHue(acct) {
	const handle = String(acct ?? '').replace(/^@/, '').toLowerCase()
	if (handle === '') {
		return 210
	}

	let hash = 0x811c9dc5
	for (let i = 0; i < handle.length; i++) {
		hash ^= handle.charCodeAt(i)
		// the shifts are FNV's 32-bit prime, written as additions because
		// `Math.imul` on a number this app does not otherwise need is a
		// dependency on a detail of how the engine rounds
		hash = (hash + (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24)) >>> 0
	}

	let hue = hash % 360
	for (const [from, to] of RESERVED) {
		const start = from % 360
		if ((start <= to && hue >= start && hue < to) || (start > to && (hue >= start || hue < to))) {
			hue = (to + 8) % 360
		}
	}

	return hue
}

/**
 * The style an element carries so that everything inside it can be that
 * account's colour.
 *
 * @param {object|string} account an account, or a handle
 * @return {object} a style binding
 */
export function accountStyle(account) {
	const acct = typeof account === 'string'
		? account
		: (account?.acct ?? account?.username ?? account?.id ?? '')

	return { '--account-hue': String(accountHue(acct)) }
}
