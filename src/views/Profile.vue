<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div :class="{'icon-loading': !accountLoaded}" class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />

		<Composer v-if="accountInfo && $route.name === 'profile'" :initial-mention="accountInfo.acct === currentAccount.acct ? null : accountInfo" default-visibility="direct" />

		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<router-view v-if="accountLoaded && accountInfo && isLocal" name="details" />
		<NcEmptyContent v-if="accountLoaded && !accountInfo"
			:title="t('social', 'User not found')"
			:description="t('social', 'Sorry, we could not find the account of {userId}', { userId: uid })">
			<template #icon>
				<img :src="emptyContentImage"
					class="icon-illustration"
					alt="">
			</template>
		</NcEmptyContent>
		<div v-if="errorMessage" class="error-message">
			{{ errorMessage }}
		</div>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import { generateFilePath } from '@nextcloud/router'
import ProfileInfo from './../components/ProfileInfo.vue'
import Composer from './../components/Composer/Composer.vue'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'

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
			errorMessage: '', // Add error message state
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
	// Start fetching account information before mounting the component
	async beforeMount() {
		this.uid = this.$route.params.account || this.serverData.account

		// Are we authenticated?
		let fetchMethod = ''
		if (this.serverData.public) {
			fetchMethod = 'fetchPublicAccountInfo'
		} else {
			fetchMethod = 'fetchAccountInfo'
		}

		try {
			// We need to update this.uid because we may have asked info for an account whose domain part was a host-meta,
			// and the account returned by the backend always uses a non host-meta'ed domain for its ID
			/** @type {[import('../types/Mastodon').Account]} */
			const response = await this.$store.dispatch(fetchMethod, this.profileAccount)
			this.uid = response.acct
			await this.$store.dispatch('fetchAccountRelationshipInfo', [this.accountInfo.id])
		} catch (error) {
			console.error('Failed to fetch account information:', error)
			this.errorMessage = `Failed to fetch account information: ${error.message}` // Set error message on failure
		}
	},
}
</script>

<style scoped>

	.social__wrapper.icon-loading {
		margin-top: 50vh;
	}

	.error-message {
		color: red;
		margin-top: 10px;
	}

</style>
