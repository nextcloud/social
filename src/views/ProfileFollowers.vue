<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__followers">
		<UserEntry v-for="user in users" :key="user.id" :item="user" />
		<div ref="sentinel" class="list-sentinel" />
		<div v-if="loading" class="loading-indicator">
			<NcLoadingIcon :size="24" />
			<span>{{ t('social', 'Loading …') }}</span>
		</div>
		<!-- a finished list with nobody in it used to be a blank panel -->
		<NcEmptyContent
			v-else-if="users.length === 0"
			:name="isFollowers ? t('social', 'No followers yet') : t('social', 'Not following anyone yet')"
			:description="isFollowers
				? t('social', 'People who follow this account will show up here.')
				: t('social', 'Accounts this account follows will show up here.')">
			<template #icon>
				<AccountMultipleOutline :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import UserEntry from '../components/UserEntry.vue'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'ProfileFollowers',
	components: {
		AccountMultipleOutline,
		NcEmptyContent,
		NcLoadingIcon,
		UserEntry,
	},

	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	data() {
		return {
			observer: null,
		}
	},

	computed: {
		...mapStores(useAccountStore),
		/** @return {string} */
		profileAccount() {
			if (!this.$route.params.account) {
				return ''
			}
			return (this.$route.params.account.indexOf('@') === -1) ? this.$route.params.account + '@' + this.hostname : this.$route.params.account
		},

		/** @return {string} */
		storeKey() {
			return this.accountStore.getActorIdForAccount(this.profileAccount) || this.profileAccount
		},

		/** @return {import('../types/Mastodon.js').Account[]} */
		users() {
			if (!this.profileAccount) {
				return []
			}
			if (this.$route.name === 'profile.followers') {
				return this.accountStore.getAccountFollowers(this.profileAccount)
			} else {
				return this.accountStore.getAccountFollowing(this.profileAccount)
			}
		},

		isFollowers() {
			return this.$route.name === 'profile.followers'
		},

		loading() {
			if (!this.profileAccount) {
				return false
			}
			if (this.isFollowers) {
				return !!this.accountStore.accountsFollowersLoading[this.storeKey]
			} else {
				return !!this.accountStore.accountsFollowingsLoading[this.storeKey]
			}
		},

		allLoaded() {
			if (!this.profileAccount) {
				return true
			}
			if (this.isFollowers) {
				return !!this.accountStore.accountsFollowersAllLoaded[this.storeKey]
			} else {
				return !!this.accountStore.accountsFollowingsAllLoaded[this.storeKey]
			}
		},

		maxId() {
			if (!this.profileAccount) {
				return 0
			}
			if (this.isFollowers) {
				return this.accountStore.accountsFollowersMaxId[this.storeKey] || 0
			} else {
				return this.accountStore.accountsFollowingsMaxId[this.storeKey] || 0
			}
		},
	},

	watch: {
		'$route.params.account': 'fetchData',
		'$route.name': 'fetchData',
		loading(val) {
			if (!val) {
				this.$nextTick(() => {
					if (this.isSentinelVisible()) {
						this.loadMoreIfNeeded()
					}
				})
			}
		},
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
		t: translate,
		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.allLoaded) {
					this.loadMoreIfNeeded()
				}
			}, { rootMargin: '300px' })
			if (this.$refs.sentinel) {
				this.observer.observe(this.$refs.sentinel)
			}
		},

		fetchData() {
			if (!this.profileAccount) {
				return
			}
			if (this.isFollowers) {
				this.accountStore.fetchAccountFollowers({ account: this.profileAccount })
			} else {
				this.accountStore.fetchAccountFollowing({ account: this.profileAccount })
			}
		},

		loadMoreIfNeeded() {
			if (this.loading || this.allLoaded || !this.maxId) {
				return
			}
			if (this.isFollowers) {
				this.accountStore.fetchAccountFollowers({ account: this.profileAccount, maxId: this.maxId })
			} else {
				this.accountStore.fetchAccountFollowing({ account: this.profileAccount, maxId: this.maxId })
			}
		},

		isSentinelVisible() {
			if (!this.$refs.sentinel) {
				return false
			}
			const rect = this.$refs.sentinel.getBoundingClientRect()
			return rect.top <= window.innerHeight + 300
		},
	},
}
</script>

<style scoped>
	.social__followers {
		width: 100%;
		max-width: var(--social-column);
		margin: 15px auto;
		display: flex;
		flex-direction: column;
	}

	.list-sentinel {
		height: 1px;
	}

	.loading-indicator {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		padding: 16px;
		color: var(--color-text-lighter);
	}
</style>
