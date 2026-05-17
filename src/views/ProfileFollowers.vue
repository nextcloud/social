<template>
	<div class="social__followers">
		<UserEntry v-for="user in users" :key="user.id" :item="user" />
		<div ref="sentinel" class="list-sentinel" />
		<div v-if="loading" class="loading-indicator">Loading…</div>
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
	data() {
		return {
			observer: null,
		}
	},
	computed: {
		/** @return {string} */
		profileAccount() {
			if (!this.$route.params.account) return ''
			return (this.$route.params.account.indexOf('@') === -1) ? this.$route.params.account + '@' + this.hostname : this.$route.params.account
		},
		/** @return {string} */
		actorId() {
			return this.$store.getters.getActorIdForAccount(this.profileAccount)
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
		isFollowers() {
			return this.$route.name === 'profile.followers'
		},
		loading() {
			if (!this.actorId) return false
			if (this.isFollowers) {
				return !!this.$store.state.account.accountsFollowersLoading[this.actorId]
			} else {
				return !!this.$store.state.account.accountsFollowingsLoading[this.actorId]
			}
		},
		allLoaded() {
			if (!this.actorId) return true
			if (this.isFollowers) {
				return !!this.$store.state.account.accountsFollowersAllLoaded[this.actorId]
			} else {
				return !!this.$store.state.account.accountsFollowingsAllLoaded[this.actorId]
			}
		},
		maxId() {
			if (!this.actorId) return 0
			if (this.isFollowers) {
				return this.$store.state.account.accountsFollowersMaxId[this.actorId] || 0
			} else {
				return this.$store.state.account.accountsFollowingsMaxId[this.actorId] || 0
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
	mounted() {
		this.$nextTick(() => this.setupIntersectionObserver())
	},
	unmounted() {
		if (this.observer) {
			this.observer.disconnect()
		}
	},
	methods: {
		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.loading && !this.allLoaded) {
					this.loadMore()
				}
			}, { rootMargin: '300px' })
			if (this.$refs.sentinel) {
				this.observer.observe(this.$refs.sentinel)
			}
		},
		fetchData() {
			if (!this.profileAccount) return
			if (this.isFollowers) {
				this.$store.dispatch('fetchAccountFollowers', { account: this.profileAccount })
			} else {
				this.$store.dispatch('fetchAccountFollowing', { account: this.profileAccount })
			}
		},
		async loadMore() {
			if (this.loading || !this.maxId) return
			if (this.isFollowers) {
				await this.$store.dispatch('fetchAccountFollowers', { account: this.profileAccount, max_id: this.maxId })
			} else {
				await this.$store.dispatch('fetchAccountFollowing', { account: this.profileAccount, max_id: this.maxId })
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
		flex-direction: column;
	}

	.list-sentinel {
		height: 1px;
	}

	.loading-indicator {
		text-align: center;
		padding: 16px;
		color: var(--color-text-lighter);
	}
</style>
