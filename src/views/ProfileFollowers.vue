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
	<div class="social__followers">
		<UserEntry v-for="user in users" :key="user.id" :item="user" />
	</div>
</template>

<script>
import UserEntry from '../components/UserEntry.vue'
import serverData from '../mixins/serverData.js'

export default {
	name: 'ProfileFollowers',
	components: {
		UserEntry,
	},
	mixins: [
		serverData,
	],
	computed: {
		/** @return {string} */
		profileAccount() {
			return (this.$route.params.account.indexOf('@') === -1) ? this.$route.params.account + '@' + this.hostname : this.$route.params.account
		},
		/** @return {import('../types/Mastodon.js').Account[]} */
		users() {
			if (this.$route.name === 'profile.followers') {
				return this.$store.getters.getAccountFollowers(this.profileAccount)
			} else {
				return this.$store.getters.getAccountFollowing(this.profileAccount)
			}
		},
	},
	beforeMount() {
		if (this.$route.name === 'profile.followers') {
			this.$store.dispatch('fetchAccountFollowers', this.profileAccount)
		} else {
			this.$store.dispatch('fetchAccountFollowing', this.profileAccount)
		}
	},
}
</script>

<style scoped>
	.social__followers {
		width: 100%;
		max-width: 600px;
		margin: 15px auto;
		display: flex;
		flex-wrap: wrap;
	}

	.user-entry {
		width: 100%;
		padding: 20px;
		margin-bottom: 10px;
	}
</style>
