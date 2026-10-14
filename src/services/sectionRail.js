/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The shared behaviour of a page that is a long list of sections with a rail
 * beside it: which section the reader is looking at, and going to one.
 *
 * Both the settings page and the administration page are that shape, and the
 * rule below is worth having in one place because both obvious versions of it
 * are wrong -- see `currentSection`.
 */

/**
 * How far down the window a section's top has to have passed to count as the
 * one being read. Just past the `scroll-margin` a section carries, so that a
 * section jumped to is the one marked.
 */
export const RAIL_BAND = 96

/**
 * Finds a section inside its page, where ids cannot collide with the header.
 *
 * @param {Element|null|undefined} root the page containing the sections
 * @param {string} id the section id
 * @return {Element|null} the section, if drawn
 */
export function findSection(root, id) {
	return Array.from(root?.querySelectorAll('[id]') ?? []).find((section) => section.id === id) ?? null
}

/**
 * The section the reader is looking at.
 *
 * The last one whose heading has passed the top of the window. Taking the
 * topmost section that is merely *visible* instead marks the wrong one as soon
 * as a tall section straddles the top: it starts above the window, so it is
 * the highest thing on screen while the reader is already past it. Measuring
 * against a band further down -- a third of the window, say -- has the
 * opposite fault: a short section leaves the one below it inside the band as
 * well, and a rail link then marks the section after the one it scrolled to.
 *
 * @param {string[]} ids the section ids, in the order they are drawn
 * @param {string} current what is marked now, kept when nothing has passed yet
 * @param {Element|null} root the page containing the sections
 * @return {string} the id to mark
 */
export function currentSection(ids, current = '', root = null) {
	let found = current

	for (const id of ids) {
		const el = findSection(root, id)
		if (el && el.getBoundingClientRect().top <= RAIL_BAND) {
			found = id
		}
	}

	return found
}

/**
 * Calls back whenever a section comes into or goes out of view.
 *
 * The observer is only the trigger; which section is current is worked out
 * from all of them by `currentSection`, because the entries a callback is
 * handed are only the ones that changed.
 *
 * @param {string[]} ids the section ids to watch
 * @param {() => void} onChange called with no arguments
 * @param {Element|null} root the page containing the sections
 * @return {IntersectionObserver|null} to disconnect, or null where the browser has none
 */
export function watchSections(ids, onChange, root = null) {
	if (typeof IntersectionObserver !== 'function') {
		return null
	}

	const observer = new IntersectionObserver(() => onChange(), { threshold: 0 })
	for (const id of ids) {
		const el = findSection(root, id)
		if (el) {
			observer.observe(el)
		}
	}

	return observer
}

/**
 * Puts a section in view, the way the rest of the page scrolls.
 *
 * @param {string} id the section to go to
 * @param {Element|null} root the page containing the sections
 * @return {boolean} whether there was a section to go to
 */
export function scrollToSection(id, root = null) {
	const section = findSection(root, id)
	if (!section || typeof section.scrollIntoView !== 'function') {
		return false
	}

	const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
	section.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' })

	return true
}
