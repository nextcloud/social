<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="explore">
		<h2 class="explore__heading">
			{{ t('social', 'Explore') }}
		</h2>

		<div class="explore__tabs" role="tablist" :aria-label="t('social', 'Explore')">
			<NcButton
				v-for="tab in tabs"
				:key="tab.id"
				role="tab"
				:aria-selected="String(tab.id === active)"
				:variant="tab.id === active ? 'secondary' : 'tertiary'"
				@click="select(tab.id)">
				{{ tab.name }}
			</NcButton>
		</div>

		<div v-if="error" class="explore__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" :disabled="loading" @click="load(active, true)">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="active === 'posts'">
			<ProfileMediaGrid
				v-if="posts.length || loading"
				:posts="posts"
				account=""
				:loading="loading" />
			<NcEmptyContent
				v-else
				:name="t('social', 'Nothing to explore yet')"
				:description="t('social', 'Pictures people are looking at will appear here.')">
				<template #icon>
					<Compass />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else-if="active === 'tags'">
			<ul v-if="tags.length" class="explore__tags">
				<li v-for="tag in tags" :key="tag.name">
					<router-link class="explore__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
						<span class="explore__tag-name">#{{ tag.name }}</span>
						<span v-if="tagUses(tag)" class="explore__tag-count">
							{{ n('social', '%n post', '%n posts', tagUses(tag)) }}
						</span>
					</router-link>
				</li>
			</ul>
			<NcEmptyContent
				v-else-if="!loading"
				:name="t('social', 'No trending hashtags')"
				:description="t('social', 'Hashtags people are using will appear here.')">
				<template #icon>
					<Pound />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<ul v-if="accounts.length" class="explore__accounts">
				<li v-for="account in accounts" :key="account.id" class="explore__account">
					<router-link
						class="explore__account-link"
						:to="{ name: 'profile', params: { account: account.acct } }">
						<img
							class="explore__avatar"
							:src="account.avatar"
							alt=""
							loading="lazy">
						<span class="explore__account-names">
							<span class="explore__account-name">{{ account.display_name || account.username }}</span>
							<span class="explore__account-handle">@{{ account.acct }}</span>
						</span>
					</router-link>
				</li>
			</ul>
			<NcEmptyContent
				v-else-if="!loading"
				:name="t('social', 'Nobody to suggest yet')"
				:description="t('social', 'Accounts this server knows about will appear here.')">
				<template #icon>
					<AccountMultipleOutline />
				</template>
			</NcEmptyContent>
		</template>

		<NcLoadingIcon v-if="loading && active !== 'posts'" class="explore__loading" :size="32" />
	</div>
</template>

<script>
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import Compass from 'vue-material-design-icons/Compass.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Pound from 'vue-material-design-icons/Pound.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import axios from '@nextcloud/axios'
import logger from '../services/logger.js'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'

/**
 * Explore: the pictures being looked at, the hashtags being used, and the
 * accounts this server knows about.
 *
 * Every one of these was already answered by the server and none of them was
 * reachable from the interface -- the whole discovery backend existed and there
 * was no page that asked it anything. This is that page.
 *
 * Each tab is loaded when it is first opened and then kept, so flicking between
 * them does not re-ask. A failure is its own state with a retry, rather than an
 * empty list that cannot be told apart from "there is nothing here".
 */
export default {
	name: 'Explore',

	components: {
		AccountMultipleOutline,
		Compass,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Pound,
		ProfileMediaGrid,
		Refresh,
	},

	data() {
		return {
			active: 'posts',
			loading: false,
			error: null,
			posts: [],
			tags: [],
			accounts: [],
			/** which tabs have been asked for, so a second visit does not re-ask */
			loaded: [],
		}
	},

	computed: {
		tabs() {
			return [
				{ id: 'posts', name: t('social', 'Pictures') },
				{ id: 'tags', name: t('social', 'Hashtags') },
				{ id: 'accounts', name: t('social', 'Accounts') },
			]
		},
	},

	beforeMount() {
		this.load(this.active)
	},

	methods: {
		t,
		n,

		select(tab) {
			this.active = tab
			this.error = null
			this.load(tab)
		},

		/**
		 * @param {string} tab which tab to fill
		 * @param {boolean} force ask again even if it has been asked once
		 */
		async load(tab, force = false) {
			if (!force && this.loaded.includes(tab)) {
				return
			}

			this.loading = true
			this.error = null
			try {
				if (tab === 'posts') {
					this.posts = await this.get('api/v2/discover/posts')
				} else if (tab === 'tags') {
					this.tags = await this.get('api/v1/trends/tags')
				} else {
					this.accounts = await this.get('api/v2/suggestions')
						.then((suggestions) => suggestions
							.map((suggestion) => suggestion.account ?? suggestion)
							.filter((account) => account && account.acct))
				}

				if (!this.loaded.includes(tab)) {
					this.loaded.push(tab)
				}
			} catch (error) {
				logger.error('Could not load the explore page', { error, tab })
				this.error = t('social', 'Could not load this. The server may be busy.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} path the route to ask, without the app prefix
		 * @return {Promise<Array>} whatever it answered, as an array
		 */
		async get(path) {
			const { data } = await axios.get(generateUrl(`apps/social/${path}`))

			return Array.isArray(data) ? data : []
		},

		/**
		 * Mastodon's tag entity counts uses per day in `history`; this server
		 * answers the same shape.
		 *
		 * @param {object} tag a trending tag
		 * @return {number} how many posts used it in the window
		 */
		tagUses(tag) {
			if (!Array.isArray(tag.history)) {
				return 0
			}

			return tag.history.reduce((total, day) => total + Number(day.uses ?? 0), 0)
		},
	},
}
</script>

<style scoped lang="scss">
.explore {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 1000px;
	margin-inline: auto;

	&__heading {
		margin-bottom: calc(var(--default-grid-baseline) * 2);
	}

	&__tabs {
		display: flex;
		gap: var(--default-grid-baseline);
		margin-bottom: calc(var(--default-grid-baseline) * 3);
		flex-wrap: wrap;
	}

	&__error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 4);
	}

	&__tags {
		list-style: none;
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
	}

	&__tag {
		display: flex;
		flex-direction: column;
		padding: calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-large);
		background-color: var(--color-background-hover);

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-dark);
		}
	}

	&__tag-count {
		font-size: var(--font-size-small, 0.85em);
		color: var(--color-text-maxcontrast);
	}

	&__accounts {
		list-style: none;
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
		gap: var(--default-grid-baseline);
	}

	&__account-link {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-large);

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}
	}

	&__avatar {
		width: 40px;
		height: 40px;
		border-radius: 50%;
		object-fit: cover;
		flex: 0 0 auto;
	}

	&__account-names {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__account-name {
		font-weight: bold;
	}

	&__account-name,
	&__account-handle {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__account-handle {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
	}

	&__loading {
		margin: 24px auto;
	}
}
</style>
