/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A colour for a hashtag, derived from the tag itself.
 *
 * The point is recognition, not decoration: `#design` should look the same
 * everywhere it appears, on every device and for every reader, so the hue is
 * computed from the name rather than stored or handed out in order. Nothing is
 * persisted and nothing has to be in sync.
 *
 * Case and the leading `#` are not part of the identity — `#Design`, `#design`
 * and `design` are the same tag to the server, so they are the same colour
 * here. Hue is the only thing that varies: saturation and lightness are pinned
 * to values that stay legible on both themes, because a hue chosen by a hash
 * has no idea what is behind it.
 */

/** how saturated a tag chip is; enough to read as a colour, not a highlighter */
const SATURATION = 62

/** the lightness the hue is drawn at on a light background */
const LIGHTNESS_LIGHT = 38

/** and on a dark one, where the same hue has to come forward instead of back */
const LIGHTNESS_DARK = 68

/**
 * The tag as the colour is keyed on: no `#`, no case, no surrounding space.
 *
 * @param {string} tag as it was typed or as the API sent it
 * @return {string} the key
 */
function normalise(tag) {
	return String(tag ?? '').trim().replace(/^#+/, '').toLowerCase()
}

/**
 * A hue for a tag, stable across reloads and machines.
 *
 * FNV-1a, because it is four lines and spreads short strings well; the hash is
 * not a security boundary and nothing is stored under it.
 *
 * @param {string} tag the hashtag, with or without its `#`
 * @return {number} degrees, 0–359
 */
export function tagHue(tag) {
	const key = normalise(tag)
	if (key === '') {
		return 0
	}

	let hash = 0x811c9dc5
	for (let i = 0; i < key.length; i++) {
		hash ^= key.charCodeAt(i)
		// the FNV prime, as shifts: a plain multiply would overflow into a
		// float and stop being the same number on every engine
		hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24)
		hash >>>= 0
	}

	return hash % 360
}

/**
 * The custom properties a tag chip or heading is drawn with.
 *
 * Returned as a style object rather than a class, because the hue is a
 * continuum and a stylesheet cannot hold 360 of them. The dark value is a
 * second property rather than a media query for the same reason — the
 * component's own CSS picks between them, so the choice stays in the
 * stylesheet where the theme is known.
 *
 * @param {string} tag the hashtag
 * @return {{'--tag-hue': string, '--tag-colour': string, '--tag-colour-dark': string}} the style object
 */
export function tagStyle(tag) {
	const hue = tagHue(tag)

	return {
		'--tag-hue': String(hue),
		'--tag-colour': `hsl(${hue}, ${SATURATION}%, ${LIGHTNESS_LIGHT}%)`,
		'--tag-colour-dark': `hsl(${hue}, ${SATURATION}%, ${LIGHTNESS_DARK}%)`,
	}
}
