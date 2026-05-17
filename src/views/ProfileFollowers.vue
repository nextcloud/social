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
			if (!this.$route.params.account) return ''
			return (this.$route.params.account.indexOf('@') === -1) ? this.$route.params.account + '@' + this.hostname : this.$route.params.account
		},
		/** @return {import('../types/Mastodon.js').Account[]} */
		users() {
			if (!this.profileAccount) return []
			if (this.$route.name === 'profile.followers') {
				return this.$store.getters.getAccountFollowers(this.profileAccount)
			} else {
				return this.$store.getters.getAccountFollowing(this.profileAccount)
			}
		},
	},
	watch: {
		'$route.params.account': 'fetchData',
		'$route.name': 'fetchData',
	},
	beforeMount() {
		this.fetchData()
	},
	methods: {
		fetchData() {
			if (!this.profileAccount) return
			if (this.$route.name === 'profile.followers') {
				this.$store.dispatch('fetchAccountFollowers', this.profileAccount)
			} else if (this.$route.name === 'profile.following') {
				this.$store.dispatch('fetchAccountFollowing', this.profileAccount)
			}
		},
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
		border: 1px solid var(--color-border);
		border-radius: 14px;
		background: var(--color-main-background);
	}
</style>
