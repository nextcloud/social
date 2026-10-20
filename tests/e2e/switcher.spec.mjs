/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { PASSWORD, USER, apiAs, ensureAccount, login, openApp } from './helpers.mjs'

/**
 * The notification filter in the reading column.
 *
 * Seven translated labels do not fit the 616px column, and a wide window does
 * not give the column more room. The control keeps every option at its natural
 * width and scrolls, rather than shrinking the options until their words run
 * into one another. German is where the labels are longest, which is where the
 * defect was seen.
 */
test.describe('the notification filter in the reading column', () => {
	/** @type {import('@playwright/test').APIRequestContext} */
	let api
	/** the language the account had before this test, put back afterwards */
	let language

	test.beforeEach(async ({ page }) => {
		api = await apiAs(USER, PASSWORD)
		await ensureAccount(api, USER)

		const me = await api.get('ocs/v2.php/cloud/user?format=json')
		language = (await me.json()).ocs.data.language || 'en'
		const changed = await api.put(`ocs/v2.php/cloud/users/${USER}`, { form: { key: 'language', value: 'de' } })
		expect(changed.ok(), 'the account could be read in German').toBe(true)

		await login(page)
		await openApp(page, '/timeline/notifications')
	})

	test.afterEach(async () => {
		if (api !== undefined && language !== undefined) {
			await api.put(`ocs/v2.php/cloud/users/${USER}`, { form: { key: 'language', value: language } })
		}
		await api?.dispose()
	})

	test('keeps long translated labels apart and reachable in a narrow column', async ({ page }) => {
		const switcher = page.locator('.notifications-filter')
		await expect(switcher).toBeVisible()

		// the window is wide; the column it draws the control in is not
		const viewport = page.viewportSize()
		const column = await switcher.evaluate((element) => element.closest('.social__wrapper').getBoundingClientRect().width)
		expect(viewport.width).toBeGreaterThan(column + 200)

		const options = switcher.locator('.switcher__option')
		await expect(options).toHaveCount(7)
		await expect(switcher.getByText('Weiterverbreitete Beiträge')).toBeVisible()

		// every icon and label stays inside the button it belongs to, and each
		// button starts after the one before it ends
		const boxes = await options.evaluateAll((elements) => elements.map((option) => {
			const button = option.getBoundingClientRect()
			const icon = option.querySelector('.switcher__icon').getBoundingClientRect()
			const label = option.querySelector('.switcher__label').getBoundingClientRect()
			return { left: button.left, right: button.right, icon: { left: icon.left, right: icon.right }, label: { left: label.left, right: label.right } }
		}))
		boxes.forEach((box, index) => {
			expect(box.icon.left, 'the icon is inside its option').toBeGreaterThanOrEqual(box.left - 0.5)
			expect(box.icon.right, 'the icon is inside its option').toBeLessThanOrEqual(box.right + 0.5)
			expect(box.label.left, 'the label is inside its option').toBeGreaterThanOrEqual(box.left - 0.5)
			expect(box.label.right, 'the label is inside its option').toBeLessThanOrEqual(box.right + 0.5)
			if (index > 0) {
				expect(box.left, 'options do not overlap').toBeGreaterThanOrEqual(boxes[index - 1].right - 0.5)
			}
		})

		// the page itself does not scroll sideways; the control does
		expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
		const scroll = await switcher.evaluate((element) => ({ client: element.clientWidth, content: element.scrollWidth }))
		expect(scroll.content, 'the labels need more room than the column').toBeGreaterThan(scroll.client)

		// every option is reachable: the last one can be brought into view
		await options.last().evaluate((element) => element.scrollIntoView({ block: 'nearest', inline: 'nearest' }))
		await expect(options.last()).toBeInViewport()
		await expect(switcher).toHaveClass(/switcher--more-left/)
	})
})
