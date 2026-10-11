/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A URL as a CSS `url()` value: quoted, with the quote, the backslash and
 * line breaks escaped, so that whatever the URL holds stays inside it.
 *
 * @param {string} url the address of the picture
 * @return {string} `url("…")`
 */
export function cssUrl(url) {
	const escaped = String(url ?? '')
		.replace(/[\\"]/g, (char) => '\\' + char)
		.replace(/\r\n|[\n\r\f]/g, (char) => (char === '\f' ? '\\c ' : '\\a '))

	return `url("${escaped}")`
}
