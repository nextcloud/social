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
		<button v-if="isCurrentUserFollowing" :class="{'icon-loading-small': followLoading}"
			@click="unfollow()"
			@mouseover="followingText=t('social', 'Unfollow')" @mouseleave="followingText=t('social', 'Following')">
			<span><span class="icon-checkmark" />{{ followingText }}</span>
		</button>
		<button v-else :class="{'icon-loading-small': followLoading}" class="primary"
			@click="follow">
			<span>{{ t('social', 'Follow') }}</span>
		</button>
	</div>
</template>

<script>
import accountMixins from '../mixins/accountMixins'
import currentUser from '../mixins/currentUserMixin'

export default {
	name: 'FollowButton',
	mixins: [
		accountMixins,
		currentUser
	],
	props: {
		account: {
			type: String,
			default: ''
		},
		uid: {
			type: String,
			default: ''
		}
	},
	data: function() {
		return {
			followingText: t('social', 'Following')
		}
	},
	computed: {
		followLoading() {
			return false
		},
		isCurrentUserFollowing() {
			return this.$store.getters.isFollowingUser(this.account)
		}
	},
	methods: {
		follow() {
			this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.account })
		},
		unfollow() {
			this.$store.dispatch('unfollowAccount', { currentAccount: this.cloudId, accountToUnfollow: this.account })
		}
	}
}
</script>
<style scoped>
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
