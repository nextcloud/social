/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import logger from './logger.js'

/**
 * How far the reader got through a video, for the red bar under a card and
 * the Continue watching shelf on the Videos page.
 *
 * The server keeps it, one row per video and reader (`WatchService`), moved
 * by `POST /api/v1/statuses/{id}/watched` and handed back with the videos
 * the reader is in the middle of (`GET /api/v1/videos/continue`, each status
 * with `watch_progress`). That list is read once per page view
 * (`loadUnfinished()`), and what it said is kept here by post, so a card for
 * the same video anywhere on the page draws the same bar.
 *
 * @typedef {object} VideoProgress
 * @property {number} position - where the reader stopped, in seconds
 * @property {number} duration - how long the video runs, in seconds
 * @property {number} fraction - `position / duration`, from 0 to 1
 * @property {number} updatedAt - when it was last moved, as a timestamp in milliseconds; 0 when not known
 */

/** What is known, by post id. @type {Map<string, VideoProgress>} */
const known = reactive(new Map())

/**
 * @param {{position?: number, duration?: number, updated_at?: ?string}|null|undefined} watch a status's `watch_progress`
 * @return {VideoProgress|null}
 */
function fromWatch(watch) {
	const position = Number(watch?.position ?? 0)
	const duration = Number(watch?.duration ?? 0)
	if (!(duration > 0) || !(position > 0)) {
		return null
	}

	return {
		position,
		duration,
		fraction: Math.min(1, position / duration),
		updatedAt: Date.parse(watch.updated_at ?? '') || 0,
	}
}

/**
 * @param {import('../types/Mastodon.js').Status} status the video post (not the boost of it)
 * @return {VideoProgress|null} how far the reader got, or null when they never started it or finished it
 */
export function progressOf(status) {
	return fromWatch(status?.watch_progress) ?? known.get(String(status?.id ?? '')) ?? null
}

/**
 * Whether a video counts as begun and not finished: what the Continue
 * watching shelf holds. The server forgets a video watched past 95%.
 *
 * @param {VideoProgress|null} progress what `progressOf()` answered
 * @return {boolean}
 */
export function isUnfinished(progress) {
	return progress !== null && progress.fraction > 0 && progress.fraction < 0.95
}

/**
 * The videos the reader is in the middle of, most recently watched first,
 * remembering where they stopped in each.
 *
 * @param {number} [limit] how many
 * @return {Promise<import('../types/Mastodon.js').Status[]>} [] when the server would not say
 */
export async function loadUnfinished(limit = 12) {
	try {
		const { data } = await axios.get(generateUrl('apps/social/api/v1/videos/continue'), { params: { limit } })
		const statuses = Array.isArray(data) ? data : []
		for (const status of statuses) {
			const progress = fromWatch(status.watch_progress)
			if (progress !== null) {
				known.set(String(status.id), progress)
			}
		}

		return statuses
	} catch (error) {
		logger.debug('Could not read what the reader was watching', { error })

		return []
	}
}

/** Forgets what is known; for tests, which share one module. */
export function forgetProgress() {
	known.clear()
}
