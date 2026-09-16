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

	/**
	 * The box opens on a click and stays open for as long as it holds
	 * anything — so before the close button, a reader who had typed a word and
	 * changed their mind had to delete the word to get their feed back. Closing
	 * it keeps the word: what was asked for is the feed, not a blank page.
	 */
	test('the composer in the feed closes without losing what was written', async ({ page }) => {
		await openApp(page)

		const composer = page.locator('.social__wrapper .new-post').first()
		await composer.locator('.message').click()
		await page.keyboard.type('half a thought')
		await expect(composer).not.toHaveClass(/new-post--collapsed/)

		await composer.getByRole('button', { name: 'Close the composer' }).click()
		await expect(composer).toHaveClass(/new-post--collapsed/)
		await expect(composer.locator('.message')).toHaveText('half a thought')

		// and it is all still there when the box is asked for again
		await composer.locator('.message').click()
		await expect(composer).not.toHaveClass(/new-post--collapsed/)
		await expect(composer.locator('.message')).toHaveText('half a thought')

		// down to the next visit to the page, which is what the draft is for
		await openApp(page)
		await expect(page.locator('.social__wrapper .new-post .message').first())
			.toHaveText('half a thought')

		// leave the feed as it was found
		await page.locator('.social__wrapper .new-post .message').first().click()
		await page.keyboard.press('ControlOrMeta+A')
		await page.keyboard.press('Backspace')
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
		// the lists live inside Explore now, beside the hashtags the account
		// follows, so the entry has to be there and open before the list is
		const explore = page.locator('.navigation__explore').first()
		await expect(explore).toBeVisible()

		const list = page.locator('.navigation__list').filter({ hasText: group }).first()
		if (!await list.isVisible()) {
			// it is open by default, but a previous run may have folded it
			await explore.getByRole('button').first().click()
		}
		await expect(list).toBeVisible()

		await list.click()
		await expect(page).toHaveURL(/timeline\/list\/\d+/)
		await expect(page.locator('h1.timeline-heading')).toHaveText(group)
	})
})
