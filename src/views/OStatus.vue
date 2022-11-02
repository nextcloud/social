<template>
	<div v-if="account">
		<div v-if="!serverData.local">
			<h2>{{ t('social', 'Follow on Nextcloud Social') }}</h2>
			<p>{{ t('social', 'Hello') }} <avatar :user="currentUser.uid" :size="16" />{{ currentUser.displayName }}</p>
			<p v-if="!isFollowing">
				{{ t('social', 'Please confirm that you want to follow this account:') }}
			</p>

			<NcAvatar :url="avatarUrl" :disable-tooltip="true" :size="128" />
			<h2>{{ displayName }}</h2>
			<form v-if="!isFollowing" @submit.prevent="follow">
				<input type="submit" class="primary" value="Follow">
			</form>
			<p v-else>
				<span class="icon icon-checkmark-white" />
				{{ t('social', 'You are following this account') }}
			</p>

			<div v-if="isFollowing">
				<NcButton @click="close">
					{{ t('social', 'Close') }}
				</NcButton>
			</div>
		</div>
		<!-- Some unauthenticated user wants to follow a local account -->
		<div v-if="serverData.local">
			<p>{{ t('social', 'You are going to follow:') }}</p>
			<NcAvatar :user="serverData.local" :disable-tooltip="true" :size="128" />
			<h2>{{ displayName }}</h2>
			<form @submit.prevent="followRemote">
				<input v-model="remote" type="text" :placeholder="t('social', 'name@domain of your federation account')">
				<input type="submit" class="primary" :value="t('social', 'Continue')">
			</form>
			<p>{{ t('social', 'This step is needed as the user is probably not registered on the same server as you are. We will redirect you to your homeserver to follow this account.') }}</p>
		</div>
	</div>
	<div v-else :class="{ 'icon-loading-dark': !account }" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import axios from '@nextcloud/axios'
import accountMixins from '../mixins/accountMixins.js'
import currentuserMixin from '../mixins/currentUserMixin.js'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'OStatus',
	components: {
		NcAvatar,
	},
	mixins: [
		accountMixins,
		currentuserMixin,
	],
	data() {
		return {
			remote: '',
			account: {},
		}
	},
	computed: {
		isFollowing() {
			return this.$store.getters.isFollowingUser(this.account.id)
		},
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.account.id)
		},
		currentUser() {
			return window.oc_current_user
		},
		displayName() {
			if (typeof this.account.id === 'undefined') {
				return (this.serverData.account ? this.serverData.account : this.serverData.local)
			}

			return (this.account.name ? this.account.name : this.account.preferredUsername)
		},
	},
	beforeMount() {
		// importing server data into the store and fetching viewed account's information
		try {
			const serverData = loadState('social', 'serverData')
			if (serverData.currentUser) {
				window.oc_current_user = JSON.parse(JSON.stringify(serverData.currentUser))
			}
			this.$store.commit('setServerData', serverData)
			if (this.serverData.account && !this.serverData.local) {
				this.$store.dispatch('fetchAccountInfo', this.serverData.account).then((result) => {
					this.account = result
				})
			}
			if (this.serverData.local) {
				this.$store.dispatch('fetchPublicAccountInfo', this.serverData.local).then((result) => {
					this.account = result
				})
			}
		} catch {
			/* empty */
		}
	},
	methods: {
		follow() {
			this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.account.account }).then(() => {

			})
		},
		followRemote() {
			axios.get(generateUrl(`/apps/social/api/v1/ostatus/link/${this.serverData.local}/` + encodeURI(this.remote))).then((a) => {
				window.location = a.data.result.url
			})
		},
		close() {
			window.close()
		},
	},
}
</script>

<style scoped>
	h2, p {
		color: var(--color-primary-text);
	}
	p .icon {
		display: inline-block;
	}
	.avatardiv {
		vertical-align: -4px;
		margin-right: 3px;
		filter: drop-shadow(0 0 0.5rem #333);
		margin-top: 10px;
		margin-bottom: 20px;
	}
</style>

<style>
	.wrapper {
		margin-top: 20px;
	}
</style>
