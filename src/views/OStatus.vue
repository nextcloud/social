<template>
	<div>
		<h2>{{ t('social', 'Follow on Nextcloud Social') }}</h2>
		<div v-if="accountInfo">
			<p>{{ t('social', 'Hello') }} <avatar :user="currentUser.uid" :size="16" />{{ currentUser.displayName }}</p>
			<p>{{ t('social', 'Please confirm that you want to follow this account:') }}</p>

			<avatar :url="avatarUrl" :disable-tooltip="true" :size="128" />
			<h2>{{ displayName }}</h2>
			<form @submit.prevent="follow">
				<input type="text" :value="serverData.account">
				<input type="submit" class="primary" value="Follow">
			</form>
		</div>
		<div :class="{ 'icon-loading-dark': !accountInfo }" />
	</div>
</template>

<style scoped>
	h2, p {
		color: var(--color-primary-text);
	}
	.avatardiv {
		vertical-align: -4px;
		margin-right: 3px;
	}
</style>

<script>
import { Avatar } from 'nextcloud-vue'
import currentuserMixin from './../mixins/currentUserMixin'

export default {
	name: 'App',
	components: {
		Avatar
	},
	mixins: [currentuserMixin],
	computed: {
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
			window.oc_current_user = JSON.parse(JSON.stringify(serverData.currentUser))
			this.$store.commit('setServerData', serverData)
			this.$store.dispatch('fetchAccountInfo', this.serverData.account)

		}
	},
	methods: {
		follow() {
			this.$store.dispatch('followAccount', { currentAccount: this.cloudId, accountToFollow: this.account }).then(() => {
				window.location = this.serverData.url
			})
		}
	}
}
</script>
