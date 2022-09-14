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
			<NcEmptyContent
				v-if="emptyContentMessage"
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

export default {
	name: 'Dashboard',

	components: {
		NcDashboardWidget,
		NcEmptyContent
	},

	props: {
		title: {
			type: String,
			required: true
		}
	},

	data() {
		return {
			notifications: [],
			showMoreUrl: generateUrl('/apps/social/timeline/notifications'),
			showMoreText: t('social', 'Social notifications'),
			loop: null,
			state: 'loading',
			appUrl: generateUrl('/apps/social')
		}
	},

	computed: {
		items() {
			return this.notifications.map((n) => {
				return {
					id: n.id,
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getAvatarUrl(n),
					avatarUsername: this.getActorName(n),
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getMainText(n),
					subText: this.getSubline(n)
				}
			})
		},
		lastTimestamp() {
			return this.notifications.length
				? this.notifications[0].publishedTime
				: 0
		},
		emptyContentMessage() {
			if (this.state === 'error') {
				return t('social', 'Error getting Social notifications')
			} else if (this.state === 'ok') {
				return t('social', 'No Social notifications!')
			}
			return ''
		},
		emptyContentIcon() {
			if (this.state === 'error') {
				return 'icon-close'
			} else if (this.state === 'ok') {
				return 'icon-checkmark'
			}
			return 'icon-checkmark'
		}
	},

	beforeMount() {
		this.fetchNotifications()
		this.loop = setInterval(() => this.fetchNotifications(), 10000)
	},

	methods: {
		fetchNotifications() {
			const req = {
				params: {
					limit: 10
				}
			}
			const url = generateUrl('/apps/social/api/v1/stream/notifications')
			// TODO check why 'since' param is in fact 'until'
			/* if (this.lastDate) {
				req.params.since = this.lastTimestamp,
			} */
			axios.get(url, req).then((response) => {
				if (response.data?.result) {
					this.processNotifications(response.data.result)
					this.state = 'ok'
				} else {
					this.state = 'error'
				}
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response?.status && error.response.status >= 400) {
					showError(t('social', 'Failed to get Social notifications'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.error(error)
				}
			})
		},
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
		filter(notifications) {
			return notifications
			// TODO check if we need to filter
			/* return notifications.filter((n) => {
				return (n.type === 'something' || n.subtype === 'somethingElse')
			}) */
		},
		getMainText(n) {
			if (n.subtype === 'Follow') {
				return t('social', '{account} is following you', { account: this.getActorName(n) })
			}
			if (n.subtype === 'Like') {
				return t('social', '{account} liked your post', { account: this.getActorName(n) })
			}
		},
		getAvatarUrl(n) {
			return undefined
			// TODO get external and internal avatars
			/* return this.getActorAccountName(n)
				? generateUrl('???')
				: undefined */
		},
		getActorName(n) {
			return n.actor_info && n.actor_info.type === 'Person' && n.actor_info.preferredUsername
				? n.actor_info.preferredUsername
				: ''
		},
		getActorAccountName(n) {
			return n.actor_info && n.actor_info.type === 'Person' && n.actor_info.account
				? n.actor_info.account
				: ''
		},
		getNotificationTarget(n) {
			if (n.subtype === 'Follow') {
				return generateUrl('/apps/social/@' + this.getActorAccountName(n) + '/')
			}
			return this.showMoreUrl
		},
		getSubline(n) {
			if (n.subtype === 'Follow') {
				return this.getActorAccountName(n)
			}
			if (n.subtype === 'Like') {
				return this.getActorAccountName(n)
			}
			return ''
		},
		getNotificationTypeImage(n) {
			if (n.subtype === 'Follow') {
				return generateUrl('/svg/social/add_user')
			}
			return ''
		}
	}
}
</script>

<style scoped lang="scss">
::v-deep .connect-button {
	margin-top: 10px;
}
</style>
