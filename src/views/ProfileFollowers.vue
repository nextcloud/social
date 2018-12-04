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
		<user-entry v-for="user in users" :item="user" :key="user.id" />
	</div>
</template>

<style scoped>
	.social__followers {
		max-width: 600px;
		margin: 15px auto;
		display: flex;
		flex-wrap: wrap;
	}
	.user-entry {
		width: 50%;
	}
</style>

<script>
import UserEntry from '../components/UserEntry'

export default {
	name: 'ProfileFollowers',
	components: {
		UserEntry
	},
	computed: {
		users: function() {
			if (this.$route.name === 'profile.followers') {
				return this.$store.getters.getAccountFollowers(this.$route.params.account)
			} else {
				return this.$store.getters.getAccountFollowing(this.$route.params.account)
			}
		}
	},
	beforeMount() {
		if (this.$route.name === 'profile.followers') {
			this.$store.dispatch('fetchAccountFollowers', this.$route.params.account)
		} else {
			this.$store.dispatch('fetchAccountFollowing', this.$route.params.account)
		}
	}
}
</script>
