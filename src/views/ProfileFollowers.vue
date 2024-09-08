<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
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
