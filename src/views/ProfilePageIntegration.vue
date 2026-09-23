<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="social-profile">
		<h2 class="social-profile__title">
			{{ t('social', 'Social') }}
		</h2>
		<ul v-if="profileCounts.length" class="social-profile__counts" :aria-label="t('social', 'Social profile counts')">
			<li v-for="count in profileCounts" :key="count.key" class="social-profile__count">
				<span class="social-profile__count-value">{{ count.value }}</span>
				<span class="social-profile__count-label">{{ count.label }}</span>
			</li>
		</ul>
		<transition-group name="list" tag="ul" class="social-profile__timeline">
			<TimelineEntry
				v-for="entry in timeline"
				:key="entry.id"
				:item="entry"
				type="account" />
		</transition-group>
	</section>
</template>

<script>
import TimelineEntry from './../components/TimelineEntry.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translatePlural } from '@nextcloud/l10n'
import logger from './../services/logger.js'
import { formatCount } from './../utils/number.js'

export default {
	name: 'ProfilePageIntegration',
	components: {
		TimelineEntry,
	},

	props: {
		userId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			accountInfo: null,
			timeline: [],
		}
	},

	computed: {
		profileCounts() {
			if (!this.accountInfo) {
				return []
			}

			const statuses = Number(this.accountInfo.statuses_count) || 0
			const following = Number(this.accountInfo.following_count) || 0
			const followers = Number(this.accountInfo.followers_count) || 0
			return [
				{
					key: 'posts',
					value: formatCount(statuses),
					label: translatePlural('social', '{count} post', '{count} posts', statuses, { count: formatCount(statuses) }),
				},
				{
					key: 'following',
					value: formatCount(following),
					label: translatePlural('social', '{count} following', '{count} following', following, { count: formatCount(following) }),
				},
				{
					key: 'followers',
					value: formatCount(followers),
					label: translatePlural('social', '{count} follower', '{count} followers', followers, { count: formatCount(followers) }),
				},
			]
		},
	},

	// Start fetching account information before mounting the component
	beforeMount() {
		const uid = this.userId

		if (!uid) {
			return
		}

		axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${encodeURIComponent(uid)}`)).then(({ data }) => {
			this.accountInfo = data
			logger.debug('Loaded profile account info', { accountInfo: this.accountInfo })
		}).catch((error) => {
			logger.error('Failed to load profile account info', { error, uid })
		})

		axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(uid)}/statuses`)).then(({ data }) => {
			this.timeline = data
			logger.debug('Loaded profile timeline', { timeline: this.timeline })
		}).catch((error) => {
			logger.error('Failed to load profile timeline', { error, uid })
		})
	},
}
</script>

<style scoped>
.social-profile {
	min-width: 0;
}

.social-profile__title {
	margin-block: 0 0.75rem;
}

.social-profile__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	list-style: none;
	margin: 0 0 1.25rem;
	padding: 0;
}

.social-profile__count {
	align-items: baseline;
	background: var(--color-background-dark, var(--color-background-hover));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	display: inline-flex;
	gap: 0.4rem;
	padding: 0.4rem 0.65rem;
}

.social-profile__count-value {
	font-weight: 700;
}

.social-profile__count-label {
	color: var(--color-text-maxcontrast);
}

.social-profile__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
