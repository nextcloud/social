/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
	APP,
	BASE_URL,
	BYSTANDER_PASSWORD,
	IGNORE_HTTPS_ERRORS,
	apiAs,
	ensureAccount,
	ensureUser,
	login,
	openApp,
	postStatus,
} from './helpers.mjs'

/** A reader of this spec's own, so nothing else is in their Continue watching. */
const WATCHER = 'videowatcher'

/**
 * A minute of a test pattern, made with ffmpeg: H.264 where this ffmpeg has
 * it, MPEG-4 Part 2 where it does not. Null without ffmpeg.
 *
 * @return {Buffer|null}
 */
function clip() {
	const dir = mkdtempSync(join(tmpdir(), 'social-e2e-videos-'))
	const file = join(dir, 'clip.mp4')
	const pattern = ['-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x90:rate=10', '-t', '60']
	try {
		for (const codec of [['-c:v', 'libx264', '-pix_fmt', 'yuv420p'], ['-c:v', 'mpeg4']]) {
			const made = spawnSync('ffmpeg', [...pattern, ...codec, '-movflags', '+faststart', '-y', file])
			if (made.status === 0) {
				return readFileSync(file)
			}
		}

		return null
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

/**
 * Removes everything this reader is in the middle of, so each test starts
 * from the same empty shelf.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 */
async function clearWatching(api) {
	const response = await api.get(`${APP}/api/v1/videos/continue?limit=40`)
	expect(response.ok(), 'the continue list can be read').toBe(true)
	for (const status of await response.json()) {
		await api.delete(`${APP}/api/v1/statuses/${status.id}/watched`)
	}
}

/** The shelf the Videos page draws above the grid. */
function continueShelf(page) {
	return page.locator('.video-browse__shelf').filter({ hasText: 'Continue watching' })
}

/**
 * Continue watching, read in a browser: a video watched past ten seconds is
 * offered back on the Videos page, survives a reload and starts where the
 * reader stopped; one barely started or watched to the end is not offered;
 * and a visitor with no session is not asked what they were watching.
 *
 * The video is made here and measured by the server, which needs ffmpeg and
 * ffprobe; where either is missing the test says so rather than failing.
 */
test.describe('Continue watching, in a browser', () => {
	/** @type {object|null} */
	let video = null
	/** @type {import('@playwright/test').APIRequestContext|null} */
	let api = null
	let ready = true

	test.beforeAll(async () => {
		const buffer = clip()
		if (buffer === null) {
			ready = false
			return
		}
		if (!await ensureUser(WATCHER, BYSTANDER_PASSWORD)) {
			ready = false
			return
		}

		api = await apiAs(WATCHER, BYSTANDER_PASSWORD)
		await ensureAccount(api, WATCHER)

		const uploaded = await api.post(`${APP}/api/v2/media`, {
			multipart: { file: { name: 'clip.mp4', mimeType: 'video/mp4', buffer } },
		})
		expect(uploaded.ok(), `a video can be uploaded: ${await uploaded.text()}`).toBe(true)
		const media = await uploaded.json()
		if ((media.meta?.duration ?? 0) <= 0) {
			ready = false
			return
		}

		video = await postStatus(api, {
			status: `A video to come back to ${Date.now()}`,
			media_ids: [media.id],
		})
	})

	test.afterAll(async () => {
		if (api === null) {
			return
		}
		if (video !== null) {
			await api.delete(`${APP}/api/v1/statuses/${video.id}`)
		}
		await api.dispose()
	})

	test.beforeEach(async () => {
		test.skip(!ready, 'needs ffmpeg to make the video and the server\'s ffprobe to measure it')
		await clearWatching(/** @type {import('@playwright/test').APIRequestContext} */ (api))
	})

	/**
	 * @param {import('@playwright/test').Page} page
	 */
	async function openAsWatcher(page) {
		await login(page, WATCHER, BYSTANDER_PASSWORD)
	}

	/**
	 * Plays the video for a moment, moves it to `seconds` and pauses it,
	 * waiting for the report the pause sends.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {number} seconds
	 */
	async function stopAt(page, seconds) {
		await openAsWatcher(page)
		await openApp(page, `/@${WATCHER}/${video.id}`)
		const player = page.locator('.video-player__video')
		await expect(player).toBeVisible()
		await page.locator('.video-player__start').click()
		await expect.poll(() => player.evaluate((element) => element.currentTime)).toBeGreaterThan(1)

		const stored = page.waitForResponse((response) => response.url().endsWith(`/statuses/${video.id}/watched`)
			&& response.request().method() === 'POST')
		await player.evaluate((element, at) => {
			element.currentTime = at
			element.pause()
		}, seconds)
		const response = await stored
		expect((await response.json()).stored, 'the server kept the place').toBe(true)
	}

	test('offers a video watched past ten seconds, survives a reload, and resumes', async ({ page }) => {
		await stopAt(page, 20)

		await openApp(page, '/timeline/videos')
		const shelf = continueShelf(page)
		await expect(shelf, 'the video is waiting on the Videos page').toBeVisible()
		await expect(shelf.locator('.video-card').filter({ hasText: video.video_card.title })).toBeVisible()

		// the reader comes back through the app, then reloads the page
		await shelf.locator('.video-card').filter({ hasText: video.video_card.title }).click()
		await expect(page).toHaveURL(new RegExp(`/${video.id}$`))
		await page.goBack()
		await expect(continueShelf(page).locator('.video-card').filter({ hasText: video.video_card.title })).toBeVisible()

		await page.reload()
		await expect(continueShelf(page).locator('.video-card').filter({ hasText: video.video_card.title })).toBeVisible()

		// and opening it goes on from where it stopped
		await continueShelf(page).locator('.video-card').filter({ hasText: video.video_card.title }).click()
		const player = page.locator('.video-player__video')
		await expect(player).toBeVisible()
		await expect.poll(() => player.evaluate((element) => element.currentTime)).toBeGreaterThanOrEqual(19)
	})

	test('does not offer a video that was barely started', async ({ page }) => {
		await stopAt(page, 5)

		await openApp(page, '/timeline/videos')
		const shelf = continueShelf(page)
		await expect(shelf.locator('.video-browse__shelf-note'), 'nothing is waiting yet').toContainText('Videos you start watching show up here')
		await expect(shelf.locator('.video-card')).toHaveCount(0)
	})

	test('does not offer a video watched to the end', async ({ page }) => {
		await stopAt(page, 58)

		await openApp(page, '/timeline/videos')
		const shelf = continueShelf(page)
		await expect(shelf.locator('.video-browse__shelf-note'), 'a finished video is forgotten').toContainText('Videos you start watching show up here')
		await expect(shelf.locator('.video-card')).toHaveCount(0)
	})

	test('does not offer a place the server cannot measure', async () => {
		const response = await /** @type {import('@playwright/test').APIRequestContext} */ (api)
			.post(`${APP}/api/v1/statuses/${video.id}/watched`, { data: { position: 20, duration: 0 } })
		expect((await response.json()).stored).toBe(true)

		const list = await /** @type {import('@playwright/test').APIRequestContext} */ (api)
			.get(`${APP}/api/v1/videos/continue?limit=40`)
		expect((await list.json()).map((status) => status.id)).not.toContain(video.id)
	})

	test('asks a visitor with no session nothing about what they were watching', async ({ browser }) => {
		await /** @type {import('@playwright/test').APIRequestContext} */ (api)
			.post(`${APP}/api/v1/statuses/${video.id}/watched`, { data: { position: 20, duration: 60 } })

		const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: IGNORE_HTTPS_ERRORS })
		try {
			const page = await context.newPage()
			/** @type {string[]} */
			const asked = []
			page.on('request', (request) => {
				if (request.url().includes('/api/v1/videos/continue')) {
					asked.push(request.url())
				}
			})

			await page.goto(APP + '/timeline/videos')
			await expect(page.locator('.video-browse')).toBeVisible()
			await expect(page.getByText('Continue watching')).toHaveCount(0)
			expect(asked, 'a visitor is never asked what they were watching').toEqual([])
		} finally {
			await context.close()
		}
	})
})
