<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="records.length > 0" class="bluesky-missing-records">
		<p>{{ t('social', 'They are missing from:') }}</p>
		<ul>
			<li v-for="record in records" :key="record.collection + '/' + record.rkey">
				<template v-if="record.collection === 'app.bsky.feed.post'">
					<span v-if="fullDate(record.created_at)" class="bluesky-missing-records__date">{{ fullDate(record.created_at) }}</span>
					<a
						v-if="record.url"
						:href="record.url"
						target="_blank"
						rel="noopener noreferrer">{{ record.text || t('social', 'A post without text') }}</a>
					<span v-else>{{ record.text || t('social', 'A post without text') }}</span>
				</template>
				<span v-else-if="record.collection === 'app.bsky.actor.profile'">{{ t('social', 'Your profile picture or header') }}</span>
				<span v-else>{{ t('social', 'Something else of your account') }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { fullDate } from '../utils/relativeTime.js'

/**
 * The posts, and the profile, that lose the pictures or videos a move could
 * not copy, as the server names them (`progress.missing_records`, at most
 * twenty): shown before the person goes on without them.
 */
export default {
	name: 'BlueskyMissingRecords',

	props: {
		/** the records, as the server names them */
		records: {
			/** @type {import('vue').PropType<{collection: string, rkey: string, created_at: string, text: string, url: string}[]>} */
			type: Array,
			default: () => [],
		},
	},

	methods: {
		fullDate,
		t,
	},
}
</script>

<style scoped lang="scss">
.bluesky-missing-records {
	margin-top: 12px;

	ul {
		margin-top: 4px;
		padding-inline-start: 20px;
		list-style: disc;
	}

	li {
		overflow-wrap: anywhere;
	}

	&__date {
		margin-inline-end: 8px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
