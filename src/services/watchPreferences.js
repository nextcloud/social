/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * How the reader likes the watch page, remembered in this browser: the wide
 * theater layout, and whether the next video opens by itself when one ends.
 * Both are off until switched on.
 *
 * Kept per browser rather than per account: they are about the screen in
 * front of the reader, and a phone and a desk want different answers.
 */

const THEATER_KEY = 'social.watch.theater'
const AUTOPLAY_KEY = 'social.watch.autoplay'

/**
 * @param {string} key what is kept
 * @return {boolean} whether it is switched on
 */
function read(key) {
	try {
		return window.localStorage.getItem(key) === '1'
	} catch {
		// a private window or blocked site data: the default
		return false
	}
}

/**
 * @param {string} key what is kept
 * @param {boolean} on whether it is switched on
 */
function write(key, on) {
	try {
		window.localStorage.setItem(key, on ? '1' : '0')
	} catch {
		// not remembered; it still holds for this page view
	}
}

/** @return {boolean} whether the player is drawn wide, with Up next below it */
export function theaterMode() {
	return read(THEATER_KEY)
}

/** @param {boolean} on whether the player is drawn wide */
export function setTheaterMode(on) {
	write(THEATER_KEY, on)
}

/** @return {boolean} whether the next video opens when one ends */
export function autoplayNext() {
	return read(AUTOPLAY_KEY)
}

/** @param {boolean} on whether the next video opens when one ends */
export function setAutoplayNext(on) {
	write(AUTOPLAY_KEY, on)
}
