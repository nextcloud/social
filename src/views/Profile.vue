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
	<div :class="{'icon-loading': !accountLoaded(uid)}" class="social__wrapper">
		<profile-info v-if="accountLoaded(uid) && accountInfo(uid)" :uid="uid" />
		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<router-view v-if="accountLoaded(uid) && accountInfo(uid) && accountInfo(uid).local" name="details" />
		<empty-content v-if="accountLoaded(uid) && !accountInfo(uid)" :item="emptyContentData" />
	</div>
</template>

<style scoped>

	.social__wrapper.icon-loading {
		margin-top: 50vh;
	}

</style>

<script>
import ProfileInfo from './../components/ProfileInfo.vue'
import EmptyContent from '../components/EmptyContent.vue'
import accountMixins from '../mixins/accountMixins'
import serverData from '../mixins/serverData'

export default {
	name: 'Profile',
	components: {
		EmptyContent,
		ProfileInfo
	},
	mixins: [
		accountMixins,
		serverData
	],
	data() {
		return {
			state: [],
			uid: null
		}
	},
	computed: {
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		emptyContentData() {
			return {
				image: 'img/undraw/profile.svg',
				title: t('social', 'User not found'),
				description: t('social', 'Sorry, we could not find the account of {userId}', { userId: this.uid })
			}
		}
	},
	// Start fetching account information before mounting the component
	beforeMount() {
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
		this.$store.dispatch(fetchMethod, this.profileAccount(this.uid)).then((response) => {
			this.uid = response.account
		})
	}
}
</script>
