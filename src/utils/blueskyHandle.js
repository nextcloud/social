/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The longest a DNS label, and so the first part of a handle, may be. */
const MAX_LABEL = 63

/**
 * The first part of the Bluesky handle a username is given: the same rule as
 * `HandleMapper::label()`, which is what the server issues. Only ASCII
 * capitals are lowered, as PHP's `strtolower()` does; every other character
 * outside `a-z0-9-` becomes a hyphen either way.
 *
 * @param {string} username a username as typed
 * @return {string} the label, without the host
 */
export function blueskyLabel(username) {
	let label = String(username ?? '').replace(/[A-Z]/g, (letter) => letter.toLowerCase())
	label = label.replace(/[^a-z0-9-]+/g, '-')
	label = label.replace(/-{2,}/g, '-')
	label = label.replace(/^-+|-+$/g, '')
	if (label === '') {
		label = 'user'
	}
	if (/^[0-9]/.test(label)) {
		label = 'u-' + label
	}

	return label.slice(0, MAX_LABEL).replace(/-+$/, '')
}

/**
 * The Bluesky handle a username is given on this host, before any number the
 * server adds when another account here already has it.
 *
 * @param {string} username a username as typed
 * @param {string} host the host handles are issued under
 * @return {string} `label.host`
 */
export function blueskyHandle(username, host) {
	return blueskyLabel(username) + '.' + String(host ?? '').toLowerCase()
}
