<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="discover">
		<h2 class="discover__heading">
			{{ t('social', 'Discover') }}
		</h2>

		<div class="discover__tabs" role="tablist" :aria-label="t('social', 'Discover')">
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

		<div v-if="error" class="discover__error" role="alert">
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
				:name="t('social', 'Nothing to discover yet')"
				:description="t('social', 'Pictures people are looking at will appear here.')">
				<template #icon>
					<Compass />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else-if="active === 'tags'">
			<ul v-if="tags.length" class="discover__tags">
				<li v-for="tag in tags" :key="tag.name">
					<router-link class="discover__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
						<span class="discover__tag-name">#{{ tag.name }}</span>
						<span v-if="tagUses(tag)" class="discover__tag-count">
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

		<template v-else-if="active === 'packs'">
			<!-- one pack open: its accounts, with a follow-all -->
			<div v-if="openPack" class="discover__pack">
				<NcButton variant="tertiary" @click="closePack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('social', 'All starter packs') }}
				</NcButton>

				<h3 class="discover__pack-name">
					{{ openPack.name }}
				</h3>
				<p v-if="openPack.description" class="discover__pack-description">
					{{ openPack.description }}
				</p>

				<NcButton
					variant="primary"
					:disabled="followingPack === openPack.id || !openPack.accounts.length"
					@click="followPack(openPack.id)">
					<template #icon>
						<NcLoadingIcon v-if="followingPack === openPack.id" :size="20" />
						<AccountMultiplePlusOutline v-else :size="20" />
					</template>
					{{ t('social', 'Follow everyone') }}
				</NcButton>

				<NcLoadingIcon v-if="packLoading" class="discover__loading" :size="32" />
				<ul v-else class="discover__accounts">
					<li v-for="account in openPack.accounts" :key="account.id" class="discover__account">
						<router-link
							class="discover__account-link"
							:to="{ name: 'profile', params: { account: account.acct } }">
							<img
								class="discover__avatar"
								:src="account.avatar"
								alt=""
								loading="lazy">
							<span class="discover__account-names">
								<span class="discover__account-name">{{ account.display_name || account.username }}</span>
								<span class="discover__account-handle">@{{ account.acct }}</span>
							</span>
						</router-link>
					</li>
				</ul>

				<!-- a pack that quietly shrinks looks like one somebody wrote
				     badly; say which handles could not be reached -->
				<p v-if="openPack.unresolved && openPack.unresolved.length" class="discover__unresolved">
					{{ n('social', 'One account could not be reached: %n', 'Some accounts could not be reached: %n', openPack.unresolved.length) }}
					<span>{{ openPack.unresolved.join(', ') }}</span>
				</p>
			</div>

			<ul v-else-if="packs.length" class="discover__packs">
				<li v-for="pack in packs" :key="pack.id">
					<button type="button" class="discover__pack-card" @click="loadPack(pack.id)">
						<span class="discover__pack-card-name">{{ pack.name }}</span>
						<span class="discover__pack-card-description">{{ pack.description }}</span>
						<span class="discover__pack-card-size">
							{{ n('social', '%n account', '%n accounts', pack.size) }}
						</span>
					</button>
				</li>
			</ul>

			<NcEmptyContent
				v-else-if="!loading"
				:name="t('social', 'No starter packs')"
				:description="t('social', 'An administrator can add some with the starter_packs app setting.')">
				<template #icon>
					<AccountMultipleOutline />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<ul v-if="accounts.length" class="discover__accounts">
				<li v-for="account in accounts" :key="account.id" class="discover__account">
					<router-link
						class="discover__account-link"
						:to="{ name: 'profile', params: { account: account.acct } }">
						<img
							class="discover__avatar"
							:src="account.avatar"
							alt=""
							loading="lazy">
						<span class="discover__account-names">
							<span class="discover__account-name">{{ account.display_name || account.username }}</span>
							<span class="discover__account-handle">@{{ account.acct }}</span>
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

		<NcLoadingIcon v-if="loading && active !== 'posts'" class="discover__loading" :size="32" />
	</div>
</template>

<script>
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountMultiplePlusOutline from 'vue-material-design-icons/AccountMultiplePlusOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Compass from 'vue-material-design-icons/Compass.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Pound from 'vue-material-design-icons/Pound.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import logger from '../services/logger.js'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'

/**
 * Discover: who to follow, and what is being looked at.
 *
 * People comes first, because it is the question somebody opening this page
 * actually has. A timeline is empty until you follow somebody, and "who?" is
 * unanswerable from a graph you are not yet part of -- which is what the starter
 * packs are for: a human answer where the suggestion engine structurally has
 * none.
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
	name: 'Discover',

	components: {
		AccountMultipleOutline,
		AccountMultiplePlusOutline,
		ArrowLeft,
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
			active: 'accounts',
			loading: false,
			error: null,
			posts: [],
			tags: [],
			accounts: [],
			packs: [],
			/** which pack is open, and the accounts it resolved to */
			openPack: null,
			packLoading: false,
			followingPack: '',
			/** which tabs have been asked for, so a second visit does not re-ask */
			loaded: [],
		}
	},

	computed: {
		tabs() {
			return [
				{ id: 'accounts', name: t('social', 'People') },
				{ id: 'packs', name: t('social', 'Starter packs') },
				{ id: 'posts', name: t('social', 'Pictures') },
				{ id: 'tags', name: t('social', 'Hashtags') },
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
				} else if (tab === 'packs') {
					this.packs = await this.get('api/v1/starter_packs')
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
				logger.error('Could not load the discover page', { error, tab })
				this.error = t('social', 'Could not load this. The server may be busy.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Opens one pack, which is where the handles are resolved.
		 *
		 * The index deliberately resolves nobody — turning a handle into a
		 * profile is a WebFinger lookup and an actor fetch against somebody
		 * else's server, so the cost is paid here, when a pack is actually
		 * opened, rather than on the way past.
		 *
		 * @param {string} slug the pack's id
		 */
		async loadPack(slug) {
			this.packLoading = true
			this.error = null
			// shown at once so the name and description are on screen while the
			// accounts are still being fetched
			this.openPack = this.packs.find((pack) => pack.id === slug) ?? null
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/starter_packs/${slug}`))
				this.openPack = data
			} catch (error) {
				logger.error('Could not open the starter pack', { error, slug })
				this.error = t('social', 'Could not open this pack. The server may be busy.')
				this.openPack = null
			} finally {
				this.packLoading = false
			}
		},

		closePack() {
			this.openPack = null
			this.error = null
		},

		/**
		 * Follows everyone in the pack who can be reached.
		 *
		 * The server skips whoever it cannot reach rather than failing the lot,
		 * and answers with who it actually followed — so the message says that
		 * number rather than the number in the pack.
		 *
		 * @param {string} slug the pack's id
		 */
		async followPack(slug) {
			this.followingPack = slug
			try {
				const { data } = await axios.post(generateUrl(`apps/social/api/v1/starter_packs/${slug}/follow`))
				const followed = Array.isArray(data?.followed) ? data.followed.length : 0
				showSuccess(n('social', 'Followed %n account', 'Followed %n accounts', followed))
			} catch (error) {
				logger.error('Could not follow the starter pack', { error, slug })
				showError(t('social', 'Could not follow these accounts'))
			} finally {
				this.followingPack = ''
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
.discover {
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
