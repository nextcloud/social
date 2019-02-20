<template>
	<div v-if="accountInfo">
		<div v-if="!serverData.local">
			<h2>{{ t('social', 'Follow on Nextcloud Social') }}</h2>
			<p>{{ t('social', 'Hello') }} <avatar :user="currentUser.uid" :size="16" />{{ currentUser.displayName }}</p>
			<p v-if="!isFollowing">
				{{ t('social', 'Please confirm that you want to follow this account:') }}
			</p>

			<avatar :url="avatarUrl" :disable-tooltip="true" :size="128" />
			<h2>{{ displayName }}</h2>
			<form v-if="!isFollowing" @submit.prevent="follow">
				<input type="submit" class="primary" value="Follow">
			</form>
			<p v-else>
				<span class="icon icon-checkmark-white" />
				{{ t('social', 'You are following this account') }}
			</p>

			<div v-if="isFollowing">
				<button @click="close">
					{{ t('social', 'Close') }}
				</button>
			</div>
		</div>
		<div v-if="serverData.local">
			<p>{{ t('social', 'You are going to follow:') }}</p>
			<avatar :user="serverData.local" :disable-tooltip="true" :size="128" />
			<h2>{{ displayName }}</h2>
			<form @submit.prevent="followRemote">
				<input v-model="remote" type="text" :placeholder="t('social', 'name@domain of your federation account')">
				<input type="submit" class="primary" :value="t('social', 'Continue')">
			</form>
			<p>{{ t('social', 'This step is needed as the user is probably not registered on the same server as you are. We will redirect you to your homeserver to follow this account.') }}</p>
		</div>
	</div>
	<div v-else :class="{ 'icon-loading-dark': !accountInfo }" />
</template>

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

<script>
import { Avatar } from 'nextcloud-vue'
import axios from 'nextcloud-axios'
import currentuserMixin from './../mixins/currentUserMixin'

export default {
	name: 'App',
	components: {
		Avatar
	},
	mixins: [currentuserMixin],
	data() {
		return {
			remote: ''
		}
	},
	computed: {
		isFollowing() {
			return this.$store.getters.isFollowingUser(this.account)
		},
		account() {
			return this.serverData.account
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.accountInfo.id)
		},
		accountInfo: function() {
			return this.$store.getters.getAccount(this.serverData.account)
		},
		currentUser() {
			return window.oc_current_user
		},
		displayName() {
			if (typeof this.accountInfo.name !== 'undefined' && this.accountInfo.name !== '') {
				return this.accountInfo.name
			}
			return this.account
		}
	},
	beforeMount: function() {
		// importing server data into the store
		const serverDataElmt = document.getElementById('serverData')
		if (serverDataElmt !== null) {
			const serverData = JSON.parse(document.getElementById('serverData').dataset.server)
			if (serverData.currentUser) {
				window.oc_current_user = JSON.parse(JSON.stringify(serverData.currentUser))
			}
			this.$store.commit('setServerData', serverData)
			if (this.serverData.account && !this.serverData.local) {
				this.$store.dispatch('fetchAccountInfo', this.serverData.account)
			}
			if (this.serverData.local) {
				this.$store.dispatch('fetchPublicAccountInfo', this.serverData.local)
			}

		}
	},
	methods: {
		follow() {
			this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.account }).then(() => {

			})
		},
		followRemote() {
			axios.get(OC.generateUrl(`/apps/social/api/v1/ostatus/link/${this.serverData.local}/` + encodeURI(this.remote))).then((a) => {
				window.location = a.data.result.url
			})
		},
		close() {
			window.close()
		}
	}
}
</script>
