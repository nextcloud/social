<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- Show button only if user is authenticated and she is not the same as the account viewed -->
	<div v-if="!serverData.public && relationship !== undefined">
		<div v-if="relationship.following"
			class="follow-button-container">
			<NcButton :disabled="loading"
				class="follow-button follow-button--following"
				type="success">
				<template #icon>
					<Check :size="32" />
				</template>
				{{ t('social', 'Following') }}
			</NcButton>
			<NcButton :disabled="loading"
				class="follow-button follow-button--unfollow"
				type="error"
				@click="unfollow()">
				<template #icon>
					<CloseOctagon :size="32" />
				</template>
				{{ t('social', 'Unfollow') }}
			</NcButton>
		</div>
		<NcButton v-else
			:disabled="loading"
			type="primary"
			class="follow-button"
			@click="follow">
			{{ t('social', 'Follow') }}
		</NcButton>
	</div>
</template>

<script>
import accountMixins from '../mixins/accountMixins.js'
import currentUser from '../mixins/currentUserMixin.js'
import Check from 'vue-material-design-icons/Check.vue'
import CloseOctagon from 'vue-material-design-icons/CloseOctagon.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'FollowButton',
	components: {
		Check,
		CloseOctagon,
		NcButton,
	},
	mixins: [
		accountMixins,
		currentUser,
	],
	props: {
		uid: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: false,
		}
	},
	computed: {
		/** @return {boolean} */
		isCurrentUserFollowing() {
			return this.$store.getters.isFollowingUser(this.profileAccount)
		},
		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.$store.getters.currentAccount
		},
	},
	methods: {
		async follow() {
			try {
				this.loading = true
				await this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.profileAccount })
			} finally {
				this.loading = false
			}
		},
		async unfollow() {
			try {
				this.loading = true
				await this.$store.dispatch('unfollowAccount', { currentAccount: this.cloudId, accountToUnfollow: this.profileAccount })
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
<style scoped lang="scss">
	.follow-button {
		width: 150px !important;
	}

	.follow-button-container {
		.follow-button--following {
			display: flex;
		}
		.follow-button--unfollow {
			display: none;
		}

		&:hover {
			.follow-button--following {
				display: none;
			}
			.follow-button--unfollow {
				display: flex;
			}
		}
	}
	.user-entry {
		padding: 20px;
		margin-bottom: 10px;
	}

	.user-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		flex-shrink: 0;
	}

	.post-author {
		font-weight: bold;
	}

	.entry-content {
		display: flex;
		align-items: flex-start;
	}

	.user-details {
		flex-grow: 1;
	}

	.user-description {
		opacity: 0.7;
	}

	button {
		min-width: 110px;
	}

	button * {
		cursor: pointer;
	}
</style>
