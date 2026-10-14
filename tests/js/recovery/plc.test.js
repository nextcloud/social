/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	buildOperation,
	cidOf,
	dagCbor,
	keyForms,
	keyFromPhrase,
	privateKeyFrom,
	recover,
	RecoveryError,
	rekey,
	rotationKeysOf,
	serviceAuthToken,
	toHex,
	verifyOperation,
} from '../../../src/recovery/plc.js'

/*
 * The tool's half of the cross-language fixtures that
 * tests/Atproto/Identity/RecoveryToolFixturesTest.php writes from PHP: the
 * same key from the same words, the same operation bytes and CID, a
 * signature PHP made that verifies here, and the operation signed here,
 * which PHP verifies in turn. With SOCIAL_UPDATE_FIXTURES set this writes
 * that last part.
 */
const FIXTURE = resolve(process.cwd(), 'tests/Atproto/Identity/fixtures/recovery-tool.json')
const WORDS = readFileSync(resolve(process.cwd(), 'resources/atproto/bip39-english.txt'), 'utf8').trim().split(/\r?\n/)
const fixture = JSON.parse(readFileSync(FIXTURE, 'utf8'))
const { operation: handOver } = fixture

describe('the recovery tool against the PHP side', () => {
	it.each(fixture.phrases.map((entry) => [entry.phrase, entry]))('derives the key Mnemonic derives from "%s"', (phrase, expected) => {
		const secret = keyFromPhrase(phrase, WORDS)

		expect(keyForms(secret)).toEqual({ didKey: expected.did_key, multibase: expected.multibase, hex: expected.hex })
		expect(toHex(privateKeyFrom(expected.multibase, WORDS))).toBe(expected.hex)
		expect(toHex(privateKeyFrom(expected.hex.toUpperCase(), WORDS))).toBe(expected.hex)
		expect(toHex(privateKeyFrom('  ' + phrase.toUpperCase().replace(/ /g, '\n') + ' ', WORDS))).toBe(expected.hex)
	})

	it('names the latest operation by the CID PHP gives it', () => {
		expect(cidOf(handOver.last)).toBe(handOver.last_cid)
		expect(cidOf(handOver.php_signed)).toBe(handOver.php_cid)
	})

	it('builds the hand-over PlcOperation::fromCredentials() builds, byte for byte', () => {
		const { didKey } = keyForms(keyFromPhrase(handOver.phrase, WORDS))

		expect(toHex(dagCbor(buildOperation(handOver.credentials, didKey, handOver.last_cid)))).toBe(handOver.unsigned_cbor_hex)
	})

	it('accepts the signature PHP made, and only from that key', () => {
		const { didKey } = keyForms(keyFromPhrase(handOver.phrase, WORDS))

		expect(verifyOperation(handOver.php_signed, didKey)).toBe(true)
		expect(verifyOperation(handOver.php_signed, fixture.phrases[0].did_key)).toBe(false)
		expect(verifyOperation({ ...handOver.php_signed, prev: null }, didKey)).toBe(false)
	})

	it('signs the operation PHP verifies: deterministic, low-S, the same bytes', () => {
		const result = recover({ secret: keyFromPhrase(handOver.phrase, WORDS), last: handOver.last, credentials: handOver.credentials })

		expect(result.prev).toBe(handOver.last_cid)
		expect(verifyOperation(result.operation, result.didKey)).toBe(true)
		const { sig, ...unsigned } = result.operation // eslint-disable-line no-unused-vars
		expect(toHex(dagCbor(unsigned))).toBe(handOver.unsigned_cbor_hex)
		const rekeyed = rekey({ secret: keyFromPhrase(handOver.phrase, WORDS), last: handOver.last, signingSecret: keyFromPhrase(fixture.phrases[3].phrase, WORDS) })
		expect(verifyOperation(rekeyed.operation, rekeyed.didKey)).toBe(true)
		expect(rekeyed.operation.verificationMethods.atproto).toBe(fixture.phrases[3].did_key)
		expect(rotationKeysOf(rekeyed.operation)[0]).toBe(rekeyed.didKey)
		const token = serviceAuthToken({
			signingSecret: keyFromPhrase(fixture.phrases[3].phrase, WORDS),
			did: handOver.did,
			audience: 'did:web:pds.example',
			method: 'com.atproto.server.createAccount',
			now: 1760000000,
			jti: '0123456789abcdef',
		})
		const written = {
			js_signed: result.operation,
			js_cid: result.cid,
			js_rekey: { operation: rekeyed.operation, cid: rekeyed.cid },
			js_service_auth: { token, now: 1760000000, audience: 'did:web:pds.example' },
		}
		if (process.env.SOCIAL_UPDATE_FIXTURES) {
			writeFileSync(FIXTURE, JSON.stringify({ ...fixture, operation: { ...handOver, ...written } }, null, 4) + '\n')
			return
		}
		expect(written).toEqual({
			js_signed: handOver.js_signed,
			js_cid: handOver.js_cid,
			js_rekey: handOver.js_rekey,
			js_service_auth: handOver.js_service_auth,
		})
	})
})

describe('what the recovery tool refuses', () => {
	const secret = keyFromPhrase(handOver.phrase, WORDS)
	const code = (run) => {
		try {
			run()
		} catch (error) {
			return error instanceof RecoveryError ? error.code : error
		}
		return null
	}

	it('words that are not a phrase', () => {
		expect(code(() => keyFromPhrase('abandon abandon', WORDS))).toBe('not-twelve-words')
		expect(code(() => keyFromPhrase('abandon '.repeat(11) + 'notaword', WORDS))).toBe('unknown-word')
		expect(code(() => keyFromPhrase('abandon '.repeat(12), WORDS))).toBe('phrase-does-not-check')
		expect(code(() => privateKeyFrom('z' + '1'.repeat(40), WORDS))).toBe('not-a-key')
	})

	it('a key the DID does not list, a tombstoned DID, and credentials it cannot use', () => {
		const other = keyFromPhrase(fixture.phrases[0].phrase, WORDS)
		expect(code(() => recover({ secret: other, last: handOver.last, credentials: handOver.credentials }))).toBe('key-not-listed')
		expect(code(() => recover({ secret, last: { type: 'plc_tombstone', prev: 'b' }, credentials: handOver.credentials }))).toBe('did-tombstoned')
		expect(code(() => recover({ secret, last: handOver.last, credentials: { ...handOver.credentials, services: {} } }))).toBe('credentials-unusable')
	})

	it('keeps the person first and at most five keys', () => {
		const { didKey } = keyForms(secret)
		const many = { ...handOver.credentials, rotationKeys: ['did:key:z1', 'did:key:z2', didKey, 'did:key:z3', 'did:key:z4', 'did:key:z5'] }

		expect(rotationKeysOf(buildOperation(many, didKey, 'b'))).toEqual([didKey, 'did:key:z1', 'did:key:z2', 'did:key:z3', 'did:key:z4'])
	})
})
