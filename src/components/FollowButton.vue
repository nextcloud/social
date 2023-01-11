<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
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
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<!-- Show button only if user is authenticated and she is not the same as the account viewed -->
	<div v-if="!serverData.public && accountInfo && accountInfo.viewerLink!='viewer'">
		<div v-if="isCurrentUserFollowing"
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
		account: {
			type: String,
			default: '',
		},
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
		isCurrentUserFollowing() {
			return this.$store.getters.isFollowingUser(this.account)
		},
	},
	methods: {
		async follow() {
			try {
				this.loading = true
				await this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.account })
			} catch {
			} finally {
				this.loading = false
			}
		},
		async unfollow() {
			try {
				this.loading = true
				await this.$store.dispatch('unfollowAccount', { currentAccount: this.cloudId, accountToUnfollow: this.account })
			} catch {
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
