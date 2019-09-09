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
		<profile-info v-if="accountLoaded && accountInfo" :uid="uid" />
		<!-- TODO: we have no details, timeline and follower list for non-local accounts for now -->
		<router-view v-if="accountLoaded && accountInfo && accountInfo.local" name="details" />
		<empty-content v-if="accountLoaded && !accountInfo" :item="emptyContentData" />
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
import serverData from '../mixins/serverData'

export default {
	name: 'Profile',
	components: {
		EmptyContent,
		ProfileInfo
	},
	mixins: [
		serverData
	],
	data() {
		return {
			state: [],
			uid: null
		}
	},
	computed: {
		profileAccount() {
			return (this.uid.indexOf('@') === -1) ? this.uid + '@' + this.hostname : this.uid
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		accountInfo: function() {
			return this.$store.getters.getAccount(this.profileAccount)
		},
		accountLoaded() {
			return this.$store.getters.accountLoaded(this.profileAccount)
		},
		emptyContentData() {
			return {
				image: 'img/undraw/profile.svg',
				title: t('social', 'User not found'),
				description: t('social', 'Sorry, we could not find the account of {userId}', { userId: this.uid })
			}
		}
	},
	beforeMount() {
		this.uid = this.$route.params.account
		if (this.serverData.public) {
			this.$store.dispatch('fetchPublicAccountInfo', this.uid)
		} else {
			this.$store.dispatch('fetchAccountInfo', this.profileAccount)
		}
	},
	methods: {
	}
}
</script>
