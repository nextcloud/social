<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<ul v-if="pinned.length" class="profile-pinned">
			<TimelineEntry
				v-for="entry in pinned"
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
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

export default {
	name: 'ProfileTimeline',
	components: {
		TimelineEntry,
		TimelineList,
	},

	data() {
		return {
			pinnedIds: [],
		}
	},

	computed: {
		...mapStores(useTimelineStore),
		/**
		 * The pinned posts, read back out of the store.
		 *
		 * They used to live in local component data, so every mutation in
		 * store/timeline.js skipped them (each one is guarded by
		 * `state.statuses[id] !== undefined`) and liking, boosting,
		 * bookmarking or unpinning a pinned post was a UI no-op. A boosted
		 * pinned post was worse: TimelineEntry looks its reblog up in the
		 * store, found nothing, and rendered an empty card.
		 *
		 * @return {object[]}
		 */
		pinned() {
			return this.pinnedIds
				.map((id) => this.timelineStore.getStatus(id))
				.filter(Boolean)
		},
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
				this.timelineStore.changeTimelineTypeAccount(this.$route.params.account)
			}
		},

		/**
		 * The pinned posts sit above the timeline, the way every Fediverse
		 * profile shows them. A failure here leaves the timeline alone.
		 */
		async loadPinned() {
			this.pinnedIds = []
			const account = this.$route.params.account
			if (!account) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(`apps/social/api/v1/accounts/${account}/statuses`),
					{ params: { pinned: true } },
				)
				const statuses = Array.isArray(data) ? data : []
				for (const status of statuses) {
					this.timelineStore.addToStatuses(status)
				}
				this.pinnedIds = statuses.map((status) => status.id)
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
