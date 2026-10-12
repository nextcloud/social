/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, postStatus } from './helpers.mjs'

/**
 * What somebody without an account sees when a link to a profile or a post
 * is shared with them: the public page, without a login — and nothing that
 * was not written for everyone. And what the reader's own pages do with
 * somebody signed out: they are sent to the login. Nobody is signed in when
 * a test here starts.
 */
test.describe('public pages, signed out', () => {
	/** @type {object} */
	let shared
	/** @type {object} */
	let followersOnly

	test.beforeAll(async () => {
		const api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)
		const stamp = Date.now()
		shared = await postStatus(api, { status: `Written for everyone ${stamp}` })
		followersOnly = await postStatus(api, { status: `Only for my followers ${stamp}`, visibility: 'private' })
		await api.dispose()
	})

	test('a profile is readable without signing in, and shows only public posts', async ({ page }) => {
		const response = await page.goto(`${APP}/@${USER}`)
		expect(response?.status()).toBe(200)
		await expect(page).not.toHaveURL(/\/login/)

		await expect(page.getByText(shared.content.replace(/<[^>]+>/g, '')).first(), 'the public post is on the page')
			.toBeVisible({ timeout: 45_000 })
		await expect(page.getByText(followersOnly.content.replace(/<[^>]+>/g, '')), 'the followers-only one is not')
			.toHaveCount(0)
		// the page is a guest's: nothing to write with
		await expect(page.locator('.navigation__compose')).toHaveCount(0)
	})

	test('a public post opens on its own page without signing in', async ({ page }) => {
		const response = await page.goto(`${APP}/@${USER}/${shared.id}`)
		expect(response?.status()).toBe(200)
		await expect(page).not.toHaveURL(/\/login/)

		await expect(page.getByText(shared.content.replace(/<[^>]+>/g, '')).first()).toBeVisible({ timeout: 45_000 })
	})

	/**
	 * The reader's own pages have nothing in them for a visitor: Nextcloud's
	 * login, which knows where to bring them back to.
	 */
	for (const path of ['settings', 'statistics', 'timeline/notifications']) {
		test(`/${path} sends somebody signed out to the login`, async ({ page }) => {
			await page.goto(`${APP}/${path}`)

			await expect(page).toHaveURL(/\/login\?/)
			expect(new URL(page.url()).searchParams.get('redirect_url')).toMatch(new RegExp(`/apps/social/${path}$`))
			await expect(page.locator('#user')).toBeVisible()
		})
	}

	test('signing in from there opens the settings asked for', async ({ page }) => {
		await page.goto(`${APP}/settings`)
		await expect(page).toHaveURL(/\/login\?/)

		await page.locator('#user').fill(USER)
		await page.locator('#password').fill(PASSWORD)
		await page.locator('form[name="login"] button[type="submit"], #submit-form, button[type="submit"]').first().click()

		await expect(page).toHaveURL(/\/apps\/social\/settings$/)
	})

	test('a followers-only post is not found by somebody signed out', async ({ page }) => {
		const response = await page.goto(`${APP}/@${USER}/${followersOnly.id}`)
		expect(response?.status()).toBe(404)
		await expect(page).not.toHaveURL(/\/login/)

		await expect(page.getByText('Post not found').first()).toBeVisible()
		await expect(page.getByText(followersOnly.content.replace(/<[^>]+>/g, ''))).toHaveCount(0)
	})
})
