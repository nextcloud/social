<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<!-- What of an account to read, next to how to draw it: the same
		     switcher the timelines carry, because it is the same shape of
		     choice — one account seen three ways rather than three places. -->
		<TimelineSwitcher
			:options="kinds"
			:value="kind"
			:label="t('social', 'Which of their posts to show')" />

		<!-- pinned posts belong to the account, not to a kind of attachment:
		     on Photos they would be whatever that account pinned, pictures or
		     not, above a page that promised pictures -->
		<ul v-if="pinned.length && kind === ''" class="profile-pinned">
			<TimelineEntry
				v-for="entry in pinned"
				:key="`pinned-${entry.id}`"
				:item="entry"
				type="account" />
		</ul>

		<TimelineList :display="display" :browse="browse" :account="routeAccount" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import TimelineEntry from './../components/TimelineEntry.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'
import { profileKinds } from '../composables/useProfileKinds.js'
import { isVideoChannel } from '../services/videoChannel.js'

export default {
	name: 'ProfileTimeline',
	components: {
		TimelineEntry,
		TimelineList,
		TimelineSwitcher,
	},

	data() {
		return {
			pinnedIds: [],
			/**
			 * Which profile's pinned posts are being waited for. A reader
			 * moving from one profile to another leaves the first request in
			 * flight, and the answer to it is a list of somebody else's posts;
			 * the same counter `TimelineList` keeps, for the same reason.
			 */
			generation: 0,
			/** whether the account mostly posts videos, so it has a channel's tabs */
			channel: false,
		}
	},

	computed: {
		/** @return {string} the account the page is about, as the address names it */
		routeAccount() {
			return String(this.$route.params.account ?? '')
		},

		...mapStores(useTimelineStore),
		/**
		 * Which of an account's posts are being read.
		 *
		 * The query rather than a route of its own, so a profile stays one
		 * page: every link to `@alice` still names the same route, and the
		 * `media` word is the API's own (`media_type`), not the tab's label.
		 * It arrives from the address bar, so anything else is read as all of
		 * them.
		 *
		 * A channel's Shorts and Live are kinds too; opened from a link they
		 * are honoured on any profile.
		 *
		 * @return {string} '', 'image', 'video', 'short' or 'live'
		 */
		kind() {
			const media = String(this.$route.query?.media ?? '')

			return ['image', 'video', 'short', 'live'].includes(media) ? media : ''
		},

		/** @return {boolean} whether the account's videos are drawn as a channel's */
		isChannel() {
			return this.channel || this.kind === 'short' || this.kind === 'live'
		},

		/**
		 * How the tab is drawn, which is a property of the tab rather than a
		 * choice on top of it.
		 *
		 * There used to be a grid/list switch here, remembered across
		 * profiles. It could disagree with the tab: the grid kept only the
		 * posts that carried a picture, so Posts showed sixteen of them as a
		 * list and three as a grid, and nothing said where the other thirteen
		 * had gone. Posts is what somebody wrote, which is a list; Photos and
		 * Videos are what they showed, which is a grid. One question, one
		 * answer. A channel's videos are what it is for, and are drawn as a
		 * video site draws them, in cards with their titles.
		 *
		 * @return {string} 'grid', 'videos' or 'timeline'
		 */
		display() {
			if (this.kind === '') {
				return 'timeline'
			}
			if (this.kind === 'image' || (this.kind === 'video' && !this.isChannel)) {
				return 'grid'
			}

			return 'videos'
		},

		/** @return {object} what a channel's tabs ask of the cards: no shelves, no upload, Live chosen on Live, tall cards on Shorts */
		browse() {
			return {
				shelves: false,
				upload: false,
				fixedChip: this.kind === 'live' ? 'live' : '',
				upright: this.kind === 'short',
			}
		},

		/**
		 * @return {object[]} the tabs, for the switcher
		 */
		kinds() {
			return profileKinds(this.routeAccount, this.isChannel)
		},

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
		// a tab is a different question asked of the server, not a filter of
		// what is already on screen: the list is refetched, or Photos would
		// show whatever of the last page happened to carry a picture
		kind: 'loadTimeline',
	},

	beforeMount() {
		this.load()
	},

	methods: {
		t,

		load() {
			this.loadTimeline()
			this.loadPinned()
			this.loadChannel()
		},

		/** Whether this profile is a channel; the tabs are the ordinary ones until it is known. */
		async loadChannel() {
			const account = this.routeAccount
			this.channel = false
			const channel = await isVideoChannel(account)
			if (this.routeAccount === account) {
				this.channel = channel
			}
		},

		loadTimeline() {
			if (this.routeAccount !== '') {
				this.timelineStore.changeTimelineTypeAccount(this.routeAccount, this.kind)
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

			this.generation += 1
			const generation = this.generation
			try {
				const { data } = await axios.get(
					generateUrl(`apps/social/api/v1/accounts/${account}/statuses`),
					{ params: { pinned: true } },
				)
				const statuses = Array.isArray(data) ? data : []
				// the posts themselves are worth keeping whoever is on screen
				// now — they are indexed by their own id and nothing reads
				// them until something asks for one
				for (const status of statuses) {
					this.timelineStore.addToStatuses(status)
				}

				// the *list* is about one profile, and this is the answer for
				// a profile the reader may have left
				if (generation !== this.generation) {
					return
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
