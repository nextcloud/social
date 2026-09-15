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
 * `verified_at` comes back on a row whose link the owner proved is theirs, by
 * putting a `rel="me"` link back to their profile on the far end. It is a date
 * string, and only its presence matters here: a verified row is marked, an
 * unverified one is an ordinary link. A row that is not a link cannot be
 * verified, whatever the server says, so the flag is held to `href`.
 *
 * @param {Array<{name: string, value: string, verified_at?: string|null}>|undefined} fields as the account entity carries them
 * @param {number} [limit] how many to keep; Mastodon's own ceiling is four
 * @return {Array<{name: string, text: string, href: string, verified: boolean, verifiedAt: string}>} href is '' when the row is not a link
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

			const safeHref = /^https?:\/\//.test(href) ? href : ''

			return {
				name: field.name,
				text,
				href: safeHref,
				verified: safeHref !== '' && Boolean(field.verified_at),
				verifiedAt: typeof field.verified_at === 'string' ? field.verified_at : '',
			}
		})
}

/**
 * The web address a field value names, or '' when it names none.
 *
 * The same reading the server makes before it tries to verify a field:
 * `ProfileLinkVerifier::linkOf()` takes the first `http(s)://…` run out of the
 * value and keeps it only if it parses as a URL. A bare `example.org` is
 * therefore text, not a link, and is never fetched — which is why the editor
 * says so rather than offering a tick that can never come.
 *
 * Only the plain-text half of `linkOf()` is reproduced here: the `href="…"`
 * branch is for the HTML a remote instance sends, and nothing typed into the
 * editor is HTML.
 *
 * @param {string} value a field value as it is typed
 * @return {string} the address, or ''
 */
export function fieldLink(value) {
	const match = /https?:\/\/[^\s<>"']+/i.exec(value ?? '')
	if (match === null) {
		return ''
	}

	try {
		// the parse is the check, as FILTER_VALIDATE_URL is on the server
		new URL(match[0])
	} catch {
		return ''
	}

	return match[0]
}
