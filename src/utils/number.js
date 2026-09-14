/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * A count, in the reader's own digits and grouping.
 *
 * `1234` is written 1,234 in English, 1.234 in German and ١٢٣٤ in Arabic, and
 * a profile that says "1234 posts" in every language is showing a number the
 * way a programmer writes one. The locale is the one Nextcloud has settled on
 * for this reader rather than the browser's, so the digits match the words
 * beside them.
 *
 * @param {number|string|null|undefined} value the count
 * @return {string} it, formatted
 */
export function formatCount(value) {
	const number = Number(value)

	return (Number.isFinite(number) ? number : 0).toLocaleString(getCanonicalLocale())
}
