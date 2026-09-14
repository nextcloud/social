/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What goes inside the sidebar's Explore entry, and how much of it fits.
 *
 * The rail holds the hashtags the reader follows and the lists they have. Both
 * grow without limit — somebody who follows forty tags would otherwise push
 * their own feed off a laptop screen — so the entry shows as much as there is
 * room for and says how many there are in all.
 */

/** A navigation row, as Nextcloud sizes one. */
const ROW_HEIGHT = 44

/**
 * The height the rail needs for everything that is not an Explore child: the
 * New post button, the six entries above, the Explore row itself, the spacers
 * and the account footer.
 *
 * Measured rather than derived, because the parts are not all rows and a
 * formula over them would be a second copy of the template that has to be
 * kept in step with it. A number that is a little too large costs one entry;
 * one that is too small costs a scrollbar, which is what this exists to avoid.
 */
const RESERVED_HEIGHT = 430

/** Never more than this, however tall the screen. */
export const MAX_ENTRIES = 12

/**
 * And never fewer than this, however short.
 *
 * Below this the entry stops being a way to reach anything and the reader is
 * better served by the count and the Discover page.
 */
export const MIN_ENTRIES = 3

/**
 * How many entries the rail has room for.
 *
 * @param {number} viewportHeight the window's inner height, in pixels
 * @return {number} between MIN_ENTRIES and MAX_ENTRIES
 */
export function entriesThatFit(viewportHeight) {
	const height = Number(viewportHeight)
	if (!Number.isFinite(height) || height <= 0) {
		return MAX_ENTRIES
	}

	const fits = Math.floor((height - RESERVED_HEIGHT) / ROW_HEIGHT)

	return Math.max(MIN_ENTRIES, Math.min(MAX_ENTRIES, fits))
}

/**
 * How many of each kind to show, when there is not room for all of them.
 *
 * Proportional to how many there are, so somebody with twelve tags and one
 * list does not lose the list, and somebody with one tag and twelve lists does
 * not lose the tag. Both kinds keep at least one place while there is room for
 * one each — a truncation that silently drops a whole kind reads as the kind
 * having gone missing rather than as there being too little room.
 *
 * @param {number} tags how many hashtags there are
 * @param {number} lists how many lists there are
 * @param {number} cap how many entries there is room for
 * @return {{tags: number, lists: number}} how many of each to show
 */
export function shareOut(tags, lists, cap) {
	const room = Math.max(0, Math.floor(cap))
	const wanted = tags + lists

	if (wanted <= room) {
		return { tags, lists }
	}

	if (room === 0) {
		return { tags: 0, lists: 0 }
	}

	// one kind only: it takes what there is
	if (tags === 0 || lists === 0) {
		return { tags: Math.min(tags, room), lists: Math.min(lists, room) }
	}

	// one place each, then the rest in proportion to what is left over
	if (room === 1) {
		return { tags: 1, lists: 0 }
	}

	const spare = room - 2
	const remainingTags = tags - 1
	const remainingLists = lists - 1
	const forTags = Math.min(
		remainingTags,
		Math.round((remainingTags / (remainingTags + remainingLists)) * spare),
	)

	const chosenTags = 1 + forTags
	const chosenLists = Math.min(lists, room - chosenTags)

	// rounding can leave a place unused when one kind runs out first
	const unused = room - chosenTags - chosenLists

	return {
		tags: Math.min(tags, chosenTags + unused),
		lists: chosenLists,
	}
}

/**
 * The entries the Explore item shows, hashtags first.
 *
 * @param {Array<{name: string}>} tags the hashtags the reader follows
 * @param {Array<{id: string, title: string}>} lists the reader's lists
 * @param {number} cap how many there is room for
 * @return {Array<object>} what to draw, each carrying the `kind` it came from
 */
export function chooseEntries(tags, lists, cap) {
	const safeTags = Array.isArray(tags) ? tags : []
	const safeLists = Array.isArray(lists) ? lists : []
	const share = shareOut(safeTags.length, safeLists.length, cap)

	return [
		...safeTags.slice(0, share.tags).map((tag) => ({ kind: 'tag', tag })),
		...safeLists.slice(0, share.lists).map((list) => ({ kind: 'list', list })),
	]
}
