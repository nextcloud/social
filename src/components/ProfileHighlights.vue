<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="show" class="profile-highlights">
		<p v-if="sinceLabel" class="profile-highlights__since">
			{{ sinceLabel }}
		</p>

		<div v-if="hasPosts" class="profile-highlights__chart">
			<!-- The chart is decoration over a sentence, not instead of one:
			     a row of twelve bars is unreadable to anybody not looking at
			     it, so the same news is written out underneath and the bars
			     themselves are hidden from the accessibility tree. -->
			<ol class="profile-highlights__bars" aria-hidden="true">
				<li
					v-for="(count, index) in weeks"
					:key="index"
					class="profile-highlights__bar"
					:class="{ 'profile-highlights__bar--empty': count === 0 }"
					:style="{ '--bar-height': barHeight(count) }" />
			</ol>
			<p class="profile-highlights__summary">
				{{ summary }}
			</p>
		</div>

		<ul v-if="hashtags.length" class="profile-highlights__tags">
			<li v-for="tag in hashtags" :key="tag.name">
				<router-link
					class="profile-highlights__tag"
					:style="tagStyle(tag.name)"
					:to="{ name: 'tags', params: { tag: tag.name } }">
					#{{ tag.name }}
				</router-link>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { tagStyle } from '../utils/tagColour.js'

/** the tallest a bar is drawn, as a proportion of the chart */
const MAX_BAR = 100

/** and the shortest a week with something in it may be, so it is still seen */
const MIN_BAR = 12

/**
 * What an account is like, over and above how much of it there is.
 *
 * A profile was a name, a picture and three counts, and a count says how much
 * of something there is and nothing about what the account does with it: "412
 * posts" reads the same for somebody who wrote them all last week and somebody
 * who has been here since 2019. This says when they started, how the last
 * twelve weeks went, and what they keep writing about.
 *
 * The server answers `available: false` for a remote account, and this then
 * renders nothing at all. That is deliberate and not a failure: this instance
 * holds a remote account's posts only from whenever somebody here started
 * following them, so a chart of that would show a quiet year for an account
 * that was busy — see ProfileHighlightsService.
 */
export default {
	name: 'ProfileHighlights',

	props: {
		/** whose profile this is, as the API addresses accounts */
		accountId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			available: false,
			since: 0,
			weeks: [],
			hashtags: [],
		}
	},

	computed: {
		/**
		 * @return {boolean} whether there is anything worth a section. An
		 *         account that has posted nothing and tagged nothing gets the
		 *         "here since" line alone if it has one, and otherwise nothing
		 */
		show() {
			return this.available && (this.sinceLabel !== '' || this.hasPosts || this.hashtags.length > 0)
		},

		/** @return {boolean} whether the window holds any posts at all */
		hasPosts() {
			return this.weeks.some((count) => count > 0)
		},

		/** @return {number} the busiest week, which the bars are drawn against */
		busiest() {
			return this.weeks.reduce((highest, count) => Math.max(highest, count), 0)
		},

		/**
		 * @return {string} when this account started, as a month and a year —
		 *         a day would be more precision than the sentence wants
		 */
		sinceLabel() {
			if (!this.since) {
				return ''
			}

			const when = new Date(this.since * 1000)
			if (Number.isNaN(when.getTime())) {
				return ''
			}

			return t('social', 'Here since {date}', {
				date: when.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
			})
		},

		/**
		 * The chart in words, which is the version most readers get.
		 *
		 * @return {string} the summary
		 */
		summary() {
			const total = this.weeks.reduce((sum, count) => sum + count, 0)

			return n(
				'social',
				'%n post in the last twelve weeks',
				'%n posts in the last twelve weeks',
				total,
			)
		},
	},

	watch: {
		accountId: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,
		n,
		tagStyle,

		/**
		 * How tall one week's bar is, relative to the busiest week.
		 *
		 * A week with one post in it and a week with none must not look alike,
		 * so anything above zero gets a floor — otherwise a quiet week next to
		 * a very busy one rounds to nothing and reads as silence.
		 *
		 * @param {number} count how many posts that week held
		 * @return {string} a CSS percentage
		 */
		barHeight(count) {
			if (count === 0 || this.busiest === 0) {
				return '0%'
			}

			return `${Math.max(MIN_BAR, Math.round((count / this.busiest) * MAX_BAR))}%`
		},

		/** Reads the account's highlights, and shows nothing if it cannot. */
		async load() {
			this.available = false

			if (this.accountId === '') {
				return
			}

			try {
				const { data } = await axios.get(generateUrl(`/apps/social/api/v1/accounts/${encodeURIComponent(this.accountId)}/highlights`))

				this.available = data?.available === true
				this.since = Number(data?.since) || 0
				this.weeks = Array.isArray(data?.weeks) ? data.weeks.map((count) => Number(count) || 0) : []
				this.hashtags = Array.isArray(data?.hashtags) ? data.hashtags : []
			} catch (error) {
				// a profile is readable without this; it is an extra, and a
				// failed extra should not be an error message on somebody's page
				logger.debug('Could not read the profile highlights', { error })
				this.available = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.profile-highlights {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block: 8px;
}

.profile-highlights__since,
.profile-highlights__summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.profile-highlights__bars {
	display: flex;
	align-items: flex-end;
	gap: 3px;
	height: 28px;
	margin: 0 0 2px;
	padding: 0;
	list-style: none;
}

.profile-highlights__bar {
	flex: 1 1 0;
	min-width: 4px;
	max-width: 12px;
	height: var(--bar-height, 0%);
	border-radius: 2px 2px 0 0;
	background: var(--color-primary-element);
}

/* a week with nothing in it is still a week: a hairline keeps the twelve
   columns evenly spaced instead of leaving a gap where a quiet week was */
.profile-highlights__bar--empty {
	height: 2px;
	background: var(--color-border);
}

.profile-highlights__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.profile-highlights__tag {
	display: inline-block;
	padding: 1px 8px;
	border: 1px solid var(--tag-colour, var(--color-border));
	border-radius: var(--border-radius-pill, 16px);
	color: var(--tag-colour, var(--color-main-text));
	font-size: 12px;
	text-decoration: none;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

@media (prefers-color-scheme: dark) {
	.profile-highlights__tag {
		border-color: var(--tag-colour-dark, var(--color-border));
		color: var(--tag-colour-dark, var(--color-main-text));
	}
}

[data-themes*='dark'] .profile-highlights__tag {
	border-color: var(--tag-colour-dark, var(--color-border));
	color: var(--tag-colour-dark, var(--color-main-text));
}
</style>
