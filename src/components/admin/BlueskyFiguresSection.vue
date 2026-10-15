<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="failed" class="bluesky-figures__error" role="alert">
			{{ t('social', 'Could not read the Bluesky figures') }}
		</p>
		<p v-else-if="!figures.enabled" class="social-admin__hint">
			{{ t('social', 'Bluesky is switched off on this server, so there is nothing to count. It is switched on in the Bluesky section.') }}
		</p>
		<template v-else>
			<div class="bluesky-figures">
				<div class="bluesky-figures__figure">
					<span class="bluesky-figures__number">{{ count(figures.posts.day) }}</span>
					<span class="bluesky-figures__label">{{ n('social', 'post in the last day', 'posts in the last day', figures.posts.day) }}</span>
				</div>
				<div class="bluesky-figures__figure">
					<span class="bluesky-figures__number">{{ count(figures.posts.week) }}</span>
					<span class="bluesky-figures__label">{{ n('social', 'post in the last week', 'posts in the last week', figures.posts.week) }}</span>
				</div>
				<div class="bluesky-figures__figure">
					<span class="bluesky-figures__number">{{ count(figures.accounts.identities) }}</span>
					<span class="bluesky-figures__label">{{ t('social', 'Accounts with a Bluesky identity') }}</span>
					<span class="bluesky-figures__sub">
						{{ n('social', '%n switched off by its owner', '%n switched off by their owners', figures.accounts.off) }}
					</span>
				</div>
			</div>

			<dl class="bluesky-figures__numbers">
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Repositories') }}</dt>
					<dd>{{ count(figures.repositories.count) }}</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Records in them') }}</dt>
					<dd>{{ count(figures.repositories.records) }}</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Media in them') }}</dt>
					<dd>{{ humanSize(figures.repositories.blob_bytes) }}</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Bluesky accounts read') }}</dt>
					<dd>{{ count(figures.reader.watched) }}</dd>
				</div>
				<div v-if="figures.reader.jetstream" class="bluesky-figures__cell">
					<dt>{{ t('social', 'Jetstream cursor') }}</dt>
					<dd>{{ jetstreamLabel }}</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Firehose subscribers') }}</dt>
					<dd :class="{ 'bluesky-figures__down': !figures.firehose.running }">
						{{ subscribersLabel }}
					</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Latest firehose event') }}</dt>
					<dd>{{ headLabel }}</dd>
				</div>
				<div class="bluesky-figures__cell">
					<dt>{{ t('social', 'Events kept for replay') }}</dt>
					<dd>{{ count(figures.firehose.events_kept) }}</dd>
				</div>
			</dl>
			<p class="social-admin__hint">
				{{ t('social', 'Counted from this server\'s own tables, at most a minute ago. Posts are the ones written to Bluesky from here, not the ones a moved account brought along.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { blueskyUrl } from '../../services/adminApi.js'
import { duration } from '../../utils/duration.js'
import { humanSize } from '../../utils/humanSize.js'
import { formatCount } from '../../utils/number.js'
import { fromNow } from '../../utils/relativeTime.js'

/**
 * @typedef {object} BlueskyFigures what `AtprotoFiguresService::figures()` answers
 * @property {boolean} enabled - false, and nothing else, while Bluesky is switched off
 * @property {number} [computed] - when the figures were counted, in seconds since the epoch
 * @property {{identities: number, off: number}} [accounts] - local accounts with a Bluesky identity, and how many of them their owners switched off
 * @property {{day: number, week: number}} [posts] - post records written here in the last day and week
 * @property {{count: number, records: number, blob_bytes: number}} [repositories] - the local repositories, their records and their blob bytes
 * @property {{watched: number, jetstream: boolean, jetstream_lag: number|null}} [reader] - the Bluesky accounts read, and how far behind the stored Jetstream cursor is in seconds
 * @property {{running: boolean, subscribers: number|null, head_seq: number, head_time: number, events_kept: number}} [firehose] - the firehose this server serves
 */

/**
 * How Bluesky is doing on this server, in figures. Read-only, and read when
 * the section is opened rather than with the page, because the counts are
 * the one part of the admin page that costs the database something.
 */
export default {
	name: 'BlueskyFiguresSection',

	components: {
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			failed: false,
			/** @type {BlueskyFigures} */
			figures: { enabled: false },
		}
	},

	computed: {
		/** @return {string} how far behind the stored Jetstream cursor is */
		jetstreamLabel() {
			const lag = this.figures.reader.jetstream_lag
			if (lag === null || lag === undefined) {
				return t('social', 'nothing heard yet')
			}
			if (lag < 60) {
				return t('social', 'up to date')
			}

			return t('social', '{duration} behind', { duration: duration(lag) })
		},

		/** @return {string} the subscriber count, or that the daemon is not running */
		subscribersLabel() {
			const subscribers = this.figures.firehose.subscribers
			if (subscribers === null || subscribers === undefined) {
				return t('social', 'Not running')
			}

			return formatCount(subscribers)
		},

		/** @return {string} the head sequence number and how long ago it was made */
		headLabel() {
			const firehose = this.figures.firehose
			if (!firehose.head_seq) {
				return t('social', 'none yet')
			}

			return t('social', 'Number {seq}, {ago}', {
				seq: formatCount(firehose.head_seq),
				ago: fromNow(firehose.head_time * 1000),
			})
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(blueskyUrl('/figures'))
			this.figures = data
		} catch {
			this.failed = true
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		n,
		humanSize,
		count: formatCount,
	},
}
</script>

<style lang="scss" scoped>
.bluesky-figures {
	display: flex;
	gap: 32px;
	flex-wrap: wrap;
}

.bluesky-figures__figure {
	display: flex;
	flex-direction: column;
}

.bluesky-figures__number {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.1;
}

.bluesky-figures__label {
	font-weight: bold;
}

.bluesky-figures__sub {
	color: var(--color-text-maxcontrast);
}

.bluesky-figures__numbers {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	dd {
		margin: 0;
		overflow-wrap: anywhere;
	}
}

.bluesky-figures__down {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.bluesky-figures__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
