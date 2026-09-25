/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Type-checks the whole of src/, single-file components included, against a
 * baseline of the errors the tree already had.
 *
 * `vue-tsc` has no baseline of its own, and the components were outside every
 * check for long enough that holding them to zero at once is not a change one
 * pull request can carry. So what it reports is compared with
 * `tests/js/typecheck-baseline.json`: a file that has an error the baseline
 * does not list, or more of one than the baseline counts, fails the check.
 * Errors that went away pass, with a note on how to shrink the baseline.
 *
 * `node tools/typecheck.mjs` checks; `node tools/typecheck.mjs --update`
 * rewrites the baseline from the current tree.
 *
 * An error is recorded by file, code and message, never by line and column,
 * so an edit elsewhere in the file does not turn an old error into a new one.
 */

import { spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const BASELINE = 'tests/js/typecheck-baseline.json'
const PROJECT = 'jsconfig.vue.json'

/** `src/App.vue(9,51): error TS2339: Property 'x' does not exist …` */
const DIAGNOSTIC = /^(.+?)\((\d+),(\d+)\): error (TS\d+): (.*)$/

/**
 * The instance type of a component spells out every field, computed and
 * method it has, so it changes whenever one is added. A quoted type that
 * long says nothing a reader needs, and would make every error that mentions
 * it look new after an unrelated edit.
 *
 * @param {string} message the first line of a diagnostic
 * @return {string} the message with component instance types and very long types elided
 */
export function normaliseMessage(message) {
	return message.replace(/'[^']*'/g, (quoted) => (
		quoted.length > 80 || quoted.includes('ComponentPublicInstance') ? "'…'" : quoted
	))
}

/**
 * @typedef {object} Diagnostic
 * @property {string} file path relative to the app root, with forward slashes
 * @property {number} line one-based
 * @property {number} column one-based
 * @property {string} key code and normalised message, what the baseline counts
 * @property {string} text the line as the compiler printed it
 */

/**
 * Reads `--pretty false` output. Only the first line of a diagnostic is kept:
 * the indented lines after it elaborate on the same error.
 *
 * @param {string} output what vue-tsc printed
 * @return {Diagnostic[]}
 */
export function parseDiagnostics(output) {
	const diagnostics = []
	for (const line of output.split(/\r?\n/)) {
		const match = DIAGNOSTIC.exec(line)
		if (!match) {
			continue
		}
		const [, file, row, column, code, message] = match
		diagnostics.push({
			file: file.replaceAll('\\', '/'),
			line: Number(row),
			column: Number(column),
			key: `${code} ${normaliseMessage(message)}`,
			text: line,
		})
	}
	return diagnostics
}

/**
 * @typedef {Record<string, Record<string, number>>} Baseline file → key → count
 */

/**
 * @param {Diagnostic[]} diagnostics as parseDiagnostics returns them
 * @return {Baseline} counts per file and key, both sorted so the file diffs cleanly
 */
export function summarise(diagnostics) {
	/** @type {Baseline} */
	const counts = {}
	for (const { file, key } of diagnostics) {
		counts[file] ??= {}
		counts[file][key] = (counts[file][key] ?? 0) + 1
	}
	/** @type {Baseline} */
	const sorted = {}
	for (const file of Object.keys(counts).sort()) {
		sorted[file] = {}
		for (const key of Object.keys(counts[file]).sort()) {
			sorted[file][key] = counts[file][key]
		}
	}
	return sorted
}

/**
 * @typedef {object} Change
 * @property {string} file as in the baseline
 * @property {string} key code and normalised message
 * @property {number} baseline how many the baseline allows
 * @property {number} current how many there are now
 */

/**
 * @param {Baseline} baseline what was accepted
 * @param {Baseline} current what the tree has now
 * @return {{ added: Change[], removed: Change[] }} errors beyond the baseline, and baseline entries no longer needed
 */
export function compare(baseline, current) {
	const added = []
	const removed = []
	const files = new Set([...Object.keys(baseline), ...Object.keys(current)])
	for (const file of [...files].sort()) {
		const was = baseline[file] ?? {}
		const now = current[file] ?? {}
		const keys = new Set([...Object.keys(was), ...Object.keys(now)])
		for (const key of [...keys].sort()) {
			const change = { file, key, baseline: was[key] ?? 0, current: now[key] ?? 0 }
			if (change.current > change.baseline) {
				added.push(change)
			} else if (change.current < change.baseline) {
				removed.push(change)
			}
		}
	}
	return { added, removed }
}

/**
 * @param {Baseline} baseline counts per file and key
 * @return {number} how many errors it accepts in all
 */
export function total(baseline) {
	return Object.values(baseline)
		.flatMap((keys) => Object.values(keys))
		.reduce((sum, count) => sum + count, 0)
}

/**
 * @param {string} project the tsconfig to check, relative to cwd
 * @param {string} cwd where to run it, and what the reported paths are relative to
 * @return {{ output: string, status: number }} what vue-tsc printed, and its exit status
 */
export function runVueTsc(project, cwd) {
	// not vue-tsc's own bin: that resolves `typescript/lib/tsc`, which
	// TypeScript 7 does not ship. See tools/vue-tsc-run.cjs.
	//
	// Anchored to the process, not to `cwd` — which is the project being
	// checked, a scratch directory under node_modules in the tests — and not
	// to `import.meta.url`, which is not a file URL when vitest imports this.
	const bin = join(process.cwd(), 'tools', 'vue-tsc-run.cjs')
	const result = spawnSync(process.execPath, [bin, '-p', project, '--pretty', 'false'], {
		cwd,
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	})
	if (result.error) {
		throw result.error
	}
	return { output: `${result.stdout}${result.stderr}`, status: result.status ?? 1 }
}

/** @param {string} line one line for the terminal */
const say = (line) => process.stdout.write(line + '\n')
/** @param {string} line one line for the terminal, on stderr */
const fail = (line) => process.stderr.write(line + '\n')

/**
 * @return {number} the process exit code
 */
function main() {
	const root = fileURLToPath(new URL('..', import.meta.url))
	const baselinePath = join(root, BASELINE)
	const { output, status } = runVueTsc(PROJECT, root)
	const diagnostics = parseDiagnostics(output)
	if (status !== 0 && diagnostics.length === 0) {
		// It failed without reporting a type error: a configuration problem or
		// a crash, and nothing a baseline could account for.
		process.stderr.write(output)
		return 1
	}
	const current = summarise(diagnostics)

	if (process.argv.includes('--update')) {
		writeFileSync(baselinePath, JSON.stringify(current, null, '\t') + '\n')
		say(`Baseline written: ${total(current)} errors in ${Object.keys(current).length} files.`)
		return 0
	}

	const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'))
	const { added, removed } = compare(baseline, current)

	if (added.length > 0) {
		fail('vue-tsc reports errors the baseline does not allow:\n')
		for (const { file, key, baseline: allowed, current: found } of added) {
			fail(`${file}: ${key}`)
			fail(`  ${found} now, ${allowed} in the baseline. Where it occurs:`)
			for (const diagnostic of diagnostics) {
				if (diagnostic.file === file && diagnostic.key === key) {
					fail(`    ${file}(${diagnostic.line},${diagnostic.column})`)
				}
			}
		}
		fail('\nFix them; the baseline is for errors the tree already had, not new ones.')
		return 1
	}

	say(`vue-tsc: ${total(current)} errors, none beyond the baseline of ${total(baseline)}.`)
	if (removed.length > 0) {
		const gone = removed.reduce((sum, change) => sum + change.baseline - change.current, 0)
		say(`${gone} of the errors in the baseline are gone. Shrink it with`)
		say('  npm run typecheck:baseline')
		say('and commit tests/js/typecheck-baseline.json with the change that fixed them.')
	}
	return 0
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	process.exitCode = main()
}
