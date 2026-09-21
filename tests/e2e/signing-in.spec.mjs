/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { APP, login } from './helpers.mjs'

/**
 * A Mastodon client adding an account, all the way through.
 *
 * Every fault this covers was invisible from the server. The registration
 * answered 200 with a body the client could not decode; the consent page's
 * Content Security Policy silently dropped a source expression it could not
 * parse and blocked the hand-off; the discovery document named an issuer that
 * did not match the address it was fetched from. Each of those is a working
 * route, a right status code, and a client that cannot sign in — so no unit
 * test and no integration test could see any of them, and the only place the
 * failure appeared at all was a web server's access log.
 *
 * The flow is the real one: register an application, open the consent page in
 * the browser, press Authorize, follow where it sends the browser, exchange the
 * code for a token, and use the token. Nothing is stubbed.
 */
test.describe('signing in as a Mastodon client would', () => {
	/** What the client asks for, as a real one does. */
	const SCOPES = 'read write follow'

	/**
	 * Registers an application the way a client does, over the same HTTP the
	 * client uses rather than through the page.
	 *
	 * @param {import('@playwright/test').APIRequestContext} request
	 * @param {string} redirectUri where the code should be sent
	 * @return {Promise<object>} the application as the server describes it
	 */
	async function registerApp(request, redirectUri) {
		const response = await request.post(`${APP}/api/v1/apps`, {
			form: {
				client_name: 'End-to-end probe',
				redirect_uris: redirectUri,
				scopes: SCOPES,
			},
		})
		expect(response.status(), 'the registration answers 200').toBe(200)

		return response.json()
	}

	/**
	 * A client decodes this into a typed struct, so the types are the contract
	 * and not a detail. Mastodon sends every id as a string and an absent
	 * website as null; a JSON number, or `""` where a URL is expected, fails to
	 * decode and the client stops before it ever opens a browser.
	 */
	test('the registration is shaped the way a typed client decodes it', async ({ request }) => {
		const app = await registerApp(request, 'urn:ietf:wg:oauth:2.0:oob')

		expect(typeof app.id, 'id is a string').toBe('string')
		expect(typeof app.client_id).toBe('string')
		expect(typeof app.client_secret).toBe('string')
		expect(app.redirect_uri).toBe('urn:ietf:wg:oauth:2.0:oob')
		// null, never '': a client declares this as an optional URL
		expect(app.website === null || typeof app.website === 'string').toBe(true)
		expect(app.website).not.toBe('')
		// present even though there is no push here, because a client reads it
		// before deciding whether to offer push at all
		expect(app.vapid_key !== undefined).toBe(true)
	})

	/**
	 * The whole round trip. This is the test that would have caught all three
	 * of the faults above at once.
	 */
	test('a client can register, be authorized, and use the token it gets', async ({ page, request }) => {
		await login(page)

		// A redirect back into this app, so the browser can actually follow it
		// here. A real client registers its own scheme; that path is covered by
		// the policy assertions below, which are what a custom scheme breaks.
		const redirectUri = new URL(`${APP}/`, page.url()).toString()
		const app = await registerApp(request, redirectUri)

		const authorize = `${APP}/oauth/authorize`
			+ `?client_id=${encodeURIComponent(app.client_id)}`
			+ `&redirect_uri=${encodeURIComponent(redirectUri)}`
			+ '&response_type=code'
			+ `&scope=${encodeURIComponent(SCOPES)}`
			+ '&state=e2e-state'

		const violations = []
		page.on('console', (message) => {
			if (/Content Security Policy/i.test(message.text())) {
				violations.push(message.text())
			}
		})

		await page.goto(authorize)

		// the consent screen names the application and what it is asking for
		await expect(page.getByText('Authorization required')).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('End-to-end probe')).toBeVisible()

		await page.getByRole('button', { name: 'Authorize' }).click()

		// the browser is sent to the client with a code, and the state it sent
		await page.waitForURL(/[?&]code=/, { timeout: 30_000 })
		const landed = new URL(page.url())
		const code = landed.searchParams.get('code')

		expect(code, 'the redirect carried an authorization code').toBeTruthy()
		expect(landed.searchParams.get('state'), 'and the state the client sent').toBe('e2e-state')
		expect(violations, 'nothing was blocked by the content security policy').toEqual([])

		// the code becomes a token
		const token = await request.post(`${APP}/oauth/token`, {
			form: {
				grant_type: 'authorization_code',
				code,
				client_id: app.client_id,
				client_secret: app.client_secret,
				redirect_uri: redirectUri,
			},
		})
		expect(token.status(), 'the token endpoint answers 200').toBe(200)
		const granted = await token.json()
		expect(typeof granted.access_token).toBe('string')
		expect(granted.token_type).toBe('Bearer')

		// and the token is one the API accepts
		const me = await request.get(`${APP}/api/v1/accounts/verify_credentials`, {
			headers: { Authorization: `Bearer ${granted.access_token}` },
		})
		expect(me.status(), 'the granted token is accepted').toBe(200)
		expect(typeof (await me.json()).acct).toBe('string')
	})

	/**
	 * A source expression is not a URI. `form-action 'self' icecubesapp://` is
	 * not something a browser can parse, so it drops the expression, leaves
	 * `'self'`, and blocks the hand-off exactly as if nothing had been added —
	 * which is the Authorize button doing nothing for every mobile client.
	 */
	test('the consent page lets the form reach a custom scheme', async ({ page, request }) => {
		await login(page)
		const app = await registerApp(request, 'e2eclient://')

		const response = await page.goto(`${APP}/oauth/authorize`
			+ `?client_id=${encodeURIComponent(app.client_id)}`
			+ `&redirect_uri=${encodeURIComponent('e2eclient://')}`
			+ `&response_type=code&scope=${encodeURIComponent(SCOPES)}`)

		const policy = (response.headers()['content-security-policy'] ?? '')
		const formAction = policy.split(';').map((d) => d.trim())
			.find((d) => d.startsWith('form-action')) ?? ''

		expect(formAction, 'the scheme is allowed').toContain('e2eclient:')
		// `e2eclient://` would be dropped by the browser as unparseable
		expect(formAction, 'as a scheme-source, not as a URI').not.toContain('e2eclient://')
	})

	/**
	 * RFC 8414 §3.3: the issuer has to be the URL the document was fetched
	 * from, minus the well-known suffix. A client that follows the spec rejects
	 * a mismatch before it opens a browser, so nothing on the server sees it
	 * fail.
	 */
	test('the discovery document describes the address it was reached at', async ({ request }) => {
		// from the domain root, which is where a client looks for it. Without
		// the web-server rules that put the API there, no client can discover
		// this instance at all and there is nothing here to assert.
		const response = await request.get('/.well-known/oauth-authorization-server')
		test.skip(response.status() === 404, 'the client API is not mapped to the domain root here')
		expect(response.status()).toBe(200)

		const metadata = await response.json()
		const origin = new URL(response.url()).origin

		expect(metadata.issuer.replace(/\/$/, ''), 'RFC 8414 §3.3').toBe(origin)
		expect(metadata.authorization_endpoint).toContain(origin)
		expect(metadata.token_endpoint).toContain(origin)
		expect(metadata.grant_types_supported).toContain('authorization_code')
	})

	/**
	 * A proxy that does not pass the scheme on leaves the app believing every
	 * request arrived over plain HTTP, and every absolute URL it answers with
	 * says so — on a site that is HTTPS. An iOS client refuses those outright.
	 * Only meaningful where the suite itself runs over HTTPS.
	 */
	test('addresses come back in the scheme they were asked over', async ({ request, baseURL }) => {
		test.skip(!String(baseURL).startsWith('https://'), 'only meaningful over HTTPS')

		const instance = await (await request.get(`${APP}/api/v2/instance`)).json()

		expect(JSON.stringify(instance)).not.toContain('"http://')
	})
})
