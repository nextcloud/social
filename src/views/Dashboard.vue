<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-label="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent v-if="emptyContentMessage"
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
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { notificationSummary } from '../services/notifications.js'

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
		this.loop = setInterval(() => this.fetchNotifications(), 10000)
	},

	methods: {
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
				clearInterval(this.loop)
				if (error.response?.status && error.response.status >= 400) {
					showError(t('social', 'Failed to get Social notifications'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.error(error)
				}
			}
		},
		/** @param {import('../types/Mastodon.js').Notification[]} newNotifications */
		processNotifications(newNotifications) {
			if (this.notifications.length === 0) {
				// first time, we take everything the server sent
				this.notifications = this.filter(newNotifications)
				return
			}
			// the API returns notifications newest first; prepend only the ones
			// we have not seen yet, identified by their id
			const knownIds = new Set(this.notifications.map((n) => n.id))
			const toAdd = this.filter(newNotifications.filter((n) => !knownIds.has(n.id)))
			if (toAdd.length > 0) {
				this.notifications = toAdd.concat(this.notifications)
			}
		},
		/** @param {import('../types/Mastodon.js').Notification[]} notifications */
		filter(notifications) {
			return notifications
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
