/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '../services/toast.js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

import logger from '../services/logger.js'

/**
 * How many notifications have arrived since the reader last looked, and where
 * "last looked" is.
 *
 * Both come from the server, which keeps a marker — the same marker every
 * other Fediverse client keeps, so a badge cleared on a phone is cleared here
 * too. Nothing is stored in the browser: a per-device idea of "unread" is
 * worse than none.
 */
export const useNotificationsStore = defineStore('notifications', {
	state: () => ({
		unread: 0,
		/**
		 * The row id the reader had read up to, the last time the server was
		 * asked; 0 while unknown or when nothing has ever been read. What the
		 * notifications page draws its "New" line from.
		 */
		lastReadId: 0,
	}),

	getters: {
		/**
		 * @param {object} state the store state
		 * @return {number} what the badge shows
		 */
		unreadNotifications(state) {
			return state.unread
		},
	},

	actions: {
		setUnreadNotifications(count) {
			this.unread = count
		},

		/** Reads the count. Failure is silent: a badge is not worth a toast. */
		async fetchUnreadNotifications() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/notifications/unread_count'))
				this.setUnreadNotifications(Number(data?.count) || 0)
			} catch (error) {
				logger.error('Failed to read the unread notification count', { error })
			}
		},

		/**
		 * Reads where the reader had got to, as the server remembers it.
		 *
		 * Asked when the notifications page opens, so that the line between
		 * what is new and what was already seen is drawn where every client
		 * agrees it is. A failure answers 0 — no line at all — rather than
		 * guessing, and says nothing: the list itself is still readable.
		 *
		 * @return {Promise<number>} the marker; 0 when unknown
		 */
		async fetchLastRead() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/markers'), {
					params: { timeline: ['notifications'] },
				})
				const marker = Number(data?.notifications?.last_read_id) || 0
				this.lastReadId = Math.max(this.lastReadId, marker)

				return marker
			} catch (error) {
				logger.error('Failed to read the notifications marker', { error })

				return 0
			}
		},

		/**
		 * Marks everything up to `lastReadId` as read, and clears the badge
		 * straight away rather than waiting for the server to agree — the
		 * reader has seen the notifications, so the badge is already wrong.
		 *
		 * @param {string|number} lastReadId the newest notification now seen
		 */
		async markNotificationsRead(lastReadId) {
			if (!lastReadId) {
				return
			}

			this.setUnreadNotifications(0)
			// the server never moves a marker backwards, and neither does this
			this.lastReadId = Math.max(this.lastReadId, Number(lastReadId) || 0)

			try {
				await axios.post(generateUrl('apps/social/api/v1/markers'), {
					notifications: { last_read_id: String(lastReadId) },
				})
			} catch (error) {
				showError(t('social', 'Could not save your place in the notifications'))
				logger.error('Failed to move the notifications marker', { error })
				// put back whatever the server actually thinks
				this.fetchUnreadNotifications()
			}
		},
	},
})
