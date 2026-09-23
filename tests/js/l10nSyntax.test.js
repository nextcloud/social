/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync, readdirSync } from 'node:fs'
import { Script } from 'node:vm'
import { describe, expect, it } from 'vitest'

describe('JavaScript translation catalogs', () => {
	it('all contain valid JavaScript', () => {
		const directory = 'l10n'
		const catalogs = readdirSync(directory).filter((name) => name.endsWith('.js'))
		expect(catalogs.length).toBeGreaterThan(0)
		for (const catalog of catalogs) {
			expect(() => new Script(readFileSync(`${directory}/${catalog}`, 'utf8'), { filename: catalog })).not.toThrow()
		}
	})
})
