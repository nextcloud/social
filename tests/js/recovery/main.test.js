/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { start } from '../../../src/recovery/main.js'

/*
 * The recovery page as the person uses it, on the markup and strings
 * RecoveryToolPage writes (read from its source, so the two cannot drift
 * apart) and the cross-language fixtures: what it signs is what PHP
 * verifies, and nothing goes out but the requests a button asks for.
 */
const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8')
const PAGE = read('lib/Atproto/Service/RecoveryToolPage.php')
const WORDS = read('resources/atproto/bip39-english.txt').trim().split(/\r?\n/)
const fixture = JSON.parse(read('tests/Atproto/Identity/fixtures/recovery-tool.json'))
const { operation: handOver } = fixture
/**
 * @param {string} text
 * @return {string}
 */
function unquote(text) {
	return text.replace(/\\'/g, '\'')
}

/** @return {object} the page's strings, by code, in English */
function strings() {
	return Object.fromEntries([...PAGE.matchAll(/'([a-z-]+)' => \$l->t\('((?:[^'\\]|\\.)*)'\)/g)].map(([, code, text]) => [code, unquote(text)]))
}

function mountPage() {
	const main = PAGE.slice(PAGE.indexOf('<main>'), PAGE.indexOf('</main>') + '</main>'.length)
		.replace(/' \. \$e\(\$l->t\('((?:[^'\\]|\\.)*)'\)\) \. '/g, (match, text) => unquote(text))
		.replace(/' \. \$e\(\$directory\) \. '/g, 'https://plc.directory')
	document.body.innerHTML = main + '<script type="application/json" id="recovery-data"></script>'
	document.getElementById('recovery-data').textContent = JSON.stringify({ words: WORDS, strings: strings() })
	start(document)
}

/**
 * @param {string} id
 * @return {any}
 */
function $(id) {
	return document.getElementById(id)
}

/**
 * @param {string} id
 * @param {string} value
 */
function type(id, value) {
	$(id).value = value
	$(id).dispatchEvent(new Event('input'))
}

/**
 * @param {object|string} body
 * @param {number} status
 * @return {Promise<Response>}
 */
function answer(body, status = 200) {
	return Promise.resolve(new Response(typeof body === 'string' ? body : JSON.stringify(body), { status }))
}

/** @return {Promise<void>} once what is pending has run */
function flush() {
	return new Promise((done) => setTimeout(done, 0))
}

describe('the recovery page', () => {
	let fetch

	beforeEach(() => {
		fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		mountPage()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('shows the public half of the key the words stand for, or what is wrong with them', () => {
		type('key', fixture.phrases[0].phrase)
		expect($('key-result').textContent).toBe('Your key\'s public half: ' + fixture.phrases[0].did_key)

		type('key', 'abandon '.repeat(12))
		expect($('key-result').textContent).toBe('The words do not check: one of them is wrong or in the wrong place.')
		expect($('key-result').classList.contains('error')).toBe(true)
	})

	it('reads the latest operation from the directory and says whether it lists the key', async () => {
		fetch.mockReturnValue(answer(handOver.last))
		type('key', handOver.phrase)
		type('did', handOver.did)

		$('fetch-last').click()
		await flush()

		expect(fetch).toHaveBeenCalledWith('https://plc.directory/' + encodeURIComponent(handOver.did) + '/log/last', {})
		expect(JSON.parse($('last').value)).toEqual(handOver.last)
		expect($('last-result').textContent).toMatch(/^Your key is one of its rotation keys/)
	})

	it('signs the hand-over PHP verifies and sends only that to the directory', async () => {
		type('key', handOver.phrase)
		type('did', handOver.did)
		type('last', JSON.stringify(handOver.last))
		$('credentials').value = JSON.stringify(handOver.credentials)

		$('sign').click()
		expect(JSON.parse($('signed').value)).toEqual(handOver.js_signed)
		expect(fetch).not.toHaveBeenCalled()

		fetch.mockReturnValue(answer(''))
		$('submit').click()
		await flush()

		expect(fetch).toHaveBeenCalledTimes(1)
		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe('https://plc.directory/' + encodeURIComponent(handOver.did))
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual(handOver.js_signed)
		expect($('submit-result').textContent).toBe('The PLC directory took the operation (' + handOver.js_cid + ').')
	})

	it('gives the identifier a signing key of the person\'s own, and creates the account with it', async () => {
		type('key', handOver.phrase)
		type('did', handOver.did)
		type('last', JSON.stringify(handOver.last))
		$('signing-key').value = fixture.phrases[3].hex

		$('make-signing').click()
		expect(JSON.parse($('signing-op').value)).toEqual(handOver.js_rekey.operation)
		expect($('signing-key').value).toBe(fixture.phrases[3].multibase)

		fetch.mockReturnValue(answer({ accessJwt: 'access-token', did: handOver.did }))
		$('pds').value = 'https://pds.example/'
		$('pds-did').value = 'did:web:pds.example'
		$('handle').value = 'alice.pds.example'
		$('email').value = 'alice@example.org'
		$('password').value = 'secret'
		$('create-account').click()
		await flush()

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe('https://pds.example/xrpc/com.atproto.server.createAccount')
		expect(JSON.parse(options.body)).toEqual({ did: handOver.did, handle: 'alice.pds.example', email: 'alice@example.org', password: 'secret' })
		const claims = JSON.parse(atob(options.headers.Authorization.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')))
		expect([claims.iss, claims.aud, claims.lxm]).toEqual([handOver.did, 'did:web:pds.example', 'com.atproto.server.createAccount'])
		expect($('token').value).toBe('access-token')
	})

	it('asks the new server with its token, and says what it answered when it refuses', async () => {
		fetch.mockReturnValue(answer({ error: 'AuthRequired', message: 'Token is expired' }, 401))
		$('pds').value = 'https://pds.example'
		$('token').value = 'old'

		$('fetch-credentials').click()
		await flush()

		expect(fetch.mock.calls[0][1].headers).toEqual({ Authorization: 'Bearer old' })
		expect($('sign-result').textContent).toBe('https://pds.example/xrpc/com.atproto.identity.getRecommendedDidCredentials answered 401: Token is expired')
	})

	it('refuses a key the identifier does not list', () => {
		type('key', fixture.phrases[0].phrase)
		type('last', JSON.stringify(handOver.last))
		$('credentials').value = JSON.stringify(handOver.credentials)

		$('sign').click()

		expect($('sign-result').textContent).toBe('Your key is not one of the rotation keys of this identifier, so it cannot change it.')
		expect($('signed').value).toBe('')
	})
})
