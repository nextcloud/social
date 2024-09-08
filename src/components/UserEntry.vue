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
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
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
		if (this.relationship === undefined) {
			await this.$store.dispatch('fetchAccountRelationshipInfo', [this.item.id])
		}
	},
}
</script>
<style scoped lang="scss">
.entry-content {
	height: 50px;
	display: flex;
	align-items: center;

	.user-avatar {
		display: flex;
		align-items: center;
		margin-right: 10px;
		flex-shrink: 0;
	}

	.user-details {
		flex-grow: 1;

		.post-author {
			font-weight: bold;
		}

		.user-description {
			opacity: 0.7;
		}
	}
}

</style>
