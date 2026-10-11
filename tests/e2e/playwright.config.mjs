/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'
import { BASE_URL } from './helpers.mjs'

/**
 * The other engines a reader may be using, asked for by name in
 * E2E_BROWSERS (`webkit`, `firefox`, or `all`), each running the smoke spec
 * only: what differs between engines is drawing and DOM APIs, and Safari
 * has already had a bug of its own (no canvas `ctx.filter`). Chromium runs
 * everything, with or without them.
 */
const EXTRA_BROWSERS = {
	webkit: devices['Desktop Safari'],
	firefox: devices['Desktop Firefox'],
}
const asked = (process.env.E2E_BROWSERS ?? '').split(',').map((name) => name.trim()).filter(Boolean)
const extraProjects = Object.entries(EXTRA_BROWSERS)
	.filter(([name]) => asked.includes(name) || asked.includes('all'))
	.map(([name, device]) => ({ name, use: { ...device }, testMatch: 'smoke.spec.mjs' }))

/**
 * The browser tests drive a real Nextcloud with this app installed. Nothing
 * here is mocked: the server, the database and the built bundle in js/ are
 * what a user gets. Point them at any instance with the three variables
 * below; `.github/workflows/e2e.yml` sets up a throwaway one.
 */
export default defineConfig({
	testDir: '.',
	testMatch: '**/*.spec.mjs',
	// one worker: the tests share one account and one timeline, and a post
	// written by one test is read by the next
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : 'list',
	outputDir: 'test-results',
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
		...extraProjects,
	],
	use: {
		// with a trailing slash, and every path in the tests without a leading
		// one: an instance under a sub-path (`/nextcloud`) keeps it that way,
		// where an absolute path would resolve against the host and lose it
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		// a development instance answers slowly on first paint
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
	},
})
