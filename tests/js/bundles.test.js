/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * What the built bundles are allowed to contain.
 *
 * These read the committed output in js/, which is what an installation
 * actually serves — the thing worth asserting about, and the reason the
 * directory is in the repository at all.
 */
const JS = resolve(process.cwd(), 'js')

function bundle(name) {
	const path = join(JS, name)

	return existsSync(path) ? readFileSync(path, 'utf8') : null
}

/** The picker's own payload, not merely a reference to the chunk that holds it. */
const CARRIES_EMOJI_DATA = /emoji-mart-vue-fast|"skin_variations"|frequently-used-emojis/

describe('the built bundles', () => {
	it('are present, because the tests below read what is actually served', () => {
		expect(existsSync(JS)).toBe(true)
		expect(readdirSync(JS).some((entry) => entry.endsWith('.js'))).toBe(true)
	})

	it.each(['social-dashboard.js', 'social-profilePage.js', 'social-ostatus.js'])(
		'%s does not carry the emoji picker',
		(name) => {
			const content = bundle(name)
			if (content === null) {
				// a partial build; the presence check above is what guards that
				return
			}

			// none of these can compose a post, and the picker is most of a
			// megabyte — it used to arrive with the post overflow menu
			expect(CARRIES_EMOJI_DATA.test(content)).toBe(false)
		},
	)

	it('keeps the picker in a chunk of its own', () => {
		const picker = bundle('social-emoji-picker.js')
		expect(picker, 'social-emoji-picker.js is missing; run the build').not.toBeNull()
		expect(CARRIES_EMOJI_DATA.test(picker)).toBe(true)
	})

	it('keeps the chunk that carries the post menu small', () => {
		const menus = readdirSync(JS).filter((entry) => entry.includes('NcActionButton') && entry.endsWith('.js'))
		expect(menus.length).toBeGreaterThan(0)

		for (const name of menus) {
			// every post's "..." menu needs this; it was 1.1 MB when the emoji
			// picker shared it
			const size = statSync(join(JS, name)).size
			expect(size, `${name} is ${Math.round(size / 1024)} KB`).toBeLessThan(400 * 1024)
		}
	})
})

/**
 * The framework — Vue, @nextcloud/vue, pinia — used to be compiled into each of
 * the five entry points separately, so opening the Dashboard and then the app
 * downloaded all of it twice. It is now one chunk they share.
 *
 * That makes an entry no longer self-contained, and the way it fails is silent:
 * webpack's runtime queues the startup module and waits for a chunk that never
 * arrives, so the script runs to completion and the page simply stays empty.
 * Nothing throws and nothing reaches the console. These tests are here because
 * that is not a failure anybody would notice from a stack trace.
 */
describe('the shared framework chunk', () => {
	const ENTRIES = [
		'social-social.js',
		'social-dashboard.js',
		'social-profilePage.js',
		'social-oauth.js',
		'social-ostatus.js',
	]

	/** Evaluates scripts in order in one window; answers what booting produced. */
	async function boot(names) {
		const { JSDOM } = await import('jsdom')
		const dom = new JSDOM(
			'<!doctype html><html><head></head><body><div id="content"></div></body></html>',
			{ runScripts: 'outside-only', url: 'http://localhost/index.php/apps/social/' },
		)
		const w = dom.window
		w.structuredClone = structuredClone
		w.OC = { requestToken: 'x', config: {}, getCurrentUser: () => ({ uid: 'alice' }) }
		w.OCA = {}
		w.OCP = {}

		for (const name of names) {
			const content = bundle(name)
			if (content === null) {
				return null
			}
			try {
				w.eval(content)
			} catch {
				// the bundles expect a whole Nextcloud page around them and will
				// stop somewhere inside it; how far they got is the question, and
				// the stylesheets below answer it
			}
		}

		return w.document.querySelectorAll('style').length
	}

	it('is a chunk the entries share, not an entry of its own', () => {
		const framework = bundle('social-framework.js')
		expect(framework, 'social-framework.js is missing; run the build').not.toBeNull()

		// a chunk registers itself and waits; an entry carries a runtime and
		// starts. The license banner webpack writes comes first either way.
		const code = framework.replace(/^\/\*![^]*?\*\/\s*/, '')
		expect(code.startsWith('(self.webpackChunksocial=self.webpackChunksocial||[]).push('))
			.toBe(true)
	})

	it.each(ENTRIES)('%s no longer carries its own copy of the framework', (name) => {
		const content = bundle(name)
		if (content === null) {
			return
		}

		// each of these was over 900 KB with the framework inside it
		expect(statSync(join(JS, name)).size, `${name} looks like it still has one`)
			.toBeLessThan(320 * 1024)
	})

	it('does nothing when an entry is served without it', async () => {
		// what a Util::addScript() that forgot the framework would produce: no
		// error, no output, an empty page
		expect(await boot(['social-social.js'])).toBe(0)
	})

	it('boots the app when it is served first', async () => {
		expect(await boot(['social-framework.js', 'social-social.js'])).toBeGreaterThan(0)
	})

	it('boots the dashboard widget when it is served first', async () => {
		expect(await boot(['social-framework.js', 'social-dashboard.js'])).toBeGreaterThan(0)
	})
})

/**
 * Every page that loads one of those entries has to load the framework first.
 * These are PHP files, but the contract they carry is this bundle's, so it is
 * pinned next to it rather than somewhere the build is not in view.
 */
describe('the pages that load the bundles', () => {
	const SITES = [
		['templates/main.php', 'social-social'],
		['templates/oauth2.php', 'social-oauth'],
		['lib/Dashboard/SocialWidget.php', 'social-dashboard'],
		['lib/Listeners/ProfileSectionListener.php', 'social-profilePage'],
		['lib/Settings/AdminSettings.php', 'social-adminAnnouncements'],
		['lib/Settings/AdminSettings.php', 'social-adminModeration'],
	]

	it.each(SITES)('%s loads the framework before %s', (file, entry) => {
		const source = readFileSync(resolve(process.cwd(), file), 'utf8')

		const framework = source.indexOf("'social-framework'")
		const target = source.indexOf(`'${entry}'`)

		expect(target, `${file} no longer loads ${entry}`).toBeGreaterThan(-1)
		expect(framework, `${file} does not load social-framework`).toBeGreaterThan(-1)
		expect(framework, `${file} loads ${entry} before the framework it needs`)
			.toBeLessThan(target)
	})
})
