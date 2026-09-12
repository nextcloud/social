<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div :class="{'icon-loading': !accountLoaded}" class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />

		<Composer v-if="accountInfo && currentAccount && $route.name === 'profile'" :initial-mention="accountInfo.acct === currentAccount.acct ? null : accountInfo" default-visibility="direct" />

		<router-view v-if="accountLoaded && accountInfo" name="details" />
		<NcEmptyContent v-if="accountLoaded && !accountInfo"
			:name="t('social', 'User not found')"
			:description="t('social', 'Sorry, we could not find the account of {userId}', { userId: uid })">
			<template #icon>
				<img :src="emptyContentImage"
					class="icon-illustration"
					alt="">
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { generateFilePath } from '@nextcloud/router'
import ProfileInfo from './../components/ProfileInfo.vue'
import { defineAsyncComponent } from 'vue'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import logger from '../services/logger.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Profile',
	components: {
		NcEmptyContent,
		ProfileInfo,
		Composer,
	},
	mixins: [
		accountMixins,
		serverData,
	],
	data() {
		return {
			state: [],
			/** @type {string|null} */
			uid: null,
		}
	},
	computed: {
		/** @return {import('../types/Mastodon').Status[]} */
		timeline() {
			return this.$store.getters.getTimeline
		},
		/** @return {string} */
		emptyContentImage() {
			return generateFilePath('social', 'img', 'undraw/profile.svg')
		},
		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.$store.getters.currentAccount
		},
	},
	watch: {
		'$route.params.account': 'fetchProfileData',
	},
	// Start fetching account information before mounting the component
	async beforeMount() {
		this.fetchProfileData()
	},
	methods: {
		async fetchProfileData() {
			this.uid = this.$route.params.account || this.serverData.account

			if (!this.uid) return

			let fetchMethod
			if (this.serverData.public) {
				fetchMethod = 'fetchPublicAccountInfo'
			} else {
				fetchMethod = 'fetchAccountInfo'
			}

			const response = await this.$store.dispatch(fetchMethod, this.profileAccount)
			if (response) {
				this.uid = response.acct
				const infoId = this.accountInfo?.nid || this.accountInfo?.id
				if (infoId && !this.serverData.public) {
					await this.$store.dispatch('fetchAccountRelationshipInfo', [infoId])
				} else {
					logger.debug('Not asking for a relationship', { known: Boolean(infoId), isPublic: this.serverData.public })
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4);

	&.icon-loading {
		margin-top: 50vh;
	}
}
</style>
