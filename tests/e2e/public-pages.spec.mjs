/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { APP, PASSWORD, USER, apiAs, ensureAccount, postStatus } from './helpers.mjs'

/**
 * What somebody without an account sees when a link to a profile or a post
 * is shared with them: the public page, without a login — and nothing that
 * was not written for everyone. Nobody signs in in this file.
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

	test('a followers-only post is not found by somebody signed out', async ({ page }) => {
		const response = await page.goto(`${APP}/@${USER}/${followersOnly.id}`)
		expect(response?.status()).toBe(404)
		await expect(page).not.toHaveURL(/\/login/)

		await expect(page.getByText('Post not found').first()).toBeVisible()
		await expect(page.getByText(followersOnly.content.replace(/<[^>]+>/g, ''))).toHaveCount(0)
	})
})
