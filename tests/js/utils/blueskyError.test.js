/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { blueskyErrorOf, blueskyErrorSentence, stoppedMoveError } from '../../../src/utils/blueskyError.js'

/** The codes the table of Atproto-Compatibility §13.5 lists, as the server sends them. */
function documentedCodes() {
	const docs = readFileSync(resolve(process.cwd(), 'docs/Atproto-Compatibility.md'), 'utf8')
	const section = docs.slice(docs.indexOf('### 13.5 Move error codes'), docs.indexOf('## 14. Operations'))

	return section.split('\n')
		.filter((line) => line.startsWith('| `'))
		.flatMap((line) => [...line.split('|')[1].matchAll(/`([a-z-]+)`/g)].map((match) => match[1]))
}

/** Words a person moving their account should not have to know. */
const JARGON = /\b(PDS|DID|PLC|XRPC|[Aa]tproto|ActivityPub|[Ff]ediverse|blobs?|repo|HTTP)\b/

describe('blueskyErrorSentence', () => {
	it('has a plain sentence for every code the server documents', () => {
		const codes = documentedCodes()
		expect(codes.length).toBeGreaterThan(60)

		// not an error: the answer of a move that was called off
		const missing = codes.filter((code) => code !== 'target-account-left' && blueskyErrorSentence(code) === null)
		expect(missing).toEqual([])
	})

	it('knows the codes the server sends beside the table', () => {
		expect(blueskyErrorSentence('move-called-off')).toBe('The move was called off.')
		expect(blueskyErrorSentence('server-error')).toBe('Something went wrong on this server. Try again; if it happens again, ask your administrator.')
		expect(blueskyErrorSentence('move-interrupted')).toBe('It was cut off before it finished. Try again: it goes on from where it stopped.')
	})

	it('says nothing technical', () => {
		const offending = documentedCodes()
			.map((code) => blueskyErrorSentence(code))
			.filter((sentence) => sentence !== null && JARGON.test(sentence))
		expect(offending).toEqual([])
	})

	it('knows nothing of a code it was not given, nor of the object prototype', () => {
		expect(blueskyErrorSentence('no-such-code')).toBeNull()
		expect(blueskyErrorSentence('constructor')).toBeNull()
		expect(blueskyErrorSentence(undefined)).toBeNull()
		expect(blueskyErrorSentence('')).toBeNull()
	})
})

describe('blueskyErrorOf', () => {
	it('says what the code means, and keeps the server\'s sentence for the details', () => {
		const error = { response: { status: 422, data: { error: 'The PDS at https://pds.example could not be reached', code: 'pds-unreachable' } } }

		expect(blueskyErrorOf(error, 'Could not start the move')).toEqual({
			text: 'The other server could not be reached. Check the address, or try again later.',
			details: 'The PDS at https://pds.example could not be reached',
		})
	})

	it('falls back to the plain sentence it was given for a code it does not know', () => {
		const error = { response: { status: 422, data: { error: 'Something new', code: 'brand-new' } } }

		expect(blueskyErrorOf(error, 'Could not start the move')).toEqual({ text: 'Could not start the move', details: 'Something new' })
	})

	it('has no details when the server sent none', () => {
		expect(blueskyErrorOf(new Error('Network Error'), 'Could not start the move')).toEqual({ text: 'Could not start the move', details: '' })
		expect(blueskyErrorOf({ response: { status: 500, data: '<html>' } }, 'Could not start the move')).toEqual({ text: 'Could not start the move', details: '' })
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
