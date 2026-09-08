/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import logger from '../services/logger.js'

/**
 * How many notifications have arrived since the reader last looked.
 *
 * The count comes from the server, which compares against a marker — the same
 * marker every other Fediverse client keeps, so a badge cleared on a phone is
 * cleared here too. Nothing is stored in the browser: a per-device idea of
 * "unread" is worse than none.
 */
export default {
	// a function, unlike its neighbours: a shared object literal is one piece
	// of state for every store built from the module, which is invisible with
	// one store and confusing the moment a test builds a second
	state: () => ({
		unread: 0,
	}),

	mutations: {
		setUnreadNotifications(state, count) {
			state.unread = count
		},
	},

	getters: {
		unreadNotifications(state) {
			return state.unread
		},
	},

	actions: {
		/** Reads the count. Failure is silent: a badge is not worth a toast. */
		async fetchUnreadNotifications(context) {
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/notifications/unread_count'),
				)
				context.commit('setUnreadNotifications', Number(data?.count) || 0)
			} catch (error) {
				logger.error('Failed to read the unread notification count', { error })
			}
		},

		/**
		 * Marks everything up to `lastReadId` as read, and clears the badge
		 * straight away rather than waiting for the server to agree — the
		 * reader is looking at the notifications, so the badge is already wrong.
		 *
		 * @param {object} context the store
		 * @param {string|number} lastReadId the newest notification now seen
		 */
		async markNotificationsRead(context, lastReadId) {
			if (!lastReadId) {
				return
			}

			context.commit('setUnreadNotifications', 0)

			try {
				await axios.post(generateUrl('apps/social/api/v1/markers'), {
					notifications: { last_read_id: String(lastReadId) },
				})
			} catch (error) {
				showError(t('social', 'Could not save your place in the notifications'))
				logger.error('Failed to move the notifications marker', { error })
				// put back whatever the server actually thinks
				context.dispatch('fetchUnreadNotifications')
			}
		},
	},
}
