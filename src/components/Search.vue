<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper">
		<div v-if="allResults.length < 1 && hashtags.length < 1" id="emptycontent" :class="{'icon-loading': loading || remoteLoading}">
			<div v-if="!loading" class="icon-search" />
			<h2 v-if="!loading">
				{{ t('social', 'No results found') }}
			</h2>
			<p v-if="!loading">
				{{ t('social', 'There were no results for your search:') }} {{ decodeURIComponent(term) }}
			</p>
		</div>
		<div v-else>
			<h3>{{ t('social', 'Searching for') }} {{ decodeURIComponent(term) }}</h3>
			<UserEntry v-for="result in allResults" :key="result.id" :item="result" />
			<div v-if="hashtags.length > 0">
				<li v-for="tag in hashtags" :key="tag.hashtag" class="tag">
					<router-link :to="{ name: 'tags', params: {tag: tag.hashtag } }">
						<span>#{{ tag.hashtag }}</span>
						<Trend :data="trendData(tag.trend)"
							:gradient="['#17adff', '#0082c9']"
							:smooth="true"
							:width="150"
							:height="44"
							stroke-width="2" />
					</router-link>
				</li>
			</div>
		</div>
	</div>
</template>

<script>

import UserEntry from './UserEntry.vue'
import axios from '@nextcloud/axios'
import Trend from 'vuetrend'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'Search',
	components: {
		UserEntry,
		Trend,
	},
	props: {
		term: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			results: {},
			loading: false,
			remoteLoading: false,
			match: null,
			hashtags: [],
		}
	},
	computed: {
		/** @return {import('../types/Mastodon.js').Account[]} */
		allResults() {
			if (this.results.accounts) {
				if (this.results.accounts.exact) {
					return [this.results.accounts.exact]
				}
				return this.results.accounts.result
			}
			return []
		},
	},
	watch: {
		term(val) {
			this.search(val)
		},
	},
	beforeMount() {
		this.search(this.term)
	},
	methods: {
		trendData(trend) {
			const data = [
				Math.max(0, trend['10d'] - trend['3d']),
				Math.max(0, trend['3d'] - trend['1d']),
				Math.max(0, trend['1d'] - trend['12h']),
				Math.max(0, trend['12h'] - trend['1h']),
				Math.max(0, trend['1h']),
			]
			return data
		},
		search(val) {
			if (this.loading) {
				return
			}
			this.loading = true
			this.searchQuery(val).then((response) => {
				this.results = response.data.result
				this.loading = false

				if (this.results.accounts.exact !== null) {
					this.$store.commit('addAccount', { actorId: this.results.accounts.exact.id, data: this.results.accounts.exact })
				}
				this.results.accounts.result.forEach((account) => {
					this.$store.commit('addAccount', { actorId: account.id, data: account })
				})
				this.hashtags = this.results.hashtags.result
			})
		},
		accountSearch(term) {
			this.loading = true
			return axios.get(generateUrl('apps/social/api/v1/global/accounts/search?search=' + term))
		},
		searchQuery(term) {
			this.loading = true
			return axios.get(generateUrl('apps/social/api/v1/search?search=' + term))
		},
		remoteSearch(term) {
			return axios.get(generateUrl('apps/social/api/v1/global/account/info?account=' + term))
		},

		t: translate,
	},
}
</script>

<style scoped lang="scss">
	.user-entry {
		padding: 0;
	}

	h3 {
		margin-top: -3px;
		margin-left: 47px;
	}
	.tag {
		list-style-type: none;
		margin: 0;
		padding: 0;
		border-bottom: 1px solid var(--color-background-dark);

		a {
			display: flex;
			span {
				display: inline-block;
				padding: 12px;
				font-weight: 300;
				flex-grow: 1;
			}
		}
	}
</style>
