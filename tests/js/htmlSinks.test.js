/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Every place the frontend turns a string into live markup.
 *
 * Vue escapes interpolation; `v-html`, an `innerHTML` assignment and their
 * relatives are the ways to lose that, and most of what this app shows was
 * written on another server. Each sink is listed here with the sanitised
 * source it is fed from, so a new one fails this test until somebody has
 * written down why its input is safe, and a listed one that disappears
 * fails it too, so the list never outlives the code.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = resolve(process.cwd(), 'src')

/**
 * `file: sink` → where the markup comes from. The sink is the trimmed source
 * line, so moving it keeps the entry and changing what it renders does not.
 *
 * @type {Record<string, string>}
 */
const ALLOWED = {
	'components/EditHistoryDialog.vue: <div class="history__content" v-html="contentOf(version)" />':
		'contentOf() returns sanitizeHtml(version.content)',
	'components/UserEntry.vue: <p v-html="sanitizedNote" />':
		'sanitizedNote is sanitizeHtml(item.note)',
	'components/ProfileInfo.vue: <div class="user-profile__note" dir="auto" v-html="note" />':
		'note is sanitizeHtml(accountInfo.note)',
	'components/AccountHoverCard.vue: <p v-if="note" class="account-hover-card__bio" v-html="note" />':
		'note is sanitizeHtml(account.note)',
	'components/Composer/Composer.vue: this.inputElement().innerHTML = div.innerHTML':
		'div holds only an emoji set through textContent, so its innerHTML is escaped text',
}

/** What turns a string into markup, written as code rather than mentioned in a comment. */
const SINKS = [
	/\bv-html\s*=/,
	/\.(?:innerHTML|outerHTML)\s*=(?!=)/,
	/\binsertAdjacentHTML\s*\(/,
	/\bdocument\.write(?:ln)?\s*\(/,
	/\binnerHTML\s*:/,
	/\bcreateContextualFragment\s*\(/,
]

/** @return {string[]} every .vue and .js file under src/, tests left out */
function sourceFiles(dir = SRC, found = []) {
	for (const entry of readdirSync(dir)) {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) {
			sourceFiles(path, found)
		} else if (/\.(vue|js)$/.test(entry) && !/\.(test|spec)\.js$/.test(entry)) {
			found.push(path)
		}
	}
	return found
}

/** A line that is a comment, in a script or in a template. */
function isComment(line) {
	return /^(\/\/|\*|\/\*|<!--)/.test(line)
}

/** @return {string[]} `file: sink` for every sink in the source */
function sinksInSource() {
	const found = []
	for (const file of sourceFiles()) {
		const name = relative(SRC, file).split('\\').join('/')
		for (const raw of readFileSync(file, 'utf8').split('\n')) {
			const line = raw.trim()
			if (!isComment(line) && SINKS.some((sink) => sink.test(line))) {
				found.push(`${name}: ${line}`)
			}
		}
	}
	return found
}

describe('places markup is built from a string', () => {
	const found = sinksInSource()

	it('finds the sinks it is meant to find', () => {
		expect(found.length).toBeGreaterThanOrEqual(4)
	})

	it('names a sanitised source for every one of them', () => {
		expect(found.filter((sink) => !(sink in ALLOWED))).toEqual([])
	})

	it('lists none that are gone', () => {
		expect(Object.keys(ALLOWED).filter((sink) => !found.includes(sink))).toEqual([])
	})

	it('keeps the sanitiser imported where a v-html renders through it', () => {
		const files = new Set(Object.keys(ALLOWED)
			.filter((sink) => sink.includes('v-html'))
			.map((sink) => sink.slice(0, sink.indexOf(':'))))

		for (const file of files) {
			expect(readFileSync(join(SRC, file), 'utf8'), file).toMatch(/import\s+\{?\s*sanitizeHtml\b[^\n]*from\s+'[^']*sanitizeHtml(\.js)?'/)
		}
	})

	it('recognises each kind of sink and ignores comments', () => {
		expect(SINKS.some((sink) => sink.test('el.innerHTML = html'))).toBe(true)
		expect(SINKS.some((sink) => sink.test('if (el.innerHTML === html) {'))).toBe(false)
		expect(SINKS.some((sink) => sink.test('el.insertAdjacentHTML(\'beforeend\', html)'))).toBe(true)
		expect(SINKS.some((sink) => sink.test('<div v-html="html" />'))).toBe(true)
		expect(isComment('* sets them with `innerHTML`, so every value')).toBe(true)
		expect(isComment('<!-- never handed to v-html -->')).toBe(true)
	})
})
