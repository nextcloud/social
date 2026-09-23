<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<h2>Social</h2>
		<ul v-if="profileCounts.length" class="profile-page__counts" :aria-label="t('social', 'Social profile counts')">
			<li v-for="count in profileCounts" :key="count.key">{{ count.label }}</li>
		</ul>
		<transition-group name="list" tag="ul">
			<TimelineEntry
				v-for="entry in timeline"
				:key="entry.id"
				:item="entry" />
		</transition-group>
	</div>
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
					label: translatePlural('social', '{count} post', '{count} posts', statuses, { count: formatCount(statuses) }),
				},
				{
					key: 'following',
					label: translatePlural('social', '{count} following', '{count} following', following, { count: formatCount(following) }),
				},
				{
					key: 'followers',
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
.profile-page__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
}
</style>
