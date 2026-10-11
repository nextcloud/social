/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { devices, expect, test } from '@playwright/test'
import { OTHER_PASSWORD, OTHER_USER, PASSWORD, USER, apiAs, appPages, ensureAccount, login, openAppPage, postStatus } from './helpers.mjs'
import { CODE, PSEUDO_CHARACTERS, pseudoLocalise } from './pseudo-locale.mjs'

/**
 * The app read in the pseudo-locale pseudo-locale.mjs writes, where every
 * string that went through t() or n() is accented, a third longer and in
 * brackets. Two things are wrong on a page shown in it:
 *
 * - text in plain English inside the app: a string that never reaches the
 *   translators, and stays English in every language;
 * - a page that no longer fits: the document or the content column wider
 *   than the window, or text cut off by a box that hides its overflow
 *   without an ellipsis, which is what a longer language does to a layout
 *   measured on English. On a desktop window and on a phone.
 *
 * Only with E2E_PSEUDO_LOCALE=1, against an instance where the locale is
 * installed and is E2E_USER's language — `node tests/e2e/pseudo-locale.mjs`
 * writes it into the app's l10n/, `occ user:setting <user> core lang en_XA`
 * chooses it — as the `en_XA` leg of e2e.yml sets up. Everybody else's
 * specs expect English, so this one runs on its own.
 */
test.skip(process.env.E2E_PSEUDO_LOCALE !== '1', 'needs the pseudo-locale installed and chosen; see the docblock')

/**
 * Text inside the app that is not the app's to translate because somebody
 * wrote it: posts, poll options, previews of messages, and the names of the
 * platforms the statistics count, as their servers report them.
 */
const USER_CONTENT = [
	'.post-message',
	'.post-author',
	'.post-author-id',
	'.poll__result-title',
	'.direct-messages__preview',
	'.stats__post-text',
	'.stats__best-text',
	'.stats__platform-name',
	'.avatardiv',
	'time',
]

/**
 * Text written in the source that is the same in every language: the names
 * of the services the statistics link to.
 */
const SAME_IN_EVERY_LANGUAGE = ['FediDB ↗', 'Fediverse Observer ↗']

/**
 * The visible text nodes in the app's container that carry a word of plain
 * English, leaving out what people wrote and are called (USER_CONTENT, the
 * accounts' names, hashtags, handles, server names) and what the browser
 * formats itself in the page's language: dates, weekdays, months, "now",
 * names of languages.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} names display names, user names and group names the instance has
 * @return {Promise<{text: string, where: string}[]>}
 */
async function untranslated(page, names) {
	return page.evaluate(({ pseudo, ignore, known }) => {
		const lang = document.documentElement.lang || 'en'
		const intlWords = new Set()
		const addWords = (text) => text.split(/[^\p{L}]+/u).filter(Boolean).forEach((word) => intlWords.add(word.toLowerCase()))
		for (let day = 0; day < 7; day++) {
			const date = new Date(Date.UTC(2024, 0, 7 + day))
			addWords(date.toLocaleDateString(lang, { weekday: 'long' }) + ' ' + date.toLocaleDateString(lang, { weekday: 'short' }))
		}
		for (let month = 0; month < 12; month++) {
			const date = new Date(Date.UTC(2024, month, 15))
			addWords(date.toLocaleDateString(lang, { month: 'long' }) + ' ' + date.toLocaleDateString(lang, { month: 'short' }))
		}
		addWords(new Date().toLocaleTimeString(lang, { hour: 'numeric', hour12: true }) + ' ' + new Date(Date.UTC(2024, 0, 1, 23)).toLocaleTimeString(lang, { hour: 'numeric', hour12: true }))
		for (const style of ['long', 'short', 'narrow']) {
			const relative = new Intl.RelativeTimeFormat(lang, { numeric: 'auto', style })
			for (const unit of ['second', 'minute', 'hour', 'day', 'week', 'month', 'year']) {
				for (const value of [-2, -1, 0, 1, 2]) {
					addWords(relative.format(value, unit))
				}
			}
		}
		const languageNames = new Intl.DisplayNames([lang], { type: 'language' })
		const languages = new Set()
		for (const first of 'abcdefghijklmnopqrstuvwxyz') {
			for (const second of 'abcdefghijklmnopqrstuvwxyz') {
				languages.add(languageNames.of(first + second))
			}
		}

		const english = /[A-Za-z]{2}/
		const pseudoCharacter = new RegExp(`[${pseudo}]`)
		const handleOrHost = /^(@?[\w.-]+@[\w.-]+(:\d+)?|@[\w.-]+|[\w-]+(\.[\w-]+)*(\.[\w-]+|:\d+)|#[\p{L}\d_]+)$/u
		const formatted = (text) => !english.test(text.split(/([^\p{L}]+)/u).filter((word) => !intlWords.has(word.toLowerCase())).join(''))

		const found = []
		const walker = document.createTreeWalker(document.querySelector('#content'), NodeFilter.SHOW_TEXT)
		for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
			const text = node.textContent.replace(/\s+/g, ' ').trim()
			if (!english.test(text) || pseudoCharacter.test(text) || known.includes(text)
				|| handleOrHost.test(text) || languages.has(text) || formatted(text)) {
				continue
			}
			const parent = node.parentElement
			if (parent === null || parent.closest(ignore) !== null || parent.closest('[aria-hidden="true"], script, style') !== null) {
				continue
			}
			if (!parent.checkVisibility({ visibilityProperty: true, opacityProperty: true })) {
				continue
			}
			const where = [parent, parent.parentElement, parent.parentElement?.parentElement].filter(Boolean)
				.map((element) => element.tagName.toLowerCase() + [...element.classList].map((name) => '.' + name).join(''))
				.reverse().join(' > ')
			found.push({ text, where })
		}
		return found
	}, { pseudo: PSEUDO_CHARACTERS, ignore: USER_CONTENT.join(', '), known: [...names, ...SAME_IN_EVERY_LANGUAGE] })
}

/**
 * What does not fit: how far the document reaches past the window, how far
 * the app's content column scrolls sideways (it scrolls on its own, so a
 * page too wide for it widens it rather than the document), and the
 * elements in the app that cut their text off.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<{documentOverflow: number, contentOverflow: number, clipped: {where: string, text: string, by: number}[]}>}
 */
async function overflow(page) {
	return page.evaluate(() => {
		const clipped = []
		for (const element of document.querySelectorAll('#content *')) {
			if (element.scrollWidth <= element.clientWidth + 1) {
				continue
			}
			const style = getComputedStyle(element)
			if (!['hidden', 'clip'].includes(style.overflowX) || style.textOverflow === 'ellipsis') {
				continue
			}
			// a box that clips on purpose: an icon, a one-pixel label for
			// screen readers
			if (element.clientWidth < 24 || !element.checkVisibility({ visibilityProperty: true })) {
				continue
			}
			// what is cut off has to be text: the box may be wider inside for
			// a picture or a shadow. Text an inner box ends in an ellipsis is
			// that box's business.
			const box = element.getBoundingClientRect()
			const cut = []
			const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
			for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
				let inner = node.parentElement
				while (inner !== element && inner !== null && getComputedStyle(inner).textOverflow !== 'ellipsis') {
					inner = inner.parentElement
				}
				if (inner !== element || node.textContent.trim() === '') {
					continue
				}
				const range = document.createRange()
				range.selectNodeContents(node)
				if ([...range.getClientRects()].some((rect) => rect.width > 1 && (rect.right > box.right + 1 || rect.left < box.left - 1))) {
					cut.push(node.textContent.replace(/\s+/g, ' ').trim())
				}
			}
			if (cut.length > 0) {
				clipped.push({
					where: element.tagName.toLowerCase() + [...element.classList].map((name) => '.' + name).join(''),
					text: cut.join(' ').slice(0, 80),
					by: element.scrollWidth - element.clientWidth,
				})
			}
		}
		const column = document.querySelector('#app-content-vue')
		return {
			documentOverflow: document.documentElement.scrollWidth - window.innerWidth,
			contentOverflow: column === null ? 0 : column.scrollWidth - column.clientWidth,
			clipped,
		}
	})
}

/** @type {string} */
let postPath
/** @type {string[]} */
let names

test.beforeAll(async () => {
	const other = await apiAs(OTHER_USER, OTHER_PASSWORD)
	const me = await apiAs(USER, PASSWORD)
	try {
		const accounts = [await ensureAccount(other, OTHER_USER), await ensureAccount(me, USER)]
		names = [...accounts.flatMap((account) => [account.display_name, account.username, account.acct]), process.env.E2E_GROUP ?? '']
			.filter(Boolean)
		const status = await postStatus(other, { status: `A post to read in another language ${Date.now()}` })
		postPath = `/@${OTHER_USER}/${status.id}`
	} finally {
		await other.dispose()
		await me.dispose()
	}
})

/**
 * Walks every page and checks it, failing softly so one run lists them all.
 *
 * @param {import('@playwright/test').Page} page
 */
async function everyPage(page) {
	const pages = [...appPages(), { name: 'post', path: postPath, marker: 'article' }]
	test.setTimeout(pages.length * 30_000)

	await login(page)
	await openAppPage(page, pages[0])
	await expect(page.locator('.app-navigation').getByText(pseudoLocalise('My Feed')).first(), `the account reads the app in ${CODE}`)
		.toBeAttached()

	for (const target of pages) {
		await test.step(target.name, async () => {
			await openAppPage(page, target)
			const fit = await overflow(page)
			expect.soft(await untranslated(page, names), `${target.name}: text that did not go through t() or n()`).toEqual([])
			expect.soft(fit.documentOverflow, `${target.name}: the page is wider than the window`).toBeLessThanOrEqual(1)
			expect.soft(fit.contentOverflow, `${target.name}: the content scrolls sideways`).toBeLessThanOrEqual(1)
			expect.soft(fit.clipped, `${target.name}: text cut off without an ellipsis`).toEqual([])
		})
	}
}

test.describe(`the app in the ${CODE} pseudo-locale`, () => {
	test('on a desktop, every page is translated and fits', async ({ page }) => {
		await everyPage(page)
	})
})

// without its browser: `defaultBrowserType` cannot be set inside a describe
const phone = { ...devices['Pixel 7'] }
delete phone.defaultBrowserType

test.describe(`the app in the ${CODE} pseudo-locale, on a phone`, () => {
	test.use(phone)

	test('every page is translated and fits', async ({ page }) => {
		await everyPage(page)
	})
})
