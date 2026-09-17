<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="discover">
		<h2 class="discover__heading">
			{{ t('social', 'Discover') }}
		</h2>

		<!-- the same control the timelines and a profile are switched with:
		     four lists of one screen, chosen while reading rather than
		     navigated to -->
		<TimelineSwitcher
			:options="tabs"
			:value="active"
			:label="t('social', 'What to discover')"
			@update:value="select" />

		<!-- above the counted lists, because it is the answer to "what is here"
		     that a visitor can act on: curated, and saying so -->
		<DiscoverCategories />

		<div v-if="error" class="discover__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" :disabled="loading" @click="load(active, true)">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="active === 'posts' || active === 'videos'">
			<ProfileMediaGrid
				v-if="media.length || loading"
				:posts="media"
				account=""
				:loading="loading" />
			<NcEmptyContent
				v-else
				:name="t('social', 'Nothing to discover yet')"
				:description="emptyMediaDescription">
				<template #icon>
					<Compass />
				</template>
			</NcEmptyContent>
		</template>

		<!-- the hashtags tab loads, ranks and follows on its own: which stretch
		     of time it means is a question only this list has -->
		<TrendingHashtags v-else-if="active === 'tags'" />

		<!-- and the links tab likewise: the same window control over a
		     different thing being counted -->
		<TrendingLinks v-else-if="active === 'news'" />

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
							<ActorAvatar
								class="discover__avatar"
								:actor="account"
								:size="40"
								:link="false" />
							<span class="discover__account-names">
								<span class="discover__account-name">{{ account.display_name || account.username }}</span>
								<span class="discover__account-handle">@{{ account.acct }}</span>
							</span>
						</router-link>
					</li>
				</ul>

				<!-- a pack that quietly shrinks looks like one somebody wrote
				     badly; say which handles could not be reached, and why it
				     is worth trying again. `%n` used to be the *count* and the
				     handles were appended after it, so this read "Some
				     accounts could not be reached: 2 a@b, c@d" -->
				<div v-if="openPack.unresolved && openPack.unresolved.length" class="discover__unresolved">
					<p>
						{{ n('social',
							'One account could not be reached from this server:',
							'%n accounts could not be reached from this server:',
							openPack.unresolved.length) }}
						<span class="discover__unresolved-handles">{{ openPack.unresolved.join(', ') }}</span>
					</p>
					<p class="discover__unresolved-why">
						{{ t('social', 'Their server did not answer. That is often a passing rate limit — and permanent if this Nextcloud cannot be reached from the internet, because a server that only answers signed requests has to fetch this one’s key to check the signature.') }}
					</p>
					<NcButton variant="tertiary" :disabled="packLoading" @click="loadPack(openPack.id)">
						<template #icon>
							<NcLoadingIcon v-if="packLoading" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ t('social', 'Try again') }}
					</NcButton>
				</div>
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
						<ActorAvatar
							class="discover__avatar"
							:actor="account"
							:size="40"
							:link="false" />
						<span class="discover__account-names">
							<span class="discover__account-name">{{ account.display_name || account.username }}</span>
							<span class="discover__account-handle">@{{ account.acct }}</span>
						</span>
						<!-- named in the fediverse field of a profile on this
						     Nextcloud: the one thing about this account no other
						     server could know -->
						<span v-if="isColleague(account)" class="discover__colleague">
							{{ t('social', 'On your Nextcloud') }}
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

		<NcLoadingIcon
			v-if="loading && active !== 'posts' && active !== 'videos'"
			class="discover__loading"
			:size="32" />
	</div>
</template>

<script>
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import ActorAvatar from '../components/ActorAvatar.vue'
import AccountMultiplePlusOutline from 'vue-material-design-icons/AccountMultiplePlusOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Compass from 'vue-material-design-icons/Compass.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import PlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import NewspaperVariantOutline from 'vue-material-design-icons/NewspaperVariantOutline.vue'
import ProfileMediaGrid from '../components/ProfileMediaGrid.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import DiscoverCategories from '../components/DiscoverCategories.vue'
import TrendingHashtags from '../components/TrendingHashtags.vue'
import TrendingLinks from '../components/TrendingLinks.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '../services/toast.js'
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
		ActorAvatar,
		AccountMultiplePlusOutline,
		ArrowLeft,
		Compass,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ProfileMediaGrid,
		TimelineSwitcher,
		DiscoverCategories,
		TrendingHashtags,
		TrendingLinks,
		Refresh,
	},

	data() {
		return {
			active: 'accounts',
			loading: false,
			error: null,
			posts: [],
			videos: [],
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
		/**
		 * Pictures and Videos are one grid asked two questions, so the template
		 * reads whichever list the tab on screen stands for rather than naming
		 * one of them.
		 *
		 * @return {object[]} the posts to draw
		 */
		media() {
			return (this.active === 'videos') ? this.videos : this.posts
		},

		/** @return {string} what an empty grid should say it is empty of */
		emptyMediaDescription() {
			return (this.active === 'videos')
				? t('social', 'Videos people are watching will appear here.')
				: t('social', 'Pictures people are looking at will appear here.')
		},

		tabs() {
			// no `to` on any of them: the switcher pushes a route where an
			// option has one and hands the pick back where it does not, and
			// which of these four is on screen is this page's own state
			return [
				{ value: 'accounts', label: t('social', 'People'), icon: AccountMultipleOutline },
				{ value: 'packs', label: t('social', 'Starter packs'), icon: AccountMultiplePlusOutline },
				{ value: 'posts', label: t('social', 'Pictures'), icon: ImageMultiple },
				{ value: 'videos', label: t('social', 'Videos'), icon: PlayBoxMultiple },
				{ value: 'tags', label: t('social', 'Hashtags'), icon: Pound },
				// last, and the only one of these that is about what is being read
				// rather than who is here: the sidebar's News entry is where a
				// reader goes for the posts, and this is the ranking behind it
				{ value: 'news', label: t('social', 'News'), icon: NewspaperVariantOutline },
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
			// the hashtags and the links ask for themselves, per window
			if (tab === 'tags' || tab === 'news' || (!force && this.loaded.includes(tab))) {
				return
			}

			this.loading = true
			this.error = null
			try {
				if (tab === 'posts') {
					// `media` is this app's own parameter: without it the route
					// answers with every post that has an attachment, which is
					// Pixelfed's meaning of it and would put the same video in
					// both grids
					this.posts = await this.get('api/v2/discover/posts?media=image')
				} else if (tab === 'videos') {
					this.videos = await this.get('api/v2/discover/posts?media=video')
				} else if (tab === 'packs') {
					this.packs = await this.get('api/v1/starter_packs')
				} else {
					// the account, with where the suggestion came from kept on
					// it: a colleague is worth saying so
					this.accounts = await this.get('api/v2/suggestions')
						.then((suggestions) => suggestions
							.map((suggestion) => suggestion.account
								? { ...suggestion.account, sources: suggestion.sources ?? [] }
								: suggestion)
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
		 * Whether a suggestion came from a profile on this Nextcloud. Mastodon
		 * calls that source `featured`; see Suggestion::SOURCE_COLLEAGUES.
		 *
		 * @param {object} account the suggested account, with its `sources`
		 * @return {boolean}
		 */
		isColleague(account) {
			return Array.isArray(account.sources) && account.sources.includes('featured')
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

	&__error {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 4);
	}

	/*
	 * The pack list had markup and no rules at all: a `<button>` with three
	 * spans in it, which the browser drew as one centred, bold, accent-coloured
	 * line — name, description and count run together — and which read as a
	 * page that had failed to load rather than as a list of packs.
	 */
	&__packs {
		list-style: none;
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__pack-card {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		width: 100%;
		height: 100%;
		/* a button in a card's clothing: the defaults it arrives with are for a
		   word on a control, not for a paragraph in a tile */
		text-align: start;
		font-size: inherit;
		font-weight: normal;
		color: var(--color-main-text);
		background-color: var(--color-background-hover);
		border: none;
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		cursor: pointer;
		transition: background-color .15s ease;

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-dark);
		}
	}

	&__pack-card-name {
		font-weight: bold;
		font-size: 1.05em;
	}

	&__pack-card-description {
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__pack-card-size {
		margin-top: auto;
		padding-top: var(--default-grid-baseline);
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);
	}

	/* and the pack itself, once one is open */
	&__pack {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__pack-name {
		margin: 0;
	}

	&__pack-description {
		margin: 0;
		max-width: 70ch;
		color: var(--color-text-maxcontrast);
		line-height: 1.5;
	}

	&__unresolved {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 0.85em);

		span {
			display: block;
		}
	}

	&__accounts {
		list-style: none;
		width: 100%;
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

	&__colleague {
		margin-inline-start: auto;
		flex: none;
		padding: 2px 8px;
		border-radius: 999px;
		font-size: 12px;
		color: var(--color-primary-element-light-text);
		background: var(--color-primary-element-light);
	}

	&__loading {
		margin: 24px auto;
	}
}

/* the one thing on this page that moves, and a reader may have asked for
   nothing to */
@media (prefers-reduced-motion: reduce) {
	.discover__pack-card {
		transition: none;
	}
}
</style>
