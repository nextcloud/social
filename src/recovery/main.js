/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The recovery page: the person's key, their DID, the new server, and the
 * two PLC operations that move the DID there, each sent only when they
 * press for it. The page carries its own strings and word list, so it
 * runs from a saved file with this server gone.
 */

import {
	cidOf,
	keyForms,
	newSigningKey,
	privateKeyFrom,
	recover,
	RecoveryError,
	rekey,
	rotationKeysOf,
	serviceAuthToken,
	toHex,
} from './plc.js'

/**
 * @param {Document} doc the page
 */
export function start(doc) {
	const data = JSON.parse(doc.getElementById('recovery-data')?.textContent ?? '{}')
	const strings = data.strings ?? {}
	const words = data.words ?? []
	/**
	 * @param {string} id the element's id
	 * @return {HTMLInputElement} the page's element: an input, a text area, a button or a paragraph, all used as an input is
	 */
	const $ = (id) => /** @type {HTMLInputElement} */ (doc.getElementById(id))

	/** @type {{secret: Uint8Array|null}} */
	const state = { secret: null }

	/** @return {Uint8Array|null} the signing key of the person's own, as made here or pasted */
	const signingSecret = () => ($('signing-key').value.trim() === '' ? null : privateKeyFrom($('signing-key').value, []))

	const say = (code, values = {}) => (strings[code] ?? code).replace(/\{(\w+)\}/g, (match, name) => values[name] ?? match)

	const show = (id, text, failed = false) => {
		const element = $(id)
		element.textContent = text
		element.classList.toggle('error', failed)
	}

	const failure = (id, error) => {
		show(id, error instanceof RecoveryError ? say(error.code) : String(error?.message ?? error), true)
	}

	const json = (id) => {
		try {
			return JSON.parse($(id).value)
		} catch {
			throw new RecoveryError('not-json')
		}
	}

	const did = () => {
		const value = $('did').value.trim()
		if (!/^did:plc:[a-z2-7]{24}$/.test(value)) {
			throw new RecoveryError('no-did')
		}
		return value
	}

	const server = () => $('pds').value.trim().replace(/\/+$/, '')

	const call = async (url, options = {}) => {
		let answer
		try {
			answer = await fetch(url, options)
		} catch (error) {
			throw new Error(say('network', { url, reason: String(error?.message ?? error) }), { cause: error })
		}
		const text = await answer.text()
		if (!answer.ok) {
			let reason = text.slice(0, 300)
			try {
				reason = JSON.parse(text).message ?? reason
			} catch {
				// not JSON: the start of the text says it
			}
			throw new Error(say('refused', { url, status: String(answer.status), reason }))
		}
		try {
			return text === '' ? {} : JSON.parse(text)
		} catch {
			return {}
		}
	}

	const bearer = () => ({ Authorization: 'Bearer ' + $('token').value.trim() })

	const key = () => {
		if (state.secret === null) {
			throw new RecoveryError('no-key')
		}
		return state.secret
	}

	const showOperation = (id, result) => {
		$(id).value = JSON.stringify(result.operation, null, 2)
	}

	const submit = async (textarea, resultId) => {
		try {
			const operation = json(textarea)
			await call($('directory').value.trim().replace(/\/+$/, '') + '/' + encodeURIComponent(did()), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(operation),
			})
			$('last').value = JSON.stringify(operation, null, 2)
			show(resultId, say('sent', { cid: cidOf(operation) }))
		} catch (error) {
			failure(resultId, error)
		}
	}

	$('key').addEventListener('input', () => {
		state.secret = null
		if ($('key').value.trim() === '') {
			show('key-result', '')
			return
		}
		try {
			state.secret = privateKeyFrom($('key').value, words)
			show('key-result', say('key-ok', { key: keyForms(state.secret).didKey }))
		} catch (error) {
			failure('key-result', error)
		}
	})

	$('fetch-last').addEventListener('click', async () => {
		try {
			const last = await call($('directory').value.trim().replace(/\/+$/, '') + '/' + encodeURIComponent(did()) + '/log/last')
			$('last').value = JSON.stringify(last, null, 2)
			$('last').dispatchEvent(new Event('input'))
		} catch (error) {
			failure('last-result', error)
		}
	})

	$('last').addEventListener('input', () => {
		try {
			const listed = rotationKeysOf(json('last'))
			const mine = state.secret !== null && listed.includes(keyForms(state.secret).didKey)
			show('last-result', say(mine ? 'last-lists-key' : 'last-keys', { keys: listed.join(', ') }), state.secret !== null && !mine)
		} catch (error) {
			failure('last-result', error)
		}
	})

	$('make-signing').addEventListener('click', () => {
		try {
			const secret = signingSecret() ?? newSigningKey()
			const result = rekey({ secret: key(), last: json('last'), signingSecret: secret })
			$('signing-key').value = keyForms(secret).multibase
			showOperation('signing-op', result)
			show('signing-result', say('signing-made'))
		} catch (error) {
			failure('signing-result', error)
		}
	})

	$('send-signing').addEventListener('click', () => submit('signing-op', 'send-signing-result'))

	$('describe').addEventListener('click', async () => {
		try {
			const described = await call(server() + '/xrpc/com.atproto.server.describeServer')
			$('pds-did').value = described.did ?? ''
			const domains = Array.isArray(described.availableUserDomains) ? described.availableUserDomains.join(', ') : ''
			show('pds-result', say('server-ok', { did: described.did ?? '', domains }))
		} catch (error) {
			failure('pds-result', error)
		}
	})

	$('create-account').addEventListener('click', async () => {
		try {
			const secret = signingSecret()
			if (secret === null) {
				throw new RecoveryError('no-signing-key')
			}
			const token = serviceAuthToken({
				signingSecret: secret,
				did: did(),
				audience: $('pds-did').value.trim(),
				method: 'com.atproto.server.createAccount',
				now: Math.floor(Date.now() / 1000),
				jti: toHex(newSigningKey().slice(0, 16)),
			})
			const body = { did: did(), handle: $('handle').value.trim(), email: $('email').value.trim(), password: $('password').value }
			if ($('invite').value.trim() !== '') {
				body.inviteCode = $('invite').value.trim()
			}
			const created = await call(server() + '/xrpc/com.atproto.server.createAccount', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
				body: JSON.stringify(body),
			})
			$('token').value = created.accessJwt ?? ''
			show('account-result', say('account-created'))
		} catch (error) {
			failure('account-result', error)
		}
	})

	$('import').addEventListener('click', async () => {
		try {
			const car = $('repo').files?.[0]
			if (car) {
				await call(server() + '/xrpc/com.atproto.repo.importRepo', {
					method: 'POST',
					headers: { 'Content-Type': 'application/vnd.ipld.car', ...bearer() },
					body: car,
				})
			}
			let uploaded = 0
			for (const blob of Array.from($('blobs').files ?? [])) {
				await call(server() + '/xrpc/com.atproto.repo.uploadBlob', {
					method: 'POST',
					headers: { 'Content-Type': blob.type || 'application/octet-stream', ...bearer() },
					body: blob,
				})
				uploaded++
				show('import-result', say('blobs-uploaded', { count: String(uploaded) }))
			}
			show('import-result', say('imported', { count: String(uploaded) }))
		} catch (error) {
			failure('import-result', error)
		}
	})

	$('fetch-credentials').addEventListener('click', async () => {
		try {
			const credentials = await call(server() + '/xrpc/com.atproto.identity.getRecommendedDidCredentials', { headers: bearer() })
			$('credentials').value = JSON.stringify(credentials, null, 2)
		} catch (error) {
			failure('sign-result', error)
		}
	})

	$('sign').addEventListener('click', () => {
		try {
			const result = recover({ secret: key(), last: json('last'), credentials: json('credentials') })
			showOperation('signed', result)
			show('sign-result', say('signed-ok', { keys: rotationKeysOf(result.operation).join(', ') }))
		} catch (error) {
			failure('sign-result', error)
		}
	})

	$('copy').addEventListener('click', async () => {
		try {
			await navigator.clipboard.writeText($('signed').value)
			show('submit-result', say('copied'))
		} catch {
			$('signed').select()
		}
	})

	$('submit').addEventListener('click', () => submit('signed', 'submit-result'))

	$('activate').addEventListener('click', async () => {
		try {
			await call(server() + '/xrpc/com.atproto.server.activateAccount', { method: 'POST', headers: bearer() })
			show('activate-result', say('activated'))
		} catch (error) {
			failure('activate-result', error)
		}
	})
}
