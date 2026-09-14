/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { login, navEntry, openApp } from './helpers.mjs'

/**
 * What a person does in their first five minutes, against a real server:
 * open the app, read the feed, write a post, look around. The unit and
 * integration suites cannot see a page that stays empty because a bundle
 * did not load or a route returned the wrong shape; this can.
 */
test.describe('Social, in a browser', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('opens with its sidebar and a feed', async ({ page }) => {
		await openApp(page)

		for (const name of ['My Feed', 'Photos', 'Videos', 'Activities', 'Direct messages', 'Discover']) {
			await expect(navEntry(page, name), `${name} is in the sidebar`).toBeVisible()
		}
		await expect(page.locator('.social__timeline')).toBeVisible()
		// the New post button is the one thing on the page that is always there
		await expect(page.locator('.navigation__compose')).toBeVisible()
	})

	test('a post written in the dialog shows up in My Feed', async ({ page }) => {
		await openApp(page)
		const text = `Hello from the browser test ${Date.now()}`

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await expect(composer).toBeVisible()
		await composer.locator('.message').click()
		await page.keyboard.type(text)
		await composer.getByRole('button', { name: /^Post/ }).click()

		// the dialog closes on success, and the post is the newest thing in the feed
		await expect(composer).toBeHidden()
		await expect(page.locator('.social__timeline article').filter({ hasText: text }).first()).toBeVisible()
	})

	test('Discover has its sections', async ({ page }) => {
		await openApp(page, '/discover')

		await expect(page.locator('.discover')).toBeVisible()
		const switcher = page.locator('.discover .switcher')
		await expect(switcher).toBeVisible()
		for (const option of ['People', 'Starter packs']) {
			await expect(switcher.getByRole('radio', { name: option })).toBeVisible()
		}
	})

	test('the timeline switcher moves between the three scopes', async ({ page }) => {
		await openApp(page)
		const switcher = page.locator('.switcher').first()
		await expect(switcher).toBeVisible()

		await switcher.getByRole('radio', { name: /Global/ }).click()
		await expect(page).toHaveURL(/federated/)
		await switcher.getByRole('radio', { name: /Local/ }).click()
		await expect(page).toHaveURL(/timeline\/timeline/)
	})

	test('a Nextcloud group the account is in is a list in the sidebar', async ({ page }) => {
		const group = process.env.E2E_GROUP
		test.skip(!group, 'set E2E_GROUP to the display name of a group this account is in')

		await openApp(page)
		// the caption over the section, then the list itself
		await expect(page.locator('.app-navigation-caption').filter({ hasText: 'Lists' }).first()).toBeVisible()
		const list = page.locator('.navigation__list').filter({ hasText: group }).first()
		await expect(list).toBeVisible()

		await list.click()
		await expect(page).toHaveURL(/timeline\/list\/\d+/)
		await expect(page.locator('h1.timeline-heading')).toHaveText(group)
	})
})
