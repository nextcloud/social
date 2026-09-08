<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<ul v-if="pinned.length" class="profile-pinned">
			<TimelineEntry v-for="entry in pinned"
				:key="`pinned-${entry.id}`"
				:item="entry"
				type="account" />
		</ul>
		<TimelineList />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import TimelineEntry from './../components/TimelineEntry.vue'
import TimelineList from './../components/TimelineList.vue'
import logger from '../services/logger.js'

export default {
	name: 'ProfileTimeline',
	components: {
		TimelineEntry,
		TimelineList,
	},
	data() {
		return {
			pinned: [],
		}
	},
	watch: {
		'$route.params.account': 'load',
	},
	beforeMount() {
		this.load()
	},
	methods: {
		load() {
			this.loadTimeline()
			this.loadPinned()
		},
		loadTimeline() {
			if (this.$route.params.account) {
				this.$store.dispatch('changeTimelineTypeAccount', this.$route.params.account)
			}
		},
		/**
		 * The pinned posts sit above the timeline, the way every Fediverse
		 * profile shows them. A failure here leaves the timeline alone.
		 */
		async loadPinned() {
			this.pinned = []
			const account = this.$route.params.account
			if (!account) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(`apps/social/api/v1/accounts/${account}/statuses`),
					{ params: { pinned: true } },
				)
				this.pinned = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Failed to load the pinned posts', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.profile-pinned {
	list-style: none;
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}
</style>
