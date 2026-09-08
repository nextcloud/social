/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Guards against Vue 2 idioms drifting back into the source.
 *
 * Every pattern here was really in the tree and silently did nothing on Vue 3:
 * a `-enter` transition class that never matched (Vue 3 names it `-enter-from`),
 * `:prop.sync` on a component whose update event was therefore never handled.
 * The compiler does not complain about any of them, and a reviewer has to know
 * the Vue 2 spelling to notice, so they are asserted instead.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { SHORTCUTS } from '../../src/services/shortcuts.js'

// vitest runs from the project root; import.meta.url is not a file url here
const SRC = resolve(process.cwd(), 'src')

/** @return {string[]} every .vue and .js file under src/ */
const sourceFiles = (dir = SRC, found = []) => {
	for (const entry of readdirSync(dir)) {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) {
			sourceFiles(path, found)
		} else if (/\.(vue|js)$/.test(entry)) {
			found.push(path)
		}
	}
	return found
}

const files = sourceFiles().map((path) => ({
	name: relative(SRC, path),
	content: readFileSync(path, 'utf8'),
}))

/**
 * @param {RegExp} pattern what a Vue 2 leftover looks like
 * @return {string[]} `file:line` for every hit
 */
const hits = (pattern) => files.flatMap(({ name, content }) =>
	content.split('\n')
		.map((line, index) => (pattern.test(line) ? `${name}:${index + 1}` : null))
		.filter(Boolean),
)

describe('the frontend is Vue 3, not Vue 2 with a Vue 3 runtime', () => {
	it('names transition classes the way Vue 3 does', () => {
		// `.list-enter` matched nothing on Vue 3, so the fade never ran
		expect(hits(/^\s*\.[\w-]+-enter\s*[,{]/)).toEqual([])
		expect(hits(/^\s*\.[\w-]+-leave\s*[,{]/)).toEqual([])
	})

	it('defines a -enter-from for every -enter-active', () => {
		for (const { name, content } of files) {
			const active = [...content.matchAll(/\.([\w-]+)-enter-active/g)].map((match) => match[1])
			for (const transition of new Set(active)) {
				expect(
					content.includes(`.${transition}-enter-from`),
					`${name}: .${transition}-enter-active has no .${transition}-enter-from, so entering is not animated`,
				).toBe(true)
			}
		}
	})

	it('uses v-model arguments instead of the .sync modifier', () => {
		expect(hits(/\.sync\s*=/)).toEqual([])
	})

	it('uses the Vue 3 lifecycle hook names', () => {
		expect(hits(/\bbeforeDestroy\s*\(|\bdestroyed\s*\(/)).toEqual([])
	})

	it('does not reach for the reactivity helpers Vue 3 dropped', () => {
		expect(hits(/this\.\$set\(|this\.\$delete\(|Vue\.set\(|Vue\.delete\(/)).toEqual([])
	})

	it('does not use the APIs Vue 3 removed', () => {
		expect(hits(/\$listeners|\$scopedSlots|\$children|\bnew Vue\(|Vue\.component\(/)).toEqual([])
	})

	it('uses named slots the Vue 3 way', () => {
		// slot="name" and slot-scope were replaced by v-slot
		expect(hits(/\sslot\s*=\s*"|slot-scope\s*=/)).toEqual([])
	})

	it('does not use event modifiers Vue 3 removed', () => {
		expect(hits(/@[\w:]+\.native|@key(?:up|down)\.\d+/)).toEqual([])
	})

	it('declares the events every component emits', () => {
		for (const { name, content } of files) {
			// `$emit(` is a component event; `eventBus.emit(` is the app-wide bus
			if (!name.endsWith('.vue') || !/\$emit\(/.test(content)) {
				continue
			}
			expect(
				/\n\temits: \[/.test(content),
				`${name}: emits events without declaring them, so they also land on the root element as attributes`,
			).toBe(true)
		}
	})

	it('guards its animations behind prefers-reduced-motion', () => {
		for (const { name, content } of files) {
			// both properties move things; both need an escape hatch
			if (!/(?:transition|animation):\s*(?!none)/.test(content)) {
				continue
			}
			expect(
				content.includes('prefers-reduced-motion'),
				`${name}: animates without a prefers-reduced-motion escape hatch`,
			).toBe(true)
		}
	})

	it('shows no message to a user that a translator never saw', () => {
		// showError('…') puts English in front of everyone; the string has to
		// go through t()/translate() to be extracted and translated at all
		const offenders = []
		for (const { name, content } of files) {
			for (const [, call, argument] of content.matchAll(/\b(showError|showSuccess|showWarning|showInfo)\(\s*([^\n]*)/g)) {
				// a variable or a helper is fine — it is the literal that is not
				if (/^['"`]/.test(argument.trim())) {
					offenders.push(`${name}: ${call}(${argument.trim().slice(0, 48)}`)
				}
			}
		}

		expect(offenders).toEqual([])
	})

	it('has somebody listening for every shortcut it advertises', () => {
		// a key in the help sheet that nothing answers is a promise to a user
		// who has no way of discovering it was never kept
		const advertised = SHORTCUTS.map(({ event }) => event)
		const listened = files
			.flatMap(({ content }) => [...content.matchAll(/eventBus\.on\(\s*'(shortcut:[a-z]+)'/g)])
			.map(([, event]) => event)

		expect(advertised.filter((event) => !listened.includes(event))).toEqual([])
	})

	it('does not put a click handler on something nobody can focus', () => {
		// a div that reacts to a click is invisible to the keyboard and to a
		// screen reader; the fix is a <button>, or a role plus tabindex plus a
		// key handler if it truly cannot be one
		const offenders = []
		for (const { name, content } of files) {
			for (const [tag] of content.matchAll(/<(?:div|span|li|img|p)\b[^>]*@click[^>]*>/g)) {
				const excused = /\brole=|\btabindex=|@keydown|@keyup|aria-hidden="true"/.test(tag)
				if (!excused) {
					offenders.push(`${name}: ${tag.replace(/\s+/g, ' ').slice(0, 70)}`)
				}
			}
		}

		expect(offenders).toEqual([])
	})

	it('suppresses no focus outline without putting one back', () => {
		for (const { name, content } of files) {
			// :focus-visible with an outline of its own is the replacement; a
			// bare `outline: none` leaves a keyboard user with no idea where
			// they are
			expect(/outline:\s*none/.test(content), `${name}: removes a focus outline`).toBe(false)
		}
	})
})
