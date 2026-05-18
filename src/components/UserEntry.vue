<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="item" class="user-entry">
		<div class="entry-content">
			<div class="user-avatar">
				<NcAvatar v-if="isLocal"
					:size="32"
					:user="item.username"
					:disable-tooltip="true" />
				<NcAvatar v-else :url="item.avatar" />
			</div>
			<div class="user-details">
				<router-link v-if="!serverData.public" :to="{ name: 'profile', params: { account: item.acct }}">
					<span class="post-author">
						{{ item.display_name }}
					</span>
					<span class="user-description">
						{{ item.acct }}
					</span>
				</router-link>
				<a v-else
					:href="item.id"
					target="_blank"
					rel="noreferrer">
					<span class="post-author">
						{{ item.display_name }}
					</span>
					<span class="user-description">
						{{ item.acct }}
					</span>
				</a>
				<!-- eslint-disable-next-line vue/no-v-html -->
				<p v-html="item.note" />
			</div>
			<FollowButton v-if="displayFollowButton" :uid="item.acct" />
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import currentUser from '../mixins/currentUserMixin.js'
import FollowButton from './FollowButton.vue'

export default {
	name: 'UserEntry',
	components: {
		FollowButton,
		NcAvatar,
	},
	mixins: [
		currentUser,
	],
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */
		item: {
			type: Object,
			default: () => {},
		},
		displayFollowButton: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.item.acct.includes('@')
		},
	},
	async mounted() {
		if (!this.serverData.public && this.relationship === undefined) {
			await this.$store.dispatch('fetchAccountRelationshipInfo', [this.item.id])
		}
	},
}
</script>
<style scoped lang="scss">
.user-entry {
	width: 100%;
	padding: 16px 20px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	box-sizing: border-box;
}

.entry-content {
	display: flex;
	align-items: flex-start;
	gap: 12px;

	.user-avatar {
		flex-shrink: 0;
		margin-top: 2px;
	}

	.user-details {
		flex: 1;
		min-width: 0;

		a {
			display: inline-flex;
			align-items: baseline;
			gap: 6px;
			text-decoration: none;
			color: var(--color-main-text);

			&:hover .post-author {
				color: var(--color-primary-element);
			}
		}

		.post-author {
			font-weight: 650;
			font-size: 14px;
		}

		.user-description {
			font-size: 13px;
			color: var(--color-text-lighter);
		}

		p {
			margin: 4px 0 0;
			font-size: 13px;
			line-height: 1.5;
			color: var(--color-text-lighter);
			word-wrap: break-word;
			overflow: hidden;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			max-height: 3em;
		}
	}
}
</style>
