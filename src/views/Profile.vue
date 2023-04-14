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
	<div :class="{'icon-loading': !accountLoaded}" class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />

		<Composer v-if="accountInfo" :initial-mention="accountInfo.acct === currentAccount.acct ? null : accountInfo" default-visibility="direct" />

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

		// We need to update this.uid because we may have asked info for an account whose domain part was a host-meta,
		// and the account returned by the backend always uses a non host-meta'ed domain for its ID
		/** @type {[import('../types/Mastodon').Account]} */
		const response = await this.$store.dispatch(fetchMethod, this.profileAccount)
		this.uid = response.acct
		await this.$store.dispatch('fetchAccountRelationshipInfo', [response.id])
	},
}
</script>

<style scoped>

	.social__wrapper.icon-loading {
		margin-top: 50vh;
	}

</style>
