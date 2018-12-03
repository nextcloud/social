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
	<div class="social__container">
		<div v-if="results.length < 1" id="emptycontent" :class="{'icon-loading': loading}">
			<div v-if="!loading" class="icon-search" />
			<h2 v-if="!loading">{{ t('social', 'No accounts found') }}</h2>
			<p v-if="!loading">No accounts found for {{ term }}</p>
		</div>
		<div v-if="match || results.length > 0">
			<h3>{{ t('social', 'Searching for') }} {{ term }}</h3>
			<UserEntry v-for="result in results" :key="result.id" :item="result" />
		</div>
	</div>
</template>

<style scoped>
	.user-entry {
		padding: 0;
	}

	h3 {
		margin-top: -3px;
		margin-left: 47px;
	}
</style>

<script>

import UserEntry from './UserEntry'
import axios from 'nextcloud-axios'

export default {
	name: 'Search',
	components: {
		UserEntry
	},
	props: {
		term: {
			type: String,
			default: ''
		}
	},
	data() {
		return {
			results: [],
			loading: false,
			match: null
		}
	},
	watch: {
		term(val) {
			// TODO: debounce
			this.search(val)
		}
	},
	beforeMount() {
		this.search(this.term)
	},
	methods: {
		search(val) {
			this.loading = true
			const re = /@((\w+)(@[\w.]+)?)/g
			if (val.match(re)) {
				this.remoteSearch(val).then((response) => {
					this.match = response.data.result.account
					this.accountSearch(val).then((response) => {
						this.results = response.data.result.accounts
						this.loading = false
					})
				}).catch((e) => { this.match = null })
			} else {
				this.accountSearch(val).then((response) => {
					this.results = response.data.result.accounts
					this.loading = false
				})
			}
		},
		accountSearch(term) {
			this.loading = true
			return axios.get(OC.generateUrl('apps/social/api/v1/global/accounts/search?search=' + term))
		},
		remoteSearch(term) {
			return axios.get(OC.generateUrl('apps/social/api/v1/global/account/info?account=' + term))
		}
	}
}
</script>
