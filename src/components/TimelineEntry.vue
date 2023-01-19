<template>
	<div :class="['timeline-entry', hasHeader ? 'with-header' : '']">
		<div v-if="isNotification" class="notification">
			<Bell :size="22" />
			<span class="notification-action">
				{{ actionSummary }}
			</span>
		</div>
		<template v-else-if="isBoost">
			<div class="container-icon-boost boost">
				<span class="icon-boost" />
			</div>
			<div class="boost">
				<router-link v-if="!isProfilePage && item.account"
					:to="{ name: 'profile', params: { account: item.account.username } }">
					<span v-tooltip.bottom="item.account.acct" class="post-author">
						{{ item.account.display_name }}
					</span>
				</router-link>
				<a v-else :href="item.account.id">
					<span class="post-author-id">
						{{ item.account.id }}
					</span>
				</a>
				{{ t('social', 'boosted') }}
			</div>
		</template>
		<UserEntry v-if="isNotification && notificationIsAboutAnAccount"
			:key="item.account.id"
			:item="item.account" />
		<template v-else>
			<div class="wrapper">
				<TimelineAvatar class="entry__avatar" :item="entryContent" />
				<TimelinePost class="entry__content"
					:item="entryContent"
					:type="type" />
			</div>
		</template>
	</div>
</template>

<script>
import TimelinePost from './TimelinePost.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import Bell from 'vue-material-design-icons/Bell.vue'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		TimelineAvatar,
		UserEntry,
		Bell,
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
		isProfilePage: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.isNotification) {
				return this.notification.status
			} else {
				return this.item
			}
		},
		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},
		/**
		 * @return {boolean}
		 */
		isBoost() {
			return this.reblog !== null
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
			return this.notification.type in ['follow', 'follow_request', 'admin.sign_up', 'admin.report']
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
			switch (this.notification.type) {
			case 'mention':
				return t('social', '{account} mentioned you', { account: this.notification.account.acct })
			case 'status':
				return t('social', '{account} has posted a status', { account: this.notification.account.acct })
			case 'reblog':
				return t('social', '{account} boosted your post', { account: this.notification.account.acct })
			case 'follow':
				return t('social', '{account} started following you', { account: this.notification.account.acct })
			case 'follow_request':
				return t('social', '{account} requested to follow you', { account: this.notification.account.acct })
			case 'favourite':
				return t('social', '{account} like you post', { account: this.notification.account.acct })
			case 'poll':
				return t('social', '{account} as ended the poll', { account: this.notification.account.acct })
			case 'update':
				return t('social', '{account} edit a status', { account: this.notification.account.acct })
			case 'admin.sign_up':
				return t('social', '{account} signed up', { account: this.notification.account.acct })
			case 'admin.report':
				return t('social', '{account} filled a report', { account: this.notification.account.acct })
			default:
				return ''
			}
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
		display: flex;
		padding-left: 2rem;
		gap: 0.2rem;
		margin-top: 1rem;

		&-action {
			flex-grow: 1;
			display: inline-block;
			grid-row: 1;
			grid-column: 2;
			color: var(--color-text-lighter);
		}

		.bell-icon {
			opacity: .5;
		}
	}

	.icon-boost {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-favorite {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-user {
		display: inline-block;
		vertical-align: middle;
	}

	.container-icon-boost {
		display: inline-block;
		padding-right: 6px;
	}

	.icon-boost {
		display: inline-block;
		width: 38px;
		height: 17px;
		opacity: .5;
		background-position: right center;
		vertical-align: middle;
	}

	.boost {
		opacity: .5;
	}
</style>
