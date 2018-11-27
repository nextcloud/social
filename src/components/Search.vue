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
	<div>
		<h3>{{ t('social', 'Search') }} {{ term }}</h3>
		<div v-if="results.length < 1" :class="{'icon-loading': loading}" class="emptycontent emptycontent-search" />
		<div>
			<UserEntry v-if="match" :item="match" />
			<UserEntry v-for="result in results" :key="result.id" :item="result" />
		</div>
	</div>
</template>

<style scoped>

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
			this.loading = true
			this.accountSearch(val).then((response) => {
				this.results = response.data.result.accounts
				this.loading = false
			})
			const re = /@((\w+)(@[\w.]+)?)/g
			if (val.match(re)) {
				this.remoteSearch(val).then((response) => {
					this.match = response.data.result.account
				}).catch((e) => { this.match = null })
			}
		}
	},
	methods: {
		accountSearch(term) {
			this.loading = true
			return axios.get(OC.generateUrl('apps/social/api/v1/accounts/search?search=' + term))
		},
		remoteSearch(term) {
			return axios.get(OC.generateUrl('apps/social/api/v1/account/info?account=' + term))
		}
	}
}
</script>
