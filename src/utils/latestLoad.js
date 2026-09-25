/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Loads of which only the newest may say anything.
 *
 * The router reuses a view when only a parameter changes, so the answer for
 * the account the reader just left can arrive after the one for the account
 * now on screen. Each load takes a ticket when it starts and asks, after
 * every await, whether it is still the newest; a load that is not leaves the
 * data, the error and the loading flag to the one that is.
 *
 * @return {{begin: () => (() => boolean), current: () => (() => boolean)}}
 */
export function latestLoad() {
	let newest = 0

	/**
	 * @param {number} ticket the generation a load belongs to
	 * @return {() => boolean} whether that generation is still the newest
	 */
	const check = (ticket) => () => ticket === newest

	return {
		/** @return {() => boolean} a new generation, which outdates every earlier one */
		begin: () => check(++newest),
		/** @return {() => boolean} the generation already running, for work that continues it */
		current: () => check(newest),
	}
}
