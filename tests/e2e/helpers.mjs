/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request } from '@playwright/test'

export const USER = process.env.E2E_USER ?? 'admin'
export const PASSWORD = process.env.E2E_PASSWORD ?? 'admin'

/**
 * The account on the other end of the flows that need two people: it
 * follows, is followed, likes, mentions and is written to. The
 * administrator, because every instance has one and its password is the one
 * the workflow installs with.
 */
export const OTHER_USER = process.env.E2E_ADMIN_USER ?? 'admin'
export const OTHER_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'admin'

/**
 * A bystander: an account that is neither end of a conversation, made by the
 * administrator through Nextcloud's provisioning API when it is missing.
 */
export const BYSTANDER = process.env.E2E_BYSTANDER ?? 'carol'
export const BYSTANDER_PASSWORD = process.env.E2E_BYSTANDER_PASSWORD ?? 'Browser-Test-2026!'

/** With a trailing slash, as playwright.config.mjs explains. */
export const BASE_URL = (process.env.E2E_BASE_URL ?? 'http://localhost:8080').replace(/\/*$/, '/')

/** Where the app lives, whether or not the instance has pretty URLs. */
export const APP = 'index.php/apps/social'

/**
 * Signs in through Nextcloud's own login form, the way a person does.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} user
 * @param {string} password
 */
export async function login(page, user = USER, password = PASSWORD) {
	await page.goto('index.php/login')
	await page.locator('#user').fill(user)
	await page.locator('#password').fill(password)
	await page.locator('form[name="login"] button[type="submit"], #submit-form, button[type="submit"]').first().click()
	// the dashboard, or wherever the instance sends a fresh session
	await expect(page).not.toHaveURL(/\/login/)
}

/**
 * Opens the app and waits for its sidebar, answering the setup screen and
 * closing the first-run introduction if this account has never been here
 * before.
 *
 * The setup screen comes first and there is no app behind it: an account is
 * made when somebody asks for one, so a Nextcloud user who has never opened
 * Aloha Social has no actor and no sidebar. Taking the handle it offers is what a
 * person does, and doing it here means the run exercises that path rather
 * than working around it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path inside the app, e.g. '/discover'
 */
export async function openApp(page, path = '/') {
	await page.goto(APP + path)

	const create = page.getByRole('button', { name: /^Create @/ })
	// the first paint of the app on a cold built-in server takes a while, and
	// this is the first of them: either screen appearing ends the wait
	await expect(create.or(page.locator('.app-navigation').first())).toBeVisible({ timeout: 45_000 })
	if (await create.isVisible().catch(() => false)) {
		await create.click()
		// the page reloads into the app once the account exists
		await expect(page.locator('.app-navigation').first()).toBeVisible({ timeout: 45_000 })
		if (path !== '/') {
			await page.goto(APP + path)
			await expect(page.locator('.app-navigation').first()).toBeVisible({ timeout: 45_000 })
		}
	}

	const skip = page.locator('.first-run__skip')
	if (await skip.isVisible().catch(() => false)) {
		await skip.click()
	}
}

/** The sidebar entry with this name. */
export function navEntry(page, name) {
	return page.locator('.app-navigation-entry').filter({ hasText: name }).first()
}

/**
 * The app's API as one account, for the seeding a test needs before it can
 * show anything: posts to act on, a follow to build a list from, a like that
 * makes a notification.
 *
 * Basic auth on every request, with the header that tells Nextcloud this is
 * an API call: a request without a session cookie needs no CSRF token, and
 * the app's routes take the Nextcloud user the same way the page does.
 *
 * @param {string} user
 * @param {string} password
 * @return {Promise<import('@playwright/test').APIRequestContext>}
 */
export async function apiAs(user, password) {
	return request.newContext({
		baseURL: BASE_URL,
		httpCredentials: { username: user, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
}

/**
 * The account behind this API context, made the way the setup screen makes
 * one if the Nextcloud user has never opened the app.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {string} user the Nextcloud user id, taken as the handle
 * @return {Promise<object>} the Mastodon Account
 */
export async function ensureAccount(api, user) {
	const me = await api.get(`${APP}/api/v1/accounts/verify_credentials`)
	if (me.ok()) {
		return me.json()
	}

	const created = await api.post(`${APP}/api/v1/account/create`, { data: { username: user } })
	expect(created.ok(), `an account for ${user} could be made`).toBe(true)

	return (await created.json()).result.account
}

/**
 * A Nextcloud user, created by the administrator if it does not exist yet.
 *
 * @param {string} user
 * @param {string} password
 * @return {Promise<boolean>} whether the user exists now
 */
export async function ensureUser(user, password) {
	const admin = await apiAs(OTHER_USER, OTHER_PASSWORD)
	try {
		const response = await admin.post('ocs/v2.php/cloud/users?format=json', {
			form: { userid: user, password },
		})
		const body = await response.json().catch(() => null)
		// 102 is the provisioning API's "already exists"
		return response.ok() || body?.ocs?.meta?.statuscode === 102
	} finally {
		await admin.dispose()
	}
}

/**
 * A post written over the API, as the client API answers it.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {object} status the body of `POST /api/v1/statuses`
 * @return {Promise<object>} the Mastodon Status
 */
export async function postStatus(api, status) {
	const response = await api.post(`${APP}/api/v1/statuses`, { data: { visibility: 'public', ...status } })
	expect(response.ok(), `the post was accepted: ${await response.text()}`).toBe(true)

	return response.json()
}
