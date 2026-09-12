<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget
		:items="items"
		:showMoreUrl="showMoreUrl"
		:showMoreLabel="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent
				v-if="emptyContentMessage"
				:name="emptyContentMessage">
				<template #icon>
					<div :class="emptyContentIcon" />
				</template>
				<template #description>
					<div v-if="state === 'error'" class="connect-button">
						<a class="button" :href="appUrl">
							{{ t('social', 'Go to Social app') }}
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { listen } from '@nextcloud/notify_push'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { notificationSummary } from '../services/notifications.js'
import logger from '../services/logger.js'

/** Without notify_push the widget has to ask; once a minute is enough for a tile. */
const POLL_MS = 60 * 1000

/** A tile shows a handful of rows, so there is no point in keeping more. */
const MAX_ITEMS = 10

export default {
	name: 'Dashboard',

	components: {
		NcDashboardWidget,
		NcEmptyContent,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			notifications: [],
			showMoreUrl: generateUrl('/apps/social/timeline/notifications'),
			showMoreText: t('social', 'Social notifications'),
			loop: null,
			stopListening: null,
			state: 'loading',
			appUrl: generateUrl('/apps/social'),
		}
	},

	computed: {
		/** @return {object[]} */
		items() {
			return this.notifications.map((n) => {
				return {
					id: n.id,
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getAvatarUrl(n),
					avatarUsername: this.getActorName(n),
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getMainText(n),
					subText: this.getSubline(n),
				}
			})
		},

		/** @return {string} */
		emptyContentMessage() {
			if (this.state === 'error') {
				return t('social', 'Error getting Social notifications')
			} else if (this.state === 'ok') {
				return t('social', 'No Social notifications!')
			}
			return ''
		},

		/** @return {string} */
		emptyContentIcon() {
			if (this.state === 'error') {
				return 'icon-close'
			} else if (this.state === 'ok') {
				return 'icon-checkmark'
			}
			return 'icon-checkmark'
		},
	},

	beforeMount() {
		this.fetchNotifications()
	},

	mounted() {
		// with notify_push the server says when something arrived; without it
		// a slow poll keeps the tile current without hammering the instance
		this.stopListening = listen('social_timeline', () => this.fetchNotifications())
		if (!this.stopListening) {
			this.loop = setInterval(() => this.fetchNotifications(), POLL_MS)
		}
	},

	beforeUnmount() {
		if (typeof this.stopListening === 'function') {
			this.stopListening()
		}
		this.stopPolling()
	},

	methods: {
		stopPolling() {
			if (this.loop !== null) {
				clearInterval(this.loop)
				this.loop = null
			}
		},

		async fetchNotifications() {
			const url = generateUrl('apps/social/api/v1/notifications')

			try {
				const response = await axios.get(url)
				if (response.data) {
					this.processNotifications(response.data)
					this.state = 'ok'
				} else {
					this.state = 'error'
				}
			} catch (error) {
				this.stopPolling()
				if (error.response?.status && error.response.status >= 400) {
					showError(t('social', 'Failed to get Social notifications'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					logger.error('Failed to process the Social notifications', { error })
				}
			}
		},

		/** @param {import('../types/Mastodon.js').Notification[]} newNotifications */
		processNotifications(newNotifications) {
			if (this.notifications.length === 0) {
				// first time, we take everything the server sent
				this.notifications = newNotifications.slice(0, MAX_ITEMS)
				return
			}
			// the API returns notifications newest first; prepend only the ones
			// we have not seen yet, identified by their id. The cut keeps a tab
			// left open for days from accumulating every notification it saw.
			const knownIds = new Set(this.notifications.map((n) => n.id))
			const toAdd = newNotifications.filter((n) => !knownIds.has(n.id))
			if (toAdd.length > 0) {
				this.notifications = toAdd.concat(this.notifications).slice(0, MAX_ITEMS)
			}
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getMainText(n) {
			return notificationSummary(n)
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getAvatarUrl(n) {
			return n.account.avatar
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getActorName(n) {
			return n.account.display_name
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getActorAccountName(n) {
			return n.account.acct
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getNotificationTarget(n) {
			if (n.type === 'follow') {
				return generateUrl('/apps/social/@' + this.getActorAccountName(n) + '/')
			}
			return this.showMoreUrl
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getSubline(n) {
			if (n.type === 'follow') {
				return this.getActorAccountName(n)
			}
			if (n.type === 'favourite') {
				return this.getActorAccountName(n)
			}
			return ''
		},

		/** @param {import('../types/Mastodon.js').Notification} n */
		getNotificationTypeImage(n) {
			if (n.type === 'follow') {
				return generateUrl('/svg/social/add_user')
			}
			return ''
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
