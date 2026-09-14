<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav v-if="tags.length" class="featured-tags" :aria-label="t('social', 'Hashtags this account features')">
		<ul class="featured-tags__list">
			<li v-for="tag in tags" :key="tag.name">
				<router-link
					class="featured-tags__tag"
					:style="tagStyle(tag.name)"
					:to="{ name: 'tags', params: { tag: tag.name } }">
					<span class="featured-tags__name">#{{ tag.name }}</span>
					<span v-if="tag.statuses_count > 0" class="featured-tags__count">{{ tag.statuses_count }}</span>
				</router-link>
			</li>
		</ul>
	</nav>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { tagStyle } from '../utils/tagColour.js'

/**
 * The hashtags an account pins to its own profile.
 *
 * The server has answered `/api/v1/accounts/{account}/featured_tags` for a
 * while and clients could read it, but nothing on this app's own profile page
 * showed them — so an account could feature a tag here and only somebody using
 * a third-party client would ever see it.
 *
 * A featured tag is a claim an account makes about itself, which is why it
 * belongs at the top of the profile next to the bio rather than among the
 * statistics: it says what this account is about, in its own words.
 *
 * Each tag wears the colour it wears everywhere else (utils/tagColour.js), so
 * `#design` on a profile is recognisably the `#design` on the tag page it
 * leads to.
 */
export default {
	name: 'FeaturedTags',

	props: {
		/** whose profile this is, as the API addresses accounts */
		accountId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			tags: [],
		}
	},

	watch: {
		accountId: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,
		tagStyle,

		/** Reads the account's featured tags, and shows nothing if it cannot. */
		async load() {
			this.tags = []

			if (this.accountId === '') {
				return
			}

			try {
				const { data } = await axios.get(generateUrl(`/apps/social/api/v1/accounts/${encodeURIComponent(this.accountId)}/featured_tags`))

				this.tags = Array.isArray(data) ? data.filter((tag) => typeof tag?.name === 'string' && tag.name !== '') : []
			} catch (error) {
				// a profile is readable without these; a failed extra is not
				// worth an error message on somebody's page
				logger.debug('Could not read the featured tags', { error })
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.featured-tags__list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 6px 0;
	padding: 0;
	list-style: none;
}

.featured-tags__tag {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 1px 9px;
	border: 1px solid var(--tag-colour, var(--color-border));
	border-radius: var(--border-radius-pill, 16px);
	color: var(--tag-colour, var(--color-main-text));
	font-size: 13px;
	text-decoration: none;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.featured-tags__count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-variant-numeric: tabular-nums;
}

@media (prefers-color-scheme: dark) {
	.featured-tags__tag {
		border-color: var(--tag-colour-dark, var(--color-border));
		color: var(--tag-colour-dark, var(--color-main-text));
	}
}

[data-themes*='dark'] .featured-tags__tag {
	border-color: var(--tag-colour-dark, var(--color-border));
	color: var(--tag-colour-dark, var(--color-main-text));
}
</style>
