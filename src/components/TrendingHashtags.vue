<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="trending">
		<!--
			The list answers "what is busy here", so which stretch of time it
			means is part of the answer rather than a setting: the server ranks
			by the window it is asked for, so an hour and ten days are two
			different lists, not two labels on one.
		-->
		<div class="trending__periods" role="tablist" :aria-label="t('social', 'Over what time')">
			<NcButton
				v-for="option in periods"
				:key="option.id"
				role="tab"
				:aria-selected="String(option.id === period)"
				:variant="option.id === period ? 'secondary' : 'tertiary'"
				@click="choose(option.id)">
				{{ option.name }}
			</NcButton>
		</div>

		<div v-if="error" class="trending__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" :disabled="loading" @click="load(true)">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-else-if="loading && tags.length === 0" class="trending__loading" :size="32" />

		<ol v-else-if="tags.length > 0" class="trending__list" :class="{ 'trending__list--stale': loading }">
			<li v-for="(tag, index) in tags" :key="tag.name" class="trending__row">
				<router-link class="trending__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
					<span class="trending__rank" aria-hidden="true">{{ index + 1 }}</span>
					<span class="trending__body">
						<span class="trending__name">#{{ tag.name }}</span>
						<span class="trending__count">{{ countText(tag) }}</span>
						<!--
							How busy this tag is against the busiest one on the
							list, which is the only comparison the numbers here
							support. Decoration: the count above it is the fact.
						-->
						<span class="trending__bar" aria-hidden="true">
							<span class="trending__bar-fill" :style="{ width: share(tag) }" />
						</span>
					</span>
				</router-link>
				<HashtagFollowButton
					class="trending__follow"
					:tag="tag.name"
					:known="followed"
					@changed="onFollowChanged" />
			</li>
		</ol>

		<NcEmptyContent
			v-else
			:name="t('social', 'No trending hashtags')"
			:description="emptyDescription">
			<template #icon>
				<Pound />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Pound from 'vue-material-design-icons/Pound.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HashtagFollowButton from './HashtagFollowButton.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

/** As many as the server will rank. */
const LIMIT = 20

export default {
	name: 'TrendingHashtags',
	components: {
		HashtagFollowButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Pound,
		Refresh,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			tags: [],
			/** the hashtags this reader follows, or null while that is unknown */
			followed: null,
			period: '1d',
			loading: false,
			error: null,
		}
	},

	computed: {
		/** @return {object[]} the windows the server ranks by, newest first */
		periods() {
			return [
				{ id: '1h', name: t('social', 'Last hour') },
				{ id: '12h', name: t('social', 'Last 12 hours') },
				{ id: '1d', name: t('social', 'Today') },
				{ id: '3d', name: t('social', 'Last 3 days') },
				{ id: '10d', name: t('social', 'Last 10 days') },
			]
		},

		/** @return {number} how often the busiest tag on the list was used */
		busiest() {
			return this.tags.reduce((most, tag) => Math.max(most, this.uses(tag)), 0)
		},

		/**
		 * An empty list means nothing was posted in *this* window, which is a
		 * different thing from an instance where nobody uses hashtags at all.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			return this.period === '10d'
				? t('social', 'Hashtags people are using will appear here.')
				: t('social', 'Nothing was tagged in this stretch of time. Try a longer one.')
		},
	},

	beforeMount() {
		this.load()
		this.loadFollowed()
	},

	methods: {
		t,
		n,

		choose(period) {
			if (period === this.period) {
				return
			}

			this.period = period
			this.load()
		},

		/** @param {boolean} retry whether this is the reader asking again after a failure */
		async load(retry = false) {
			const period = this.period
			this.loading = true
			if (retry) {
				this.error = null
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/tags'), {
					params: { limit: LIMIT, period },
				})
				// another window was chosen while this was in flight, and this
				// answer is a ranking of the one that is gone
				if (period !== this.period) {
					return
				}

				this.tags = Array.isArray(data) ? data : []
				this.error = null
			} catch (error) {
				logger.error('Could not load the trending hashtags', { error, period })
				this.error = t('social', 'Could not load this. The server may be busy.')
			} finally {
				if (period === this.period) {
					this.loading = false
				}
			}
		},

		/**
		 * Which of these the reader already follows, in one request rather than
		 * one per tag: twenty rows on this page would otherwise be twenty
		 * lookups before anything could be drawn.
		 */
		async loadFollowed() {
			if (this.serverData.public) {
				return
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/followed_tags'), {
					params: { limit: 200 },
				})
				this.followed = (Array.isArray(data) ? data : []).map((tag) => tag.name)
			} catch (error) {
				// the buttons ask for themselves when this is not known
				logger.debug('Could not read which hashtags are followed', { error })
			}
		},

		/**
		 * Keeps the answer this page holds in step with what the buttons did,
		 * so moving between windows does not undo a follow on screen.
		 *
		 * @param {object} change what the button did: `{ tag, following }`
		 */
		onFollowChanged(change) {
			if (this.followed === null || !change?.tag) {
				return
			}

			const without = this.followed.filter((name) => name !== change.tag)
			this.followed = change.following ? [...without, change.tag] : without
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {number} how often it was used in the window asked for
		 */
		uses(tag) {
			if (!Array.isArray(tag.history)) {
				return 0
			}

			return tag.history.reduce((total, day) => total + Number(day.uses ?? 0), 0)
		},

		/** @param {object} tag a Tag entity @return {string} its count in words */
		countText(tag) {
			return n('social', '%n post', '%n posts', this.uses(tag))
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {string} its share of the busiest tag's count, as a width
		 */
		share(tag) {
			if (this.busiest === 0) {
				return '0%'
			}

			// a floor, so the quietest tag on the list is still a bar rather
			// than an empty track that reads as "no data"
			return Math.max(6, Math.round((this.uses(tag) / this.busiest) * 100)) + '%'
		},
	},
}
</script>

<style scoped lang="scss">
.trending {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.trending__periods {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.trending__error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 4);
}

.trending__loading {
	margin: 32px auto;
}

.trending__list {
	list-style: none;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--default-grid-baseline);
	transition: opacity .15s ease;
}

/* the previous window's ranking, while the next one is on its way */
.trending__list--stale {
	opacity: .55;
}

.trending__row {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&:hover,
	&:focus-within {
		background-color: var(--color-background-dark);
	}
}

.trending__tag {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
	flex: 1 1 auto;
	color: inherit;
}

.trending__rank {
	flex: 0 0 auto;
	width: 24px;
	text-align: center;
	font-weight: bold;
	font-size: 16px;
	color: var(--color-text-maxcontrast);
}

/* the top three carry the accent, which is what makes a ranking readable at a
   glance rather than a list that happens to be in an order */
.trending__row:nth-child(-n + 3) .trending__rank {
	color: var(--color-primary-element);
}

.trending__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 auto;
}

.trending__name {
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.trending__count {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
}

.trending__bar {
	display: block;
	height: 4px;
	margin-top: 2px;
	border-radius: 2px;
	background-color: var(--color-border);
	overflow: hidden;
}

.trending__bar-fill {
	display: block;
	height: 100%;
	border-radius: 2px;
	background-color: var(--color-primary-element);
	transition: width .3s ease;
}

.trending__follow {
	flex: 0 0 auto;
}

@media (prefers-reduced-motion: reduce) {
	.trending__bar-fill,
	.trending__list {
		transition: none;
	}
}
</style>
