/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where the subject of a picture is, in the coordinates Mastodon uses.
 *
 * A focal point is a pair from -1 to 1 with the origin at the centre of the
 * picture and y pointing *up*: (0, 0) is the middle, (1, 1) the top right
 * corner. The browser measures a pointer from the top left corner with y
 * pointing down, and CSS wants `object-position` as two percentages from the
 * same corner. These are the three conversions, in one place, so the editor,
 * the badge and the grid cannot disagree about which way is up.
 */

/** how far one arrow key press moves the point */
export const NUDGE = 0.05

/**
 * @typedef {object} FocalPoint
 * @property {number} x -1 (left edge) to 1 (right edge)
 * @property {number} y -1 (bottom edge) to 1 (top edge)
 */

/**
 * @param {number} value a coordinate
 * @return {number} the same, held inside -1..1 and rounded to two decimals
 */
function clamp(value) {
	const bounded = Math.min(1, Math.max(-1, Number.isFinite(value) ? value : 0))

	// two decimals is finer than any crop can show and keeps the wire value
	// from being 0.30000000000000004
	return Math.round(bounded * 100) / 100
}

/**
 * @param {FocalPoint} focus a point in any state
 * @return {FocalPoint} the same point, held inside the picture
 */
export function clampFocus(focus) {
	return { x: clamp(focus?.x), y: clamp(focus?.y) }
}

/**
 * Where a pointer landed, as a focal point.
 *
 * @param {number} offsetX pixels from the left edge of the picture
 * @param {number} offsetY pixels from the top edge of the picture
 * @param {number} width the picture's width on screen, in pixels
 * @param {number} height the picture's height on screen, in pixels
 * @return {FocalPoint} the point, or the centre when the picture has no size
 */
export function focusFromPoint(offsetX, offsetY, width, height) {
	if (!(width > 0) || !(height > 0)) {
		return { x: 0, y: 0 }
	}

	return clampFocus({
		x: (offsetX / width) * 2 - 1,
		y: 1 - (offsetY / height) * 2,
	})
}

/**
 * Where a focal point sits on the picture, for CSS: `left` and `top` as
 * percentages of the picture's size, and the same pair as an
 * `object-position` value.
 *
 * @param {FocalPoint|null|undefined} focus the point; nothing means the centre
 * @return {{left: string, top: string, objectPosition: string}}
 */
export function positionOfFocus(focus) {
	const point = isFocalPoint(focus) ? focus : { x: 0, y: 0 }
	const left = ((point.x + 1) / 2 * 100).toFixed(2) + '%'
	const top = ((1 - point.y) / 2 * 100).toFixed(2) + '%'

	return { left, top, objectPosition: `${left} ${top}` }
}

/**
 * @param {FocalPoint} focus the point
 * @param {number} dx how far to move it to the right, in focus units
 * @param {number} dy how far to move it up, in focus units
 * @return {FocalPoint} the moved point, still inside the picture
 */
export function nudgeFocus(focus, dx, dy) {
	const from = isFocalPoint(focus) ? focus : { x: 0, y: 0 }

	return clampFocus({ x: from.x + dx, y: from.y + dy })
}

/**
 * The point as `POST /api/v1/media` and `PUT /api/v1/media/{id}` take it:
 * Mastodon's `focus`, two numbers with a comma between them.
 *
 * @param {FocalPoint} focus the point
 * @return {string} for example `0.25,-0.5`
 */
export function focusParam(focus) {
	const point = clampFocus(focus)

	return `${point.x},${point.y}`
}

/**
 * @param {unknown} focus anything an attachment's `meta.focus` may hold
 * @return {boolean} whether it is a usable pair of numbers
 */
export function isFocalPoint(focus) {
	if (focus === null || typeof focus !== 'object') {
		return false
	}

	// read through a cast: the argument is `unknown` on purpose, because this
	// is the guard that decides whether it is a point at all
	const pair = /** @type {{x?: unknown, y?: unknown}} */ (focus)

	return Number.isFinite(pair.x) && Number.isFinite(pair.y)
}
