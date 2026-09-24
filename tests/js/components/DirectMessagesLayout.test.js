/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The chat's layout, read from its compiled stylesheet.
 *
 * jsdom lays nothing out, so what the page does at a given width cannot be
 * measured here; what can be checked is which rules apply at which width,
 * which is where each of these went wrong.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { parse } from '@vue/compiler-sfc'
import { compileString } from 'sass'
import { describe, expect, it } from 'vitest'

const COMPONENTS = resolve(process.cwd(), 'src/components')

/**
 * @param {string} file a .vue file under src/components or src/views
 * @return {string} its first style block, compiled to CSS
 */
function compiledStyle(file) {
	const { descriptor } = parse(readFileSync(resolve(process.cwd(), file), 'utf8'))
	const style = descriptor.styles[0]

	return style.lang === 'scss'
		? compileString(style.content, { loadPaths: [COMPONENTS], style: 'expanded' }).css
		: style.content
}

/**
 * Every rule of a stylesheet with the media query it sits in.
 *
 * @param {string} css compiled CSS
 * @return {{media: string, selector: string, body: string}[]} the rules
 */
function rules(css) {
	const found = []
	const walk = (text, media) => {
		let index = 0
		while (index < text.length) {
			const open = text.indexOf('{', index)
			if (open === -1) {
				return
			}
			let depth = 1
			let close = open + 1
			while (depth > 0 && close < text.length) {
				depth += text[close] === '{' ? 1 : text[close] === '}' ? -1 : 0
				close++
			}
			// a statement such as `@charset` ends at its semicolon, not at a brace
			const prelude = text.slice(index, open).split(';').at(-1).trim()
			const body = text.slice(open + 1, close - 1)
			if (prelude.startsWith('@media')) {
				walk(body, prelude)
			} else {
				found.push({ media, selector: prelude, body })
			}
			index = close
		}
	}
	walk(css.replace(/\/\*[\s\S]*?\*\//g, ''), '')

	return found
}

describe('DirectMessages layout', () => {
	const css = rules(compiledStyle('src/components/DirectMessages.vue'))

	// a floor under the height made the box taller than a short viewport: the
	// message field went off the bottom and the whole page scrolled instead
	it('never gives the chat a floor taller than the space it has', () => {
		const root = css.filter((rule) => rule.selector === '.direct-messages')

		expect(root.length).toBeGreaterThan(0)
		for (const rule of root) {
			expect(rule.body).not.toMatch(/min-height/)
		}
	})

	// the navigation toggle sits over the heading's corner wherever the
	// navigation is pinned; below 1024px the page already starts under it
	it('clears the navigation toggle only where it is over the heading', () => {
		const padded = css.filter((rule) => rule.selector === '.direct-messages__list-heading'
			&& /padding-inline-start/.test(rule.body))

		expect(padded.map((rule) => rule.media)).toEqual(['@media (min-width: 1025px)'])
	})

	// below 1024px the page starts under the navigation toggle; a height that
	// ignored that ran the Send button off the bottom at every width there
	it('takes the space the page leaves for the navigation toggle off its height', () => {
		const root = css.find((rule) => rule.selector === '.direct-messages' && rule.media === '')
		const timeline = rules(compiledStyle('src/views/Timeline.vue'))
		const clearance = timeline.filter((rule) => /--social-toggle-clearance:/.test(rule.body))
		const margin = timeline.find((rule) => rule.selector.startsWith('.social__wrapper > :first-child'))

		expect(root.body).toMatch(/height:\s*calc\([^;]*- var\(--social-toggle-clearance, 0px\)\)/)
		expect(clearance.map((rule) => rule.media)).toEqual(['@media (max-width: 1024px)'])
		expect(margin.body).toMatch(/margin-top:\s*var\(--social-toggle-clearance\)/)
	})

	it('shows the list and the thread side by side down to 801px', () => {
		const single = css.filter((rule) => rule.selector === '.direct-messages'
			&& /grid-template-columns:\s*minmax\(0,\s*1fr\)/.test(rule.body))

		expect(single.map((rule) => rule.media)).toEqual(['@media (max-width: 800px)'])
	})
})
