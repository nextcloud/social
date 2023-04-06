<!--
 - @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 -
 - @author Julien Veyssier <eneiluj@posteo.net>
 -
 - @license GNU AGPL version 3 or any later version
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
	<NcDashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent v-if="emptyContentMessage"
				:icon="emptyContentIcon">
				<template #desc>
					{{ emptyContentMessage }}
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
import NcDashboardWidget from '@nextcloud/vue/dist/Components/NcDashboardWidget.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
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
		/** @return {number} */
		lastTimestamp() {
			return this.notifications.length
				? this.notifications[0].publishedTime
				: 0
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
			if (this.lastTimestamp !== 0) {
				// just add those which are more recent than our most recent one
				let i = 0
				while (i < newNotifications.length && this.lastTimestamp < newNotifications[i].publishedTime) {
					i++
				}
				if (i > 0) {
					const toAdd = this.filter(newNotifications.slice(0, i))
					this.notifications = toAdd.concat(this.notifications)
				}
			} else {
				// first time, we don't check the date
				this.notifications = this.filter(newNotifications)
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
::v-deep .connect-button {
	margin-top: 10px;
}
</style>
