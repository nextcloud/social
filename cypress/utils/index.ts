/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export function getSearchParams (url) {
	return url
		.split(/[?&]/)
		.reduce((acc, cur) => {
			const parts = cur.split('=')
			parts[1] && (acc[parts[0]] = parts[1])
			return acc
		}, {})
}

export function randHash() {
	return Math.random().toString(36).replace(/[^a-z]+/g, '').slice(0, 10)
}
