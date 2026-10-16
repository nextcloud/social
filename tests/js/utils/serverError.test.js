/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { errorOf, errorSentence, stoppedMoveError } from '../../../src/utils/serverError.js'

/**
 * The codes a table of the docs lists, as the server sends them.
 *
 * @param {string} file the doc, under docs/
 * @param {string} from the heading the table follows
 * @param {string} to the heading after it
 * @return {string[]} the codes
 */
function codesIn(file, from, to) {
	const docs = readFileSync(resolve(process.cwd(), 'docs', file), 'utf8')
	const start = docs.indexOf(from)
	const section = docs.slice(start, docs.indexOf(to, start))

	return section.split('\n')
		.filter((line) => line.startsWith('| `'))
		.flatMap((line) => [...line.split('|')[1].matchAll(/`([a-z-]+)`/g)].map((match) => match[1]))
}

/** The codes the table of Atproto-Compatibility §13.5 lists. */
function documentedCodes() {
	return codesIn('Atproto-Compatibility.md', '### 13.5 Move error codes', '## 14. Operations')
}

/** The codes the Migration routes answer with, as API.md lists them. */
function migrationCodes() {
	return codesIn('API.md', '#### Migration error codes', '\n### ')
}

/** Words a person moving their account should not have to know. */
const JARGON = /\b(PDS|DID|PLC|XRPC|[Aa]tproto|ActivityPub|[Ff]ediverse|blobs?|repo|HTTP)\b/

describe('errorSentence', () => {
	it('has a plain sentence for every code the server documents', () => {
		const codes = documentedCodes()
		expect(codes.length).toBeGreaterThan(60)

		// not an error: the answer of a move that was called off
		const missing = codes.filter((code) => code !== 'target-account-left' && errorSentence(code) === null)
		expect(missing).toEqual([])
	})

	it('has a plain sentence for every code the Migration routes document', () => {
		const codes = migrationCodes()
		expect(codes.length).toBeGreaterThan(20)

		expect(codes.filter((code) => errorSentence(code) === null)).toEqual([])
		expect(codes.map((code) => errorSentence(code)).filter((sentence) => JARGON.test(sentence))).toEqual([])
	})

	it('says what a Migration refusal means and what to do next', () => {
		expect(errorSentence('import-running')).toBe('That is under way already. See how far it got on the list of imports.')
		expect(errorSentence('alias-missing')).toBe('Your new account does not name this one yet. Add this account as an alias on the new account first, then try again.')
	})

	it('knows the codes the server sends beside the table', () => {
		expect(errorSentence('move-called-off')).toBe('The move was called off.')
		expect(errorSentence('server-error')).toBe('Something went wrong on this server. Try again; if it happens again, ask your administrator.')
		expect(errorSentence('move-interrupted')).toBe('It was cut off before it finished. Try again: it goes on from where it stopped.')
	})

	it('says nothing technical', () => {
		const offending = documentedCodes()
			.map((code) => errorSentence(code))
			.filter((sentence) => sentence !== null && JARGON.test(sentence))
		expect(offending).toEqual([])
	})

	it('knows nothing of a code it was not given, nor of the object prototype', () => {
		expect(errorSentence('no-such-code')).toBeNull()
		expect(errorSentence('constructor')).toBeNull()
		expect(errorSentence(undefined)).toBeNull()
		expect(errorSentence('')).toBeNull()
	})
})

describe('errorOf', () => {
	it('says what the code means, and keeps the server\'s sentence for the details', () => {
		const error = { response: { status: 422, data: { error: 'The PDS at https://pds.example could not be reached', code: 'pds-unreachable' } } }

		expect(errorOf(error, 'Could not start the move')).toEqual({
			text: 'The other server could not be reached. Check the address, or try again later.',
			details: 'The PDS at https://pds.example could not be reached',
		})
	})

	it('falls back to the plain sentence it was given for a code it does not know', () => {
		const error = { response: { status: 422, data: { error: 'Something new', code: 'brand-new' } } }

		expect(errorOf(error, 'Could not start the move')).toEqual({ text: 'Could not start the move', details: 'Something new' })
	})

	it('keeps a message the server sent instead of an error for the details', () => {
		const error = { response: { status: 403, data: { message: 'Password confirmation is required' } } }

		expect(errorOf(error, 'Could not move the account')).toEqual({ text: 'Could not move the account', details: 'Password confirmation is required' })
	})

	it('has no details when the server sent none', () => {
		expect(errorOf(new Error('Network Error'), 'Could not start the move')).toEqual({ text: 'Could not start the move', details: '' })
		expect(errorOf({ response: { status: 500, data: '<html>' } }, 'Could not start the move')).toEqual({ text: 'Could not start the move', details: '' })
	})
})

describe('stoppedMoveError', () => {
	it('explains the code the move stopped with, and keeps what it said for the details', () => {
		expect(stoppedMoveError({ error: '3 pictures or videos did not arrive on the other server', progress: { code: 'blobs-missing' } })).toEqual({
			text: 'Some pictures or videos did not arrive. Copy them again, or go on without them.',
			details: '3 pictures or videos did not arrive on the other server',
		})
	})

	it('says something went wrong for a move that stopped without a code it knows', () => {
		expect(stoppedMoveError({ error: 'signPlcOperation answered 400 (InvalidToken)', progress: {} })).toEqual({
			text: 'Something went wrong on the way. Try again; if it happens again, the details say what.',
			details: 'signPlcOperation answered 400 (InvalidToken)',
		})
	})
})
