/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The metadata rows under a profile, as text and an optional link.
 *
 * Mastodon's `fields` carry `value` as HTML — a remote instance sends
 * `<a href="https://example.org" rel="me">example.org</a>` for a link row and
 * plain text for the rest — so the value is parsed rather than rendered: the
 * text is what is shown, and the anchor's `href` is followed only when it is
 * `http(s)`. `javascript:` and friends therefore become a row with no link at
 * all, which is the safe reading of a field somebody else wrote.
 *
 * The profile page and the hover card both show these rows, and a second copy
 * of this parsing in either of them is a second set of rules for what a link
 * is.
 *
 * @param {Array<{name: string, value: string}>|undefined} fields as the account entity carries them
 * @param {number} [limit] how many to keep; Mastodon's own ceiling is four
 * @return {Array<{name: string, text: string, href: string}>} href is '' when the row is not a link
 */
export function profileFields(fields, limit = Infinity) {
	const parser = new DOMParser()

	return (fields ?? [])
		.slice(0, limit)
		.map((field) => {
			const doc = parser.parseFromString(field.value || '', 'text/html')
			const text = doc.body.textContent.trim()
			const anchor = doc.body.querySelector('a[href]')
			const href = anchor ? anchor.getAttribute('href') : text

			return {
				name: field.name,
				text,
				href: /^https?:\/\//.test(href) ? href : '',
			}
		})
}
