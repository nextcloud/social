<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper social__search">
		<!-- Reply used to emit `composer-reply` on the event bus with nothing
		     on this page listening, so it did nothing at all. Kept mounted the
		     way the single-post view keeps it, because the listener lives in
		     the Composer's mounted(). -->
		<Composer v-show="composerDisplayStatus" />
		<h1 class="social__search-heading">
			{{ t('social', 'Search results for “{term}”', { term: query }) }}
		</h1>

		<div v-if="loading" class="social__search-loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('social', 'Searching …') }}</span>
		</div>

		<div v-else-if="error !== null" class="social__search-error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="search">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<NcEmptyContent v-else-if="isEmpty"
			:name="t('social', 'No results found')"
			:description="t('social', 'Nothing on this server matches “{term}”. Searching for a full handle like @user@example.org can find somebody this server has not met yet.', { term: query })">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<section v-if="accounts.length > 0" class="social__search-section">
				<h2>{{ t('social', 'People') }}</h2>
				<UserEntry v-for="account in accounts" :key="account.id" :item="account" />
			</section>

			<section v-if="hashtags.length > 0" class="social__search-section">
				<h2>{{ t('social', 'Hashtags') }}</h2>
				<ul class="social__search-tags">
					<li v-for="tag in hashtags" :key="tag.name" class="tag">
						<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
							<span>#{{ tag.name }}</span>
						</router-link>
					</li>
				</ul>
			</section>

			<section v-if="statuses.length > 0" class="social__search-section">
				<h2>{{ t('social', 'Posts') }}</h2>
				<ul class="social__search-statuses">
					<TimelineEntry v-for="status in statuses"
						:key="status.id"
						:item="status"
						type="search" />
				</ul>
			</section>
		</template>
	</div>
</template>

<script>

import { defineAsyncComponent } from 'vue'
import UserEntry from './UserEntry.vue'
import TimelineEntry from './TimelineEntry.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import logger from '../services/logger.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))

/** how long to wait for the typing to stop before asking the server */
const DEBOUNCE_MS = 300

export default {
	name: 'Search',
	components: {
		Composer,
		Magnify,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
		TimelineEntry,
		UserEntry,
	},
	props: {
		term: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			accounts: [],
			/**
			 * Ids, not the statuses themselves. Held locally they were invisible
			 * to every mutation in store/timeline.js — each one is guarded by
			 * `state.statuses[id] !== undefined` — so liking, boosting,
			 * bookmarking or pinning a result sent its request and then changed
			 * nothing on screen, and deleting one left it in the list.
			 */
			statusIds: [],
			hashtags: [],
			loading: false,
			error: null,
			debounceTimer: null,
		}
	},
	computed: {
		/** @return {string} the term, as somebody typed it rather than as a URL */
		query() {
			try {
				return decodeURIComponent(this.term)
			} catch (error) {
				return this.term
			}
		},
		/**
		 * The found posts, read back out of the store so that acting on one
		 * shows. A status the store no longer holds — a deleted one — drops
		 * out of the list on its own.
		 *
		 * @return {object[]}
		 */
		statuses() {
			return this.statusIds
				.map((id) => this.$store.getters.getStatus(id))
				.filter(Boolean)
		},
		/** @return {boolean} whether the reply composer is open */
		composerDisplayStatus() {
			return this.$store.getters.getComposerDisplayStatus
		},
		/** @return {boolean} */
		isEmpty() {
			return this.accounts.length === 0 && this.statuses.length === 0 && this.hashtags.length === 0
		},
	},
	watch: {
		// typing re-enters the route on every keystroke; one request per word
		// is enough, and re-sorting the whole store per letter was the old cost
		term: 'searchDebounced',
	},
	beforeMount() {
		this.search()
	},
	unmounted() {
		if (this.debounceTimer !== null) {
			window.clearTimeout(this.debounceTimer)
		}
	},
	methods: {
		t: translate,
		searchDebounced() {
			if (this.debounceTimer !== null) {
				window.clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = window.setTimeout(() => {
				this.debounceTimer = null
				this.search()
			}, DEBOUNCE_MS)
		},
		/**
		 * Asks the server, rather than filtering the handful of posts that
		 * happen to be loaded. `/api/v2/search` answers with accounts, posts
		 * and hashtags in one response.
		 */
		async search() {
			const term = this.query.trim()
			if (term === '') {
				this.accounts = []
				this.statusIds = []
				this.hashtags = []
				this.error = null
				return
			}

			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v2/search'), {
					params: { q: term, limit: 20 },
				})
				this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
				this.hashtags = Array.isArray(data?.hashtags) ? data.hashtags : []

				// so the follow buttons beside the results know where they stand
				for (const account of this.accounts) {
					if (account?.url) {
						this.$store.commit('addAccount', { actorId: account.url, data: account })
					}
				}

				// through the store, not local data: see `statusIds`
				const found = Array.isArray(data?.statuses) ? data.statuses : []
				for (const status of found) {
					this.$store.commit('addToStatuses', status)
				}
				this.statusIds = found.map((status) => status.id)
			} catch (error) {
				logger.error('Failed to perform the search', { error })
				this.error = translate('social', 'The search could not be run. Please try again.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
	.social__search {
		max-width: 600px;
		margin: 0 auto;
		padding: calc(var(--default-grid-baseline) * 2);
	}

	.social__search-heading {
		font-size: 20px;
		font-weight: 700;
		margin: calc(var(--default-grid-baseline) * 3) 0;
		word-break: break-word;
	}

	.social__search-section {
		margin-bottom: calc(var(--default-grid-baseline) * 6);

		h2 {
			font-size: 15px;
			font-weight: 700;
			color: var(--color-text-lighter);
			margin-bottom: calc(var(--default-grid-baseline) * 2);
		}
	}

	.social__search-loading,
	.social__search-error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 32px 20px;
		color: var(--color-text-lighter);
		text-align: center;
	}

	.social__search-tags,
	.social__search-statuses {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	.tag {
		border-bottom: 1px solid var(--color-background-dark);

		a {
			display: flex;

			span {
				display: inline-block;
				padding: 12px;
				font-weight: 500;
				flex-grow: 1;
			}
		}
	}
</style>
