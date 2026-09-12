/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * The two date formats this app shows, from the platform rather than from a
 * date library.
 *
 * `moment` was pulled into an entrypoint to provide `fromNow()` and one
 * `LLL` format — around 600 kB of module graph for five calls. `Intl` does
 * both, is localised by the browser, and costs nothing to ship.
 */

/** the steps a relative time is rounded to, longest first */
const STEPS = [
	['year', 365 * 24 * 3600],
	['month', 30 * 24 * 3600],
	['week', 7 * 24 * 3600],
	['day', 24 * 3600],
	['hour', 3600],
	['minute', 60],
	['second', 1],
]

/** @return {string} the locale to format in, falling back to the browser's */
function locale() {
	try {
		return getCanonicalLocale()
	} catch {
		return undefined
	}
}

/**
 * How long ago something happened, in words: "5 minutes ago", "last week".
 *
 * @param {string|number|Date} date when it happened
 * @param {Date} [now] the moment to measure from, for tests
 * @return {string} a localised relative time, or '' for a date that cannot be read
 */
export function fromNow(date, now = new Date()) {
	const then = new Date(date)
	if (Number.isNaN(then.getTime())) {
		return ''
	}

	const seconds = Math.round((then.getTime() - now.getTime()) / 1000)
	const magnitude = Math.abs(seconds)

	const [unit, size] = STEPS.find(([, step]) => magnitude >= step) ?? ['second', 1]
	const formatter = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' })

	return formatter.format(Math.round(seconds / size), unit)
}

/**
 * The full date and time, as `moment`'s `LLL` showed it: a readable date with
 * the time, in the viewer's locale.
 *
 * @param {string|number|Date} date the date to write out
 * @return {string} a localised date and time, or '' for a date that cannot be read
 */
export function fullDateTime(date) {
	const value = new Date(date)
	if (Number.isNaN(value.getTime())) {
		return ''
	}

	return new Intl.DateTimeFormat(locale(), {
		dateStyle: 'long',
		timeStyle: 'short',
	}).format(value)
}
