/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { login, openApp } from './helpers.mjs'

/**
 * The two things a Mastodon user reaches for in Settings and in a post's menu:
 * filtering a word, and correcting a post by deleting it and writing it again.
 *
 * Both are worth a browser test rather than a unit test because both are a
 * page talking to a route: the unit suite can prove the component asks, and
 * only this can prove the server answers.
 */
test.describe('filters, and writing a post again', () => {
	test.beforeEach(async ({ page }) => {
		await login(page)
	})

	test('Settings can add and remove a filtered word', async ({ page }) => {
		await openApp(page, '/settings')

		const section = page.locator('#filters')
		await expect(section).toBeVisible()
		await expect(section.getByRole('heading', { name: 'Filtered words' })).toBeVisible()

		const word = `spoilers-${Date.now()}`
		await section.getByRole('button', { name: 'Add a filter' }).click()
		await section.getByLabel('Name of the filter').fill(word)
		await section.getByLabel('Word or phrase').first().fill(word)
		// a new filter applies nowhere until somewhere is ticked, and the form
		// says so rather than letting it be saved
		await section.getByText('My Feed', { exact: true }).click()
		await section.getByRole('button', { name: 'Create filter' }).click()

		const row = section.locator('.filters__item', { hasText: word })
		await expect(row).toBeVisible()

		// and take it away again, so the box is left as it was found
		await row.getByRole('button', { name: `More actions for ${word}` }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await page.getByRole('button', { name: 'Delete', exact: true }).last().click()
		await expect(section.locator('.filters__item', { hasText: word })).toHaveCount(0)
	})

	test('a post of your own offers Delete & re-draft', async ({ page }) => {
		await openApp(page)
		const text = `Redraft check ${Date.now()}`

		await page.locator('.navigation__compose').click()
		const composer = page.locator('.modal-composer')
		await composer.locator('.message').click()
		await page.keyboard.type(text)
		await composer.getByRole('button', { name: /^Post/ }).click()

		const post = page.locator('.timeline-entry', { hasText: text }).first()
		await expect(post).toBeVisible({ timeout: 20_000 })
		await post.hover()
		await post.locator('button.action-item__menutoggle').first().click()
		await expect(page.getByRole('menuitem', { name: 'Delete & re-draft' })).toBeVisible()
	})
})
