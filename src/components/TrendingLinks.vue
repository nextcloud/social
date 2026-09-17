<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="links">
		<!--
			The same control the hashtags carry, for the same reason: the server
			ranks by the window it is asked for, so an hour and ten days are two
			different lists rather than two labels on one.
		-->
		<div class="links__periods" role="tablist" :aria-label="t('social', 'Over what time')">
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

		<div v-if="error" class="links__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" :disabled="loading" @click="load(true)">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-else-if="loading && links.length === 0" class="links__loading" :size="32" />

		<ol v-else-if="links.length > 0" class="links__list" :class="{ 'links__list--stale': loading }">
			<li v-for="link in links" :key="link.url" class="links__row">
				<!--
					The headline is the thing, so it is what the row leads with
					and what the picture is there to support. Off to the page
					itself: somebody who has decided to read an article wants
					the article, and the conversation about it is one tap
					further down.
				-->
				<a
					class="links__article"
					:href="link.url"
					target="_blank"
					rel="noopener noreferrer">
					<img
						v-if="link.image"
						class="links__image"
						:src="link.image"
						alt=""
						loading="lazy"
						@error="dropImage(link)">
					<span v-else class="links__image links__image--blank" aria-hidden="true">
						<NewspaperVariantOutline :size="24" />
					</span>
					<span class="links__body">
						<span class="links__provider">{{ providerOf(link) }}</span>
						<span class="links__title">{{ link.title }}</span>
						<span v-if="link.description" class="links__description">{{ link.description }}</span>
					</span>
				</a>

				<!--
					What this app can say about an article that a reader
					elsewhere cannot: who here is talking about it. The count is
					the reason the link is on this list at all, so it is the
					label rather than a number beside one.
				-->
				<router-link
					class="links__conversation"
					:to="{ name: 'timeline', params: { type: 'link' }, query: { url: link.url } }">
					<Forum :size="16" />
					{{ sharesText(link) }}
				</router-link>
			</li>
		</ol>

		<NcEmptyContent
			v-else
			:name="t('social', 'No news yet')"
			:description="emptyDescription">
			<template #icon>
				<NewspaperVariantOutline />
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
import Forum from 'vue-material-design-icons/Forum.vue'
import NewspaperVariantOutline from 'vue-material-design-icons/NewspaperVariantOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import logger from '../services/logger.js'

/** As many as the server will rank. */
const LIMIT = 20

/**
 * The articles this instance is reading, ranked by how many posts carry them.
 *
 * `/api/v1/trends/links` has answered this since the trends were written and
 * nothing ever asked it: the instance counted every link it saw and had
 * nowhere to show the count. This is that page.
 *
 * A link here is a `PreviewCard` — the title, description and picture the
 * linked page says about itself, read once and cached — plus the number of
 * posts that carried it, which is the whole reason it is on the list.
 */
export default {
	name: 'TrendingLinks',
	components: {
		Forum,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NewspaperVariantOutline,
		Refresh,
	},

	data() {
		return {
			links: [],
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

		/**
		 * An empty list means nothing was shared in *this* window, which is a
		 * different thing from an instance where nobody posts links at all.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			return this.period === '10d'
				? t('social', 'Articles people here are sharing will appear here.')
				: t('social', 'Nothing was shared in this stretch of time. Try a longer one.')
		},
	},

	beforeMount() {
		this.load()
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
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/links'), {
					params: { limit: LIMIT, period },
				})
				// another window was chosen while this was in flight, and this
				// answer is a ranking of the one that is gone
				if (period !== this.period) {
					return
				}

				// a card with no title says nothing a reader could act on --
				// the server keeps those out, and a row without one here would
				// be a picture and a number
				this.links = (Array.isArray(data) ? data : []).filter((link) => link?.url && link?.title)
				this.error = null
			} catch (error) {
				logger.error('Could not load the trending links', { error, period })
				this.error = t('social', 'Could not load this. The server may be busy.')
			} finally {
				if (period === this.period) {
					this.loading = false
				}
			}
		},

		/**
		 * Who published it, as the page said or — failing that — where it
		 * lives. The same fallback `PostCard` makes, because the two draw the
		 * same card.
		 *
		 * @param {object} link a Trends::Link entity
		 * @return {string} the source, in words
		 */
		providerOf(link) {
			if (link.provider_name) {
				return link.provider_name
			}

			try {
				return new URL(link.url).host
			} catch {
				return link.url
			}
		},

		/**
		 * @param {object} link a Trends::Link entity
		 * @return {number} how many posts carried it in the window asked for
		 */
		shares(link) {
			if (!Array.isArray(link.history)) {
				return 0
			}

			return link.history.reduce((total, day) => total + Number(day.uses ?? 0), 0)
		},

		/** @param {object} link a Trends::Link entity @return {string} its count in words */
		sharesText(link) {
			return n('social', '%n post', '%n posts', this.shares(link))
		},

		/**
		 * A picture that will not load leaves a broken frame in the middle of
		 * the row; dropping it falls back to the placeholder, which is what a
		 * card with no picture at all looks like.
		 *
		 * @param {object} link the row whose picture failed
		 */
		dropImage(link) {
			link.image = ''
		},
	},
}
</script>

<style scoped lang="scss">
.links {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.links__periods {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.links__error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 4);
}

.links__loading {
	margin: 32px auto;
}

.links__list {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	transition: opacity .15s ease;
}

/* the previous window's ranking, while the next one is on its way */
.links__list--stale {
	opacity: .55;
}

.links__row {
	display: flex;
	flex-wrap: wrap;
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

.links__article {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
	flex: 1 1 320px;
	color: inherit;
}

.links__image {
	flex: 0 0 auto;
	width: 96px;
	height: 64px;
	object-fit: cover;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.links__image--blank {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.links__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 auto;
}

.links__provider {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.links__title {
	font-weight: bold;
	/* two lines of a headline, then an ellipsis: a long one must not push the
	   rows below it off the first screen */
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.links__description {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.links__conversation {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	flex: 0 0 auto;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	color: var(--color-primary-element);
	font-weight: bold;
	white-space: nowrap;

	&:hover,
	&:focus-visible {
		background-color: var(--color-primary-element-light);
	}
}

@media (prefers-reduced-motion: reduce) {
	.links__list {
		transition: none;
	}
}
</style>
