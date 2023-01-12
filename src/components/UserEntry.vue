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
				<NcAvatar v-if="item.local"
					:size="32"
					:user="item.preferredUsername"
					:disable-tooltip="true" />
				<NcAvatar v-else :url="avatarUrl" />
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
				<a v-else
					:href="item.id"
					target="_blank"
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
			<FollowButton :account="item.account" :uid="cloudId" />
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import follow from '../mixins/follow.js'
import currentUser from '../mixins/currentUserMixin.js'
import FollowButton from './FollowButton.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'UserEntry',
	components: {
		FollowButton,
		NcAvatar,
	},
	mixins: [
		follow,
		currentUser,
	],
	props: {
		item: { type: Object, default: () => {} },
	},
	data() {
		return {
			followingText: t('social', 'Following'),
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
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.id)
		},
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
