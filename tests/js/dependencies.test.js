/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8')

/** @return {string[]} every source file under src/ */
function sources(directory = 'src') {
	return readdirSync(directory).flatMap((entry) => {
		const path = join(directory, entry)

		return statSync(path).isDirectory() ? sources(path) : [path]
	}).filter((path) => /\.(js|vue|ts)$/.test(path))
}

const escape = (name) => name.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')

describe('package.json', () => {
	/**
	 * `dependencies` is what the page runs; a build tool listed there is
	 * installed for anybody who only wants the runtime, and reads as
	 * something the bundle needs.
	 */
	it('lists under dependencies only what the code imports, or what those require beside them', () => {
		const { dependencies } = JSON.parse(read('package.json'))
		const code = sources().map((path) => readFileSync(path, 'utf8')).join('\n')
		const imported = (name) => new RegExp(`(?:from|import)\\s*\\(?\\s*(?:/\\*[^*]*\\*/\\s*)?['"]${escape(name)}(?:/[^'"]*)?['"]`).test(code)
		const direct = Object.keys(dependencies).filter(imported)
		const peers = new Set(direct.flatMap((name) => Object.keys(JSON.parse(read(`node_modules/${name}/package.json`)).peerDependencies ?? {})))

		const unused = Object.keys(dependencies).filter((name) => !direct.includes(name) && !peers.has(name))
		expect(unused).toEqual([])
	})
})
