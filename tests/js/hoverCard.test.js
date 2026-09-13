/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * One account preview, and it is this app's.
 *
 * `NcAvatar` hangs Nextcloud's own profile card off a local account's avatar —
 * `triggers: ['hover', 'focus', 'click']` whenever it is given a `user` and no
 * `url` — and this app wraps the same avatars in `AccountHoverCard`. Both open
 * on the same hover and the larger of the two lands on top, so a reader got a
 * card about the Nextcloud user where they asked about the Fediverse account,
 * and only for *local* accounts: which card appeared depended on which
 * instance the account happened to be on.
 *
 * `disableMenu` on every one of them is the rule. It is a rule a new avatar
 * added next year cannot be expected to remember, which is what this test is
 * for: it reads the templates rather than mounting anything, so it catches the
 * omission at the source rather than in whichever view happened to be tested.
 */
const SRC = resolve(process.cwd(), 'src')

/** @return {string[]} every .vue file under src/ */
function templates(directory = SRC, found = []) {
	for (const entry of readdirSync(directory)) {
		const path = join(directory, entry)
		if (statSync(path).isDirectory()) {
			templates(path, found)
		} else if (entry.endsWith('.vue')) {
			found.push(path)
		}
	}

	return found
}

/**
 * Every `<NcAvatar …/>` in the source, with where it is.
 *
 * @return {Array<{file: string, line: number, tag: string}>}
 */
function avatars() {
	return templates().flatMap((file) => {
		const source = readFileSync(file, 'utf8')

		return [...source.matchAll(/<NcAvatar\b[^>]*?\/>/gs)].map((match) => ({
			file: relative(process.cwd(), file),
			line: source.slice(0, match.index).split('\n').length,
			tag: match[0],
		}))
	})
}

describe('the account preview', () => {
	it('is drawn by this app and never by Nextcloud', () => {
		const offenders = avatars()
			// ActorAvatar builds its props in one object, `avatarProps`, which
			// carries disableMenu — asserted on its own below
			.filter(({ tag }) => !tag.includes('v-bind="avatarProps"'))
			.filter(({ tag }) => !tag.includes('disableMenu'))
			.map(({ file, line }) => `${file}:${line}`)

		expect(offenders, offenders.join(', ') + ' — every NcAvatar needs :disableMenu="true",'
		+ ' or Nextcloud\'s profile card opens over this app\'s own').toEqual([])
	})

	it('is switched off in the one place that builds an avatar from props', async () => {
		const { default: ActorAvatar } = await import('../../src/components/ActorAvatar.vue')
		const props = ActorAvatar.computed.avatarProps.call({
			size: 32,
			actor: { acct: 'alice', username: 'alice' },
			isLocal: true,
			avatarUrl: '',
		})

		expect(props.disableMenu).toBe(true)
	})

	it('reads at least the avatars this app is known to draw', () => {
		// a guard on the guard: a glob that silently matched nothing would make
		// every assertion above vacuously true
		expect(avatars().length).toBeGreaterThanOrEqual(10)
	})
})
