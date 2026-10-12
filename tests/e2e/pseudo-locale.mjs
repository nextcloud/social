/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Writes a pseudo-locale for the app: every string it offers for
 * translation, accented, a third longer and in brackets, so `Settings`
 * reads `[Šéţţîñĝš ~]`. A page shown in it tells two things at a glance:
 * text that is still plain English never went through t() or n(), and a
 * layout that only just fits English breaks.
 *
 *   node tests/e2e/pseudo-locale.mjs [directory]
 *
 * writes `en_XA.json` and `en_XA.js` into the directory (the app's l10n/
 * by default), the two files Nextcloud reads a language from. `en_XA` is
 * the code Android and Chromium give their accented pseudo-locale: XA is in
 * the range ISO 3166 leaves to private use, so no real translation will
 * ever arrive under it, and the underscore is how Nextcloud spells a
 * region. Nextcloud offers an app every language it finds a JSON file for
 * in the app's l10n/, and none of them for core, so a user whose language
 * is `en_XA` gets this app's strings in it and Nextcloud's own in English.
 *
 * The strings are read from the source the way the translation extractor
 * reads them (.l10nignore, tests/TranslatableStringsTest.php): string
 * literals passed to t() and n() — and to translate() and translatePlural(),
 * their longer names — in src/ and templates/, and to `->t()` and `->n()`
 * in lib/ and templates/. A string written any other way is not found, as
 * it is not found for the translators either.
 */

import { mkdirSync, readFileSync, readdirSync, realpathSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const CODE = 'en_XA'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const SOURCES = ['src', 'lib', 'templates']
const EXTENSIONS = /\.(vue|js|mjs|ts|php)$/

const PLAIN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
const ACCENTED = 'åƀçðéƒĝĥîĵķļɱñöþǫŕšţûṽŵẋýžÅƁÇÐÉƑĜĤÎĴĶĻṀÑÖÞǪŔŠŢÛṼŴẊÝŽ'

/** Every character a pseudo-localised string is written in, for telling one apart. */
export const PSEUDO_CHARACTERS = ACCENTED

/**
 * What a string must keep as it is: placeholders (`{name}`, `%s`, `%1$s`,
 * `%n`, `%%`), HTML tags and entities.
 */
const KEEP = /(\{[^{}]*\}|%(?:\d+\$)?[sdnf%]|<[^>]*>|&[a-zA-Z#0-9]+;)/

/**
 * One string in the pseudo-locale.
 *
 * @param {string} text
 * @return {string}
 */
export function pseudoLocalise(text) {
	const accented = text.split(KEEP)
		.map((part, index) => index % 2 === 1 ? part : part.replace(/[a-zA-Z]/g, (letter) => ACCENTED[PLAIN.indexOf(letter)]))
		.join('')
	// a third longer than the English, counting the brackets and the space,
	// in words of five so the padding wraps the way text does
	const extra = Math.max(1, Math.round(text.length * 0.32) - 3)
	const padding = '~'.repeat(extra).replace(/(~{5})(?=~)/g, '$1 ')

	return `[${accented} ${padding}]`
}

const LITERAL = String.raw`('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|\x60(?:[^\x60\\$]|\\.)*\x60)`
const APP = String.raw`\s*['"]social['"]\s*,\s*`
const PATTERNS = [
	{ plural: false, pattern: new RegExp(String.raw`(?<![\w$>])(?:t|translate)\(${APP}${LITERAL}`, 'g') },
	{ plural: true, pattern: new RegExp(String.raw`(?<![\w$>])(?:n|translatePlural)\(${APP}${LITERAL}\s*,\s*${LITERAL}`, 'g') },
	{ plural: false, pattern: new RegExp(String.raw`->t\(\s*${LITERAL}`, 'g') },
	{ plural: true, pattern: new RegExp(String.raw`->n\(\s*${LITERAL}\s*,\s*${LITERAL}`, 'g') },
]

/**
 * The text a quoted literal stands for.
 *
 * @param {string} literal with its quotes
 * @return {string}
 */
function unquote(literal) {
	return literal.slice(1, -1).replace(/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/gs, (_, escape) => {
		if (escape.startsWith('u{')) {
			return String.fromCodePoint(parseInt(escape.slice(2, -1), 16))
		}
		if (/^[ux][0-9a-fA-F]+$/.test(escape)) {
			return String.fromCharCode(parseInt(escape.slice(1), 16))
		}
		return { n: '\n', t: '\t', r: '\r' }[escape] ?? escape
	})
}

/** @param {string} directory */
function* files(directory) {
	for (const entry of readdirSync(directory)) {
		const path = join(directory, entry)
		if (statSync(path).isDirectory()) {
			yield* files(path)
		} else if (EXTENSIONS.test(entry)) {
			yield path
		}
	}
}

/**
 * Every message in the source, keyed the way a Nextcloud catalogue keys
 * it: the text itself, or `_singular_::_plural_` for a plural.
 *
 * @param {string} root the app's directory
 * @return {Map<string, string|string[]>} key → the English it translates
 */
export function sourceMessages(root = ROOT) {
	const messages = new Map()
	for (const source of SOURCES) {
		let paths
		try {
			paths = [...files(join(root, source))]
		} catch {
			continue
		}
		for (const path of paths) {
			const body = readFileSync(path, 'utf8')
			for (const { plural, pattern } of PATTERNS) {
				for (const match of body.matchAll(pattern)) {
					const singular = unquote(match[1])
					if (singular.trim() === '') {
						continue
					}
					if (plural) {
						messages.set(`_${singular}_::_${unquote(match[2])}_`, [singular, unquote(match[2])])
					} else {
						messages.set(singular, singular)
					}
				}
			}
		}
	}

	return messages
}

/**
 * The catalogue: the same messages, pseudo-localised.
 *
 * @param {Map<string, string|string[]>} messages
 * @return {Record<string, string|string[]>}
 */
export function pseudoCatalogue(messages) {
	return Object.fromEntries([...messages].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([key, english]) => [
		key,
		Array.isArray(english) ? english.map(pseudoLocalise) : pseudoLocalise(english),
	]))
}

const PLURAL_FORM = 'nplurals=2; plural=(n != 1);'

/**
 * Writes the pseudo-locale as Nextcloud's two catalogue files.
 *
 * @param {string} directory
 * @return {number} how many messages it holds
 */
export function writePseudoLocale(directory = join(ROOT, 'l10n')) {
	const catalogue = pseudoCatalogue(sourceMessages())
	mkdirSync(directory, { recursive: true })
	writeFileSync(join(directory, `${CODE}.json`), JSON.stringify({ translations: catalogue, pluralForm: PLURAL_FORM }, null, '\t') + '\n')
	writeFileSync(join(directory, `${CODE}.js`), `OC.L10N.register(\n\t"social",\n\t${JSON.stringify(catalogue, null, '\t').replace(/\n/g, '\n\t')},\n\t${JSON.stringify(PLURAL_FORM)}\n);\n`)

	return Object.keys(catalogue).length
}

// run as a script rather than imported, also through a symbolic link to the app
if (process.argv[1] && realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url))) {
	const directory = process.argv[2] ? resolve(process.argv[2]) : join(ROOT, 'l10n')
	const count = writePseudoLocale(directory)
	process.stdout.write(`${count} messages written to ${join(directory, CODE)}.{json,js}\n`)
}
