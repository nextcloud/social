<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="categories.length > 0" class="discover-categories">
		<h3 class="discover-categories__heading">
			{{ t('social', 'What this server is about') }}
		</h3>
		<p class="discover-categories__lede">
			{{ t('social', 'Chosen by the people who run it, rather than counted.') }}
		</p>
		<div v-for="category in categories" :key="category.id" class="discover-categories__group">
			<h4 class="discover-categories__name">
				{{ category.name }}
			</h4>
			<ul class="discover-categories__tags">
				<li v-for="tag in category.hashtags" :key="tag">
					<router-link
						class="discover-categories__tag"
						:to="{ name: 'tags', params: { tag } }">
						#{{ tag }}
					</router-link>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/**
 * The subjects this instance says it is about.
 *
 * Trending on a small instance is four hashtags and a wedding, and an Explore
 * page that shows only that looks abandoned. What the instance would *like* to
 * be known for is a decision its administrators make, and no counter can work
 * it out — so this is curated, sits above the counted lists, and says which of
 * the two it is.
 *
 * Renders nothing at all when nobody has curated anything, rather than an
 * empty shelf: a heading over nothing is worse than no heading.
 */
export default {
	name: 'DiscoverCategories',

	data() {
		return {
			categories: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1.1/discover/categories'))
				this.categories = (data.categories ?? []).filter((category) => (category.hashtags ?? []).length > 0)
			} catch (error) {
				// an Explore page without this is still an Explore page
				logger.debug('Could not load the discover categories', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.discover-categories {
	margin-block-end: 24px;
}

.discover-categories__heading {
	margin-block-end: 0;
}

.discover-categories__lede {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}

.discover-categories__name {
	margin-block: 8px 4px;
}

.discover-categories__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.discover-categories__tag {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	text-decoration: none;

	&:hover,
	&:focus {
		background-color: var(--color-background-hover);
	}
}
</style>
