/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import logger from './logger.js'

/**
 * What a video's page offers to watch next (`GET /api/v1/videos/{id}/related`):
 * more from the same channel first, then videos sharing a hashtag or the
 * category, each only where the reader could have found it on a timeline.
 *
 * @param {string} id the video post's id
 * @param {number} [limit] how many
 * @return {Promise<import('../types/Mastodon.js').Status[]>} [] when the server would not say
 */
export async function loadRelated(id, limit = 12) {
	try {
		const { data } = await axios.get(generateUrl(`apps/social/api/v1/videos/${encodeURIComponent(id)}/related`), { params: { limit } })

		return Array.isArray(data) ? data : []
	} catch (error) {
		logger.debug('Could not read what to watch next', { error, id })

		return []
	}
}
