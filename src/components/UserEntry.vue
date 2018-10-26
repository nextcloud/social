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
	<div class="user-entry">
		<div class="entry-content">
			<div class="user-avatar">
				<avatar :size="32" :user="item.id" />
			</div>
			<div class="user-details">
				<router-link :to="{ name: 'profile', params: { account: item.id }}">
					<span class="post-author">{{ item.displayName }}</span>
				</router-link>
				<p class="user-description">{{ item.description}}</p>
			</div>
			<button v-if="item.following" class="icon-checkmark-color">Following</button>
			<button v-else class="primary">Follow</button>
		</div>
	</div>
</template>

<script>
import { Avatar } from 'nextcloud-vue'

export default {
	name: 'UserEntry',
	components: {
		Avatar
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	data: function() {
		return {

		}
	}
}
</script>
<style scoped>
	.user-entry {
		padding: 10px;
		margin-bottom: 10px;
	}

	.user-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		flex-shrink: 0;
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
</style>
