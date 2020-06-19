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
	<div v-if="item" class="user-entry">
		<div class="entry-content">
			<div class="user-avatar">
				<avatar v-if="item.local" :size="32" :user="item.preferredUsername"
					:disable-tooltip="true" />
				<avatar v-else :url="avatarUrl" />
			</div>
			<div class="user-details">
				<router-link v-if="!serverData.public" :to="{ name: 'profile', params: { account: item.local ? item.preferredUsername : item.account }}">
					<span class="post-author">
						{{ item.name }}
					</span>
					<span class="user-description">
						{{ item.account }}
					</span>
				</router-link>
				<a v-else :href="item.id" target="_blank"
					rel="noreferrer">
					<span class="post-author">
						{{ item.name }}
					</span>
					<span class="user-description">
						{{ item.account }}
					</span>
				</a>
				<!-- eslint-disable-next-line vue/no-v-html -->
				<p v-html="item.summary" />
			</div>
			<follow-button :account="item.account" />
		</div>
	</div>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import follow from '../mixins/follow'
import currentUser from '../mixins/currentUserMixin'
import FollowButton from './FollowButton.vue'

export default {
	name: 'UserEntry',
	components: {
		FollowButton,
		Avatar
	},
	mixins: [
		follow,
		currentUser
	],
	props: {
		item: { type: Object, default: () => {} }
	},
	data: function() {
		return {
			followingText: t('social', 'Following')
		}
	},
	computed: {
		id() {
			if (this.item.actor_info) {
				return this.item.actor_info.id
			}
			return this.item.id
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.id)
		}
	}
}
</script>
<style scoped>
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
		margin-left: 10px;
		min-width: 110px;
	}

	button * {
		cursor: pointer;
	}
</style>
