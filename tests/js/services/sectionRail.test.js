/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { currentSection, findSection, scrollToSection, watchSections } from '../../../src/services/sectionRail.js'

/**
 * The rail beside a long page of sections: which section the reader is in,
 * and going to one of them.
 *
 * Every lookup is scoped to the page, because Nextcloud's header carries an
 * element with `id="notifications"` of its own
 * (`core/templates/layout.user.php`). The header comes before the app in the
 * document and sits at the top of the window at every scroll position, so a
 * lookup across the whole document answers for the bell menu: the rail marked
 * Notifications whatever was being read, the section of that name was never
 * watched, and clicking its rail entry scrolled to the header.
 */
describe('sectionRail', () => {
	/** the bell menu in Nextcloud's header: the section's id, before the page */
	let header
	/** the element the sections are drawn inside */
	let page

	/** Puts an element at a distance down the window, as jsdom does not draw. */
	const place = (el, top) => {
		el.getBoundingClientRect = () => ({ top })
		return el
	}

	beforeEach(() => {
		header = document.createElement('div')
		header.id = 'notifications'
		document.body.appendChild(header)

		page = document.createElement('div')
		page.innerHTML = '<section id="account"></section><section id="notifications"></section>'
		document.body.appendChild(page)
	})

	afterEach(() => {
		header.remove()
		page.remove()
	})

	describe('findSection', () => {
		it('finds the section inside the page, not the element above it wearing the id', () => {
			expect(findSection(page, 'notifications')).toBe(page.querySelector('[id="notifications"]'))
			expect(findSection(page, 'notifications')).not.toBe(header)
		})

		it('finds nothing for a section the page has not drawn', () => {
			expect(findSection(page, 'delete')).toBe(null)
		})

		it('finds nothing without a page to look inside', () => {
			expect(findSection(null, 'notifications')).toBe(null)
			expect(findSection(undefined, 'notifications')).toBe(null)
		})
	})

	describe('currentSection', () => {
		it('marks the last section whose heading has passed the band', () => {
			place(page.querySelector('#account'), -400)
			place(page.querySelector('#notifications'), 40)

			expect(currentSection(['account', 'notifications'], '', page)).toBe('notifications')
		})

		it('keeps what is marked while no section has reached the band', () => {
			place(page.querySelector('#account'), 400)
			place(page.querySelector('#notifications'), 600)

			expect(currentSection(['account', 'notifications'], 'account', page)).toBe('account')
		})

		it('is not marked by an element outside the page', () => {
			place(header, 0)
			place(page.querySelector('#account'), 400)
			place(page.querySelector('#notifications'), 600)

			expect(currentSection(['account', 'notifications'], '', page)).toBe('')
		})
	})

	describe('scrollToSection', () => {
		it('scrolls to the section of the page', () => {
			const section = page.querySelector('#notifications')
			const scrollTo = vi.fn()
			const scrollHeader = vi.fn()
			section.scrollIntoView = scrollTo
			header.scrollIntoView = scrollHeader

			expect(scrollToSection('notifications', page)).toBe(true)
			expect(scrollTo).toHaveBeenCalled()
			expect(scrollHeader).not.toHaveBeenCalled()
		})

		it('says so when the page has no such section', () => {
			expect(scrollToSection('delete', page)).toBe(false)
			expect(scrollToSection('notifications')).toBe(false)
		})
	})

	describe('watchSections', () => {
		it('watches the sections of the page, and nothing else', () => {
			const observe = vi.spyOn(IntersectionObserver.prototype, 'observe')

			try {
				const observer = watchSections(['account', 'notifications'], () => {}, page)
				const watched = observe.mock.calls.map(([el]) => el)

				expect(watched).toContain(page.querySelector('#account'))
				expect(watched).toContain(page.querySelector('#notifications'))
				expect(watched).not.toContain(header)
				observer?.disconnect()
			} finally {
				observe.mockRestore()
			}
		})
	})
})
