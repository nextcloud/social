/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import logger from './logger.js'
import { CHANNEL_SAMPLE, looksLikeChannel } from '../utils/videoCard.js'

/** What has been decided, by the handle a profile is opened with. @type {Map<string, Promise<boolean>>} */
const decided = new Map()

/**
 * Whether a profile is a channel, which gives it the Videos, Shorts and Live
 * tabs drawn as a video site draws them (`looksLikeChannel()`).
 *
 * Asked once per account and page load: the account's newest posts of its
 * own, the first page of its profile without the boosts. A profile that could
 * not be read is not a channel, and is asked again next time.
 *
 * @param {string} account the handle or id in the profile's address
 * @return {Promise<boolean>}
 */
export function isVideoChannel(account) {
	if (account === '') {
		return Promise.resolve(false)
	}
	if (!decided.has(account)) {
		decided.set(account, (async () => {
			try {
				const { data } = await axios.get(
					generateUrl(`apps/social/api/v1/accounts/${account}/statuses`),
					{ params: { limit: CHANNEL_SAMPLE, exclude_reblogs: true } },
				)

				return looksLikeChannel(Array.isArray(data) ? data : [])
			} catch (error) {
				logger.debug('Could not tell whether a profile is a channel', { error, account })
				decided.delete(account)

				return false
			}
		})())
	}

	return /** @type {Promise<boolean>} */ (decided.get(account))
}

/** Forgets what was decided; for tests, which share one module. */
export function forgetChannels() {
	decided.clear()
}
