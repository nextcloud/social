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
		<user-entry :item="user" v-for="user in users" />
	</div>
</template>

<style scoped>
	.social__followers {
		max-width: 700px;
		margin: 15px auto;
		display: flex;
	}
	.user-entry {
		width: 50%;
		padding: 10px;
	}
</style>

<script>
import UserEntry from '../components/UserEntry';

export default {
	name: 'ProfileFollowers',
	components: {
		UserEntry,
	},
	computed: {
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		users() {
			return [
				{
					id: 'admin',
					displayName: 'Administrator',
					description: 'My social account',
					following: true
				},
				{
					id: 'admin',
					displayName: 'Administrator',
					description: 'My social account',
					following: false
				}
			]
		}
	}
}
</script>
