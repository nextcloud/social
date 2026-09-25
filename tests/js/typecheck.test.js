/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The baseline comparison behind `npm run typecheck` (tools/typecheck.mjs).
 * The last case runs vue-tsc for real, on a component that reads a field it
 * never declares, to show that such a mistake is reported and fails the check.
 */
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'
import { compare, normaliseMessage, parseDiagnostics, runVueTsc, summarise } from '../../tools/typecheck.mjs'

const ROOT = process.cwd()

const OUTPUT = [
	"src/App.vue(31,9): error TS2339: Property 't' does not exist on type 'CreateComponentPublicInstanceWithMixins<ToResolvedProps<{}, {}>, { a: 1 }>'.",
	"src/App.vue(84,10): error TS2339: Property 't' does not exist on type 'CreateComponentPublicInstanceWithMixins<ToResolvedProps<{}, {}>, { a: 1; b: 2 }>'.",
	"src/App.vue(406,5): error TS2322: Type 'string | string[]' is not assignable to type 'string'.",
	"  Type 'string[]' is not assignable to type 'string'.",
	"src/utils/x.js(2,1): error TS2304: Cannot find name 'y'.",
	'',
].join('\n')

describe('parseDiagnostics', () => {
	it('keeps the first line of each error, by file, code and message', () => {
		const diagnostics = parseDiagnostics(OUTPUT)
		expect(diagnostics.map(({ file, line, column }) => [file, line, column])).toEqual([
			['src/App.vue', 31, 9],
			['src/App.vue', 84, 10],
			['src/App.vue', 406, 5],
			['src/utils/x.js', 2, 1],
		])
		expect(diagnostics[2].key).toBe("TS2322 Type 'string | string[]' is not assignable to type 'string'.")
	})

	it('gives one key to errors that differ only in the component instance type', () => {
		const [first, second] = parseDiagnostics(OUTPUT)
		expect(first.key).toBe("TS2339 Property 't' does not exist on type '…'.")
		expect(second.key).toBe(first.key)
	})
})

describe('normaliseMessage', () => {
	it('keeps short types and elides long ones', () => {
		expect(normaliseMessage("Property 'x' does not exist on type 'ServerData'.")).toBe("Property 'x' does not exist on type 'ServerData'.")
		expect(normaliseMessage(`Type '${'a'.repeat(90)}' is wrong.`)).toBe("Type '…' is wrong.")
	})

	/**
	 * Which CI runner a job landed on decided whether the check passed. A
	 * TS2694 names the module it looked in by absolute path, and the length
	 * test alone elided `/home/runner/actions-runner/_work/…` while leaving
	 * `/home/runner/work/…` — so one error read as new on one runner and as
	 * fixed on the other.
	 */
	it('gives one key wherever the checkout happens to be', () => {
		const message = (root) => `Namespace '"${root}/src/components/Composer/Composer.vue"' has no exported member 'LocalAttachment'.`
		const keys = [
			'/home/runner/actions-runner/_work/social/social',
			'/home/runner/work/social/social',
			'/private/tmp/claude/scratchpad/wt-mi',
			'C:\\Users\\someone\\social',
		].map((root) => normaliseMessage(message(root)))

		expect(new Set(keys).size).toBe(1)
		expect(keys[0]).toBe('Namespace \'"src/components/Composer/Composer.vue"\' has no exported member \'LocalAttachment\'.')
	})
})

describe('summarise', () => {
	it('counts per file and key', () => {
		expect(summarise(parseDiagnostics(OUTPUT))).toEqual({
			'src/App.vue': {
				"TS2322 Type 'string | string[]' is not assignable to type 'string'.": 1,
				"TS2339 Property 't' does not exist on type '…'.": 2,
			},
			'src/utils/x.js': {
				"TS2304 Cannot find name 'y'.": 1,
			},
		})
	})
})

describe('compare', () => {
	const baseline = summarise(parseDiagnostics(OUTPUT))

	it('passes the tree the baseline was made from', () => {
		expect(compare(baseline, baseline)).toEqual({ added: [], removed: [] })
	})

	it('is not moved by line numbers', () => {
		const shifted = OUTPUT.replace('(31,9)', '(40,3)').replace('(2,1)', '(12,1)')
		expect(compare(baseline, summarise(parseDiagnostics(shifted)))).toEqual({ added: [], removed: [] })
	})

	it('fails a second occurrence of an error the baseline counts once', () => {
		const more = OUTPUT + "src/utils/x.js(9,1): error TS2304: Cannot find name 'y'.\n"
		expect(compare(baseline, summarise(parseDiagnostics(more))).added).toEqual([
			{ file: 'src/utils/x.js', key: "TS2304 Cannot find name 'y'.", baseline: 1, current: 2 },
		])
	})

	it('fails an error the baseline allows in one file when it appears in another', () => {
		const moved = OUTPUT.replace('src/utils/x.js', 'src/utils/z.js')
		const { added, removed } = compare(baseline, summarise(parseDiagnostics(moved)))
		expect(added.map(({ file }) => file)).toEqual(['src/utils/z.js'])
		expect(removed.map(({ file }) => file)).toEqual(['src/utils/x.js'])
	})

	it('reports errors that went away without failing', () => {
		const fewer = OUTPUT.split('\n').filter((line) => !line.includes('(84,10)')).join('\n')
		expect(compare(baseline, summarise(parseDiagnostics(fewer)))).toEqual({
			added: [],
			removed: [{ file: 'src/App.vue', key: "TS2339 Property 't' does not exist on type '…'.", baseline: 2, current: 1 }],
		})
	})
})

describe('vue-tsc on a component', () => {
	// Inside node_modules so that `vue` resolves, and ignored by git.
	const dir = resolve(ROOT, 'node_modules/.cache/typecheck-test')
	afterAll(() => rmSync(dir, { recursive: true, force: true }))

	it('reports a member the component never declares, and the check fails on it', () => {
		mkdirSync(dir, { recursive: true })
		const config = JSON.parse(readFileSync(join(ROOT, 'jsconfig.json'), 'utf8'))
		writeFileSync(join(dir, 'jsconfig.json'), JSON.stringify({
			compilerOptions: config.compilerOptions,
			include: ['*.vue'],
		}))
		writeFileSync(join(dir, 'Broken.vue'), [
			'<template>',
			'\t<p>{{ label }}</p>',
			'</template>',
			'<script>',
			"import { defineComponent } from 'vue'",
			'export default defineComponent({',
			"\tdata() { return { label: '' } },",
			'\tmethods: {',
			"\t\trename() { this.lable = 'x' },",
			'\t},',
			'})',
			'</script>',
			'',
		].join('\n'))

		const { output, status } = runVueTsc('jsconfig.json', dir)
		expect(status).not.toBe(0)
		const current = summarise(parseDiagnostics(output))
		expect(current).toEqual({
			'Broken.vue': {
				"TS2551 Property 'lable' does not exist on type '…'. Did you mean 'label'?": 1,
			},
		})
		expect(compare({}, current).added).toHaveLength(1)
	}, 60_000)
})
