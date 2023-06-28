<template>
	<component :is="element" class="timeline-entry" :class="{ 'notification': isNotification, 'with-header': hasHeader }">
		<div v-if="isNotification" class="notification__header">
			<span class="notification__summary">
				<img :src="notification.account.avatar">
				<Heart v-if="notification.type === 'favourite'" :size="16" />
				<Repeat v-if="notification.type === 'reblog'" :size="16" />
				<AccountPlusOutline v-if="notification.type === 'follow'" :size="16" />
				<AccountQuestion v-if="notification.type === 'follow_request'" :size="16" />
				<At v-if="notification.type === 'mention'" :size="16" />
				<MessageOutline v-if="notification.type === 'status'" :size="16" />
				<MessagePlusOutline v-if="notification.type === 'update'" :size="16" />
				<Poll v-if="notification.type === 'poll'" :size="16" />
				{{ actionSummary }}
			</span>
			<span class="notification__details">
				<router-link v-if="!notificationIsAboutAnAccount"
					:to="{ name: 'single-post', params: {
						account: item.account.display_name,
						id: notification.status.id,
						type: 'single-post',
					} }"
					:data-timestamp="notification.created_at"
					class="post-timestamp"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</router-link>
				<span v-else
					class="post-timestamp"
					:data-timestamp="notification.created_at"
					:title="notificationFormattedDate">
					{{ notificationRelativeTimestamp }}
				</span>
			</span>
		</div>
		<template v-else-if="isBoost">
			<div class="boost">
				<Repeat :size="16" />
				<router-link :to="{ name: 'profile', params: { account: item.account.acct } }">
					<img :src="item.account.avatar">
					<span :title="item.account.acct" class="post-author">
						{{ item.account.display_name }}&ensp;
					</span>
				</router-link>
				{{ t('social', 'boosted') }}
			</div>
		</template>
		<UserEntry v-if="isNotification && notificationIsAboutAnAccount" :display-follow-button="false" :item="item.account" />
		<template v-else>
			<div v-if="entryContent" class="wrapper">
				<TimelineAvatar v-if="!isNotification" class="entry__avatar" :item="entryContent" />
				<TimelinePost class="entry__content"
					:item="entryContent"
					:type="type" />
			</div>
		</template>
	</component>
</template>

<script>
import Bell from 'vue-material-design-icons/Bell.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import At from 'vue-material-design-icons/At.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import { translate } from '@nextcloud/l10n'
import moment from '@nextcloud/moment'
import TimelinePost from './TimelinePost.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import { notificationSummary } from '../services/notifications.js'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		TimelineAvatar,
		UserEntry,
		Bell,
		Repeat,
		Heart,
		AccountPlusOutline,
		AccountQuestion,
		At,
		Poll,
		MessageOutline,
		MessagePlusOutline,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status|import('../types/Mastodon.js').Notification>} */
		item: {
			type: Object,
			default: () => {},
		},
		type: {
			type: String,
			required: true,
		},
		element: {
			type: String,
			default: 'li',
		},
	},
	computed: {
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.isNotification) {
				return this.notification.status
			} else if (this.isBoost) {
				// We use the object stored in the store so that actions on it are reflected.
				return this.$store.getters.getStatus(this.item.reblog.id)
			} else {
				return this.item
			}
		},
		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},
		/** @return {string} */
		notificationFormattedDate() {
			return moment(this.notification.created_at).format('LLL')
		},
		/** @return {string} */
		notificationRelativeTimestamp() {
			return moment(this.notification.created_at).fromNow()
		},
		/** @return {boolean} */
		isBoost() {
			return this.status.reblog !== null
		},
		/** @return {import('../types/Mastodon.js').Notification} */
		notification() {
			return this.item
		},
		/** @return {import('../types/Mastodon.js').Status} */
		status() {
			return this.item
		},
		/** @return {boolean} */
		notificationIsAboutAnAccount() {
			return ['follow', 'follow_request', 'admin.sign_up', 'admin.report'].includes(this.notification.type)
		},
		/**
		 * @return {boolean}
		 */
		hasHeader() {
			return this.isBoost || this.isNotification
		},
		/**
		 * @return {string}
		 */
		actionSummary() {
			return notificationSummary(this.notification)
		},
	},
	methods: {
		t: translate,
	},
}
</script>
<style scoped lang="scss">
	.wrapper {
		display: flex;
		margin: auto;
		padding: 0;

		&:focus {
			background-color: rgba(47, 47, 47, 0.068);
		}

		.entry__avatar {
			flex-shrink: 0;
		}

		.entry__content {
			flex-grow: 1;
			width: 0;
		}
	}

	.notification-header {
		display: flex;
		align-items: bottom;
	}

	.notification {
		border-bottom: 1px solid var(--color-border);

		&__header {
			display: flex;
			gap: 0.2rem;
			margin-top: 1rem;
		}

		&__summary {
			flex-grow: 1;
			display: inline-block;
			grid-row: 1;
			grid-column: 2;
			color: var(--color-text-lighter);
			position: relative;
			margin-bottom: 8px;

			img {
				width: 32px;
				border-radius: 50%;
				overflow: hidden;
				vertical-align: middle;
				margin-top: -1px;
				margin-right: 8px;
			}

			.material-design-icon {
				position: absolute;
				top: 16px;
				left: 20px;
				padding: 2px;
				background: var(--color-main-background);
				border-radius: 50%;
				border: 1px solid var(--color-background-dark);
			}
		}

		&__details .post-timestamp {
			color: var(--color-text-lighter);
		}
		&__details a {
			&:hover {
				text-decoration: underline;
			}
		}

		:deep(.post-header) {
			.post-visibility {
				display: none;
			}

			.post-timestamp {
				display: none;
			}
		}

		:deep(.user-entry) {
			.user-avatar {
				display: none;
			}
		}
	}

	.boost {
		color: var(--color-text-lighter);
		display: flex;
		margin-left: 21px; // To align with status' text.

		img {
			width: 16px;
			border-radius: 50%;
			vertical-align: middle;
			margin-top: -4px;
			margin-left: 4px;
		}
	}
</style>
