/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect } from '@playwright/test'

export const USER = process.env.E2E_USER ?? 'admin'
export const PASSWORD = process.env.E2E_PASSWORD ?? 'admin'

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
 * Opens the app and waits for its sidebar, closing the first-run
 * introduction if this account has never been here before.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path inside the app, e.g. '/discover'
 */
export async function openApp(page, path = '/') {
	await page.goto(APP + path)
	// the first paint of the app on a cold built-in server takes a while
	await expect(page.locator('.app-navigation').first()).toBeVisible({ timeout: 45_000 })
	const skip = page.locator('.first-run__skip')
	if (await skip.isVisible().catch(() => false)) {
		await skip.click()
	}
}

/** The sidebar entry with this name. */
export function navEntry(page, name) {
	return page.locator('.app-navigation-entry').filter({ hasText: name }).first()
}
