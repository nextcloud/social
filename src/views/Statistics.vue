<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="stats">
		<h2>{{ t('social', 'Statistics') }}</h2>
		<p class="stats__hint">
			{{ t('social', 'What you have posted here, and what came back. Counted from this server when you opened the page, so it is never a stale number.') }}
		</p>

		<NcLoadingIcon v-if="loading" class="stats__loading" :size="44" />

		<div v-else-if="error" class="stats__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<template v-else-if="stats">
			<!-- who -->
			<section class="stats__card">
				<h3>
					<IconAccount :size="20" />
					{{ '@' + stats.account.acct }}
				</h3>
				<p v-if="joined" class="stats__note">
					{{ joined }}
				</p>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.posts.total) }}</strong>
						<span>{{ n('social', 'post', 'posts', stats.posts.total) }}</span>
					</li>
					<li>
						<strong>{{ number(stats.account.followers) }}</strong>
						<span>{{ n('social', 'follower', 'followers', stats.account.followers) }}</span>
					</li>
					<li>
						<strong>{{ number(stats.account.following) }}</strong>
						<span>{{ t('social', 'following') }}</span>
					</li>
				</ul>
			</section>

			<!-- how the posts are doing -->
			<section class="stats__card">
				<h3>
					<IconHeart :size="20" />
					{{ t('social', 'How your posts are doing') }}
				</h3>
				<ul class="stats__figures">
					<li>
						<strong>{{ number(stats.engagement.likes) }}</strong>
						<span>{{ t('social', 'likes received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.likes_per_post) }) }}</em>
					</li>
					<li>
						<strong>{{ number(stats.engagement.boosts) }}</strong>
						<span>{{ t('social', 'boosts received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.boosts_per_post) }) }}</em>
					</li>
					<li>
						<strong>{{ number(stats.engagement.replies) }}</strong>
						<span>{{ t('social', 'replies received') }}</span>
						<em>{{ t('social', '{n} per post', { n: decimal(stats.engagement.replies_per_post) }) }}</em>
					</li>
				</ul>
				<p class="stats__note">
					{{ t('social', 'A floor rather than a total: a like on a server that never told this one about it cannot be counted anywhere.') }}
				</p>
			</section>

			<!-- the best of them -->
			<section v-if="stats.best.length" class="stats__card">
				<h3>
					<IconTrophy :size="20" />
					{{ t('social', 'Your best posts') }}
				</h3>
				<ol class="stats__best">
					<li v-for="post in stats.best" :key="post.id">
						<router-link :to="{ name: 'single-post', params: { account: stats.account.acct, id: post.id } }">
							<span class="stats__best-text">{{ post.excerpt || t('social', '(no text)') }}</span>
							<span class="stats__best-counts">
								<span><IconHeart :size="14" /> {{ number(post.likes) }}</span>
								<span><IconRepeat :size="14" /> {{ number(post.boosts) }}</span>
								<span><IconReply :size="14" /> {{ number(post.replies) }}</span>
							</span>
						</router-link>
					</li>
				</ol>
			</section>

			<!-- when -->
			<section class="stats__card">
				<h3>
					<IconCalendar :size="20" />
					{{ t('social', 'When you post') }}
				</h3>
				<h4>{{ t('social', 'Over the last twelve months') }}</h4>
				<ul class="stats__bars stats__bars--months">
					<li v-for="month in months" :key="month.key" :title="monthTitle(month)">
						<span class="stats__bar" :style="{ '--height': month.height }" />
						<span class="stats__bar-label">{{ month.label }}</span>
					</li>
				</ul>
				<h4>{{ t('social', 'By hour of the day (UTC)') }}</h4>
				<ul class="stats__bars stats__bars--hours">
					<li v-for="hour in hours" :key="hour.key" :title="hourTitle(hour)">
						<span class="stats__bar" :style="{ '--height': hour.height }" />
						<span class="stats__bar-label">{{ hour.label }}</span>
					</li>
				</ul>
			</section>

			<!-- what -->
			<section class="stats__card">
				<h3>
					<IconShape :size="20" />
					{{ t('social', 'What you post') }}
				</h3>
				<dl class="stats__rows">
					<div v-for="row in composition" :key="row.key" class="stats__row">
						<dt>{{ row.label }}</dt>
						<dd>
							<span class="stats__meter" :style="{ '--share': row.share }" />
							<span class="stats__row-value">{{ number(row.count) }}</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- hashtags -->
			<section v-if="stats.hashtags.length" class="stats__card">
				<h3>
					<IconPound :size="20" />
					{{ t('social', 'What you write about') }}
				</h3>
				<ul class="stats__tags">
					<li v-for="tag in stats.hashtags" :key="tag.name">
						<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
							#{{ tag.name }}
							<span class="stats__tag-count">{{ number(tag.count) }}</span>
						</router-link>
					</li>
				</ul>
			</section>

			<p class="stats__window">
				{{ window }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccount from 'vue-material-design-icons/AccountCircle.vue'
import IconCalendar from 'vue-material-design-icons/CalendarBlank.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconReply from 'vue-material-design-icons/Reply.vue'
import IconRepeat from 'vue-material-design-icons/Repeat.vue'
import IconShape from 'vue-material-design-icons/ShapeOutline.vue'
import IconTrophy from 'vue-material-design-icons/Trophy.vue'
import { n, t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/**
 * The reader's own numbers.
 *
 * Everything here is drawn with CSS rather than a charting library: two bar
 * charts and a set of meters do not justify the weight of one, and this app
 * has just spent a release getting that weight down.
 *
 * The bars are scaled to the tallest column rather than to an absolute, which
 * is what makes a quiet month visible next to a busy one. Each carries its own
 * figure in a `title` and in the accessible name, because a bar whose only
 * value is its height says nothing to somebody who cannot see it.
 */
export default {
	name: 'Statistics',

	components: {
		IconAccount,
		IconCalendar,
		IconHeart,
		IconPound,
		IconRefresh,
		IconReply,
		IconRepeat,
		IconShape,
		IconTrophy,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			error: '',
			/** @type {object|null} */
			stats: null,
		}
	},

	computed: {
		/** @return {string} */
		joined() {
			const at = this.stats?.account?.created_at
			if (!at) {
				return ''
			}

			const date = new Date(at)

			return Number.isNaN(date.getTime())
				? ''
				: t('social', 'Here since {date}', {
						date: date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
					})
		},

		/** @return {Array<{key: string, label: string, count: number, height: string}>} */
		months() {
			const counts = this.stats?.by_month ?? {}
			const tallest = Math.max(1, ...Object.values(counts))

			return Object.entries(counts).map(([key, count]) => ({
				key,
				count,
				label: new Date(key + '-01T00:00:00Z').toLocaleDateString(undefined, { month: 'narrow' }),
				full: new Date(key + '-01T00:00:00Z').toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
				height: Math.round((count / tallest) * 100) + '%',
			}))
		},

		/** @return {Array<{key: number, label: string, count: number, height: string}>} */
		hours() {
			const counts = this.stats?.by_hour ?? []
			const tallest = Math.max(1, ...counts)

			return counts.map((count, hour) => ({
				key: hour,
				count,
				// every third hour is labelled; the rest would be a smear
				label: (hour % 3 === 0) ? String(hour) : '',
				hour,
				height: Math.round((count / tallest) * 100) + '%',
			}))
		},

		/**
		 * What the posts are made of, as counts against the total.
		 *
		 * @return {Array<{key: string, label: string, count: number, share: string}>}
		 */
		composition() {
			const posts = this.stats?.posts ?? {}
			const visibility = this.stats?.visibility ?? {}
			const total = Math.max(1, posts.total ?? 0)
			const rows = [
				{ key: 'originals', label: t('social', 'Posts of your own'), count: posts.originals ?? 0 },
				{ key: 'replies', label: t('social', 'Replies'), count: posts.replies ?? 0 },
				{ key: 'boosts', label: t('social', 'Boosts of other people'), count: posts.boosts ?? 0 },
				{ key: 'media', label: t('social', 'With a picture or a video'), count: posts.with_media ?? 0 },
				{ key: 'public', label: t('social', 'Public'), count: visibility.public ?? 0 },
				{ key: 'unlisted', label: t('social', 'Unlisted'), count: visibility.unlisted ?? 0 },
				{ key: 'followers', label: t('social', 'Followers only'), count: visibility.followers ?? 0 },
				{ key: 'direct', label: t('social', 'Direct'), count: visibility.direct ?? 0 },
			]

			return rows.map((row) => ({ ...row, share: Math.round((row.count / total) * 100) + '%' }))
		},

		/** @return {string} what the numbers above were counted over */
		window() {
			const w = this.stats?.window
			if (!w) {
				return ''
			}

			if (w.capped) {
				return t('social', 'Counted over your {count} most recent posts, which is as far back as this page goes.', { count: w.max })
			}

			return n('social', 'Counted over your one post.', 'Counted over all {count} of your posts.', w.counted, { count: w.counted })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('apps/social/api/v1/statistics'))
				this.stats = response.data
			} catch (error) {
				logger.error('could not load the statistics', { error })
				this.error = t('social', 'Could not work out your statistics.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {number} value a count
		 * @return {string} it, in the reader's own digits and grouping
		 */
		number(value) {
			return Number(value ?? 0).toLocaleString()
		},

		/**
		 * @param {number} value an average
		 * @return {string} it, to one decimal
		 */
		decimal(value) {
			return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		/**
		 * @param {object} month one column
		 * @return {string} what the column says, for a pointer and a reader
		 */
		monthTitle(month) {
			return n('social', '%n post in {month}', '%n posts in {month}', month.count, { month: month.full })
		},

		/**
		 * @param {object} hour one column
		 * @return {string} what the column says
		 */
		hourTitle(hour) {
			return n('social', '%n post at {hour}:00 UTC', '%n posts at {hour}:00 UTC', hour.count, { hour: hour.hour })
		},
	},
}
</script>

<style scoped lang="scss">
.stats {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}
}

.stats__hint {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.stats__loading {
	margin: 60px auto;
}

.stats__error {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 10px;
	padding: 16px;
}

.stats__card {
	margin-bottom: 16px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);

	h3 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	h4 {
		margin: 14px 0 6px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: normal;
	}
}

.stats__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.stats__figures {
	display: flex;
	flex-wrap: wrap;
	gap: 12px 28px;
	margin: 10px 0 4px;

	li {
		display: flex;
		flex-direction: column;
	}

	strong {
		font-size: 26px;
		line-height: 1.1;
	}

	span {
		color: var(--color-text-maxcontrast);
	}

	em {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
		font-style: normal;
	}
}

.stats__best {
	display: flex;
	flex-direction: column;
	gap: 2px;

	a {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 14px;
		align-items: baseline;
		justify-content: space-between;
		padding: 8px;
		border-radius: var(--border-radius, 8px);

		&:hover {
			background: var(--color-background-hover);
		}
	}
}

.stats__best-text {
	flex: 1 1 260px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.stats__best-counts {
	display: flex;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;

	span {
		display: flex;
		gap: 3px;
		align-items: center;
	}
}

.stats__bars {
	display: flex;
	gap: 3px;
	align-items: flex-end;
	height: 90px;

	li {
		display: flex;
		flex: 1 1 0;
		flex-direction: column;
		justify-content: flex-end;
		height: 100%;
		min-width: 0;
	}
}

.stats__bar {
	/* a column with nothing in it is still a column: the 2px keeps the
	   baseline readable instead of leaving a hole in the chart */
	height: max(2px, var(--height));
	border-radius: 3px 3px 0 0;
	background: var(--color-primary-element);
	transition: height .3s cubic-bezier(.22, 1, .36, 1);
}

.stats__bar-label {
	overflow: hidden;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 10px;
	text-align: center;
	white-space: nowrap;
}

.stats__rows {
	margin-top: 8px;
}

.stats__row {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 3px 0;

	dt {
		flex: 0 0 42%;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	dd {
		display: flex;
		flex: 1;
		gap: 8px;
		align-items: center;
		min-width: 0;
		margin: 0;
	}
}

.stats__meter {
	height: 8px;
	/* the share of the total, never narrower than a sliver that is visibly
	   not zero */
	width: max(3px, var(--share));
	border-radius: 4px;
	background: var(--color-primary-element-light, var(--color-primary-element));
}

.stats__row-value {
	font-size: 13px;
	font-variant-numeric: tabular-nums;
}

.stats__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;

	a {
		display: inline-flex;
		gap: 6px;
		align-items: baseline;
		padding: 3px 10px;
		border-radius: var(--border-radius-pill, 100px);
		background: var(--color-background-dark);

		&:hover {
			background: var(--color-background-hover);
		}
	}
}

.stats__tag-count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.stats__window {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-align: center;
}

@media (prefers-reduced-motion: reduce) {
	.stats__bar {
		transition: none;
	}
}
</style>
