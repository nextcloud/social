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

		<!-- A profile is a grid on Pixelfed and a timeline here. Both, then,
		     with the choice remembered: somebody who came for the grid should
		     not have to ask for it on every profile they open. -->
		<div class="profile-views" role="tablist" :aria-label="t('social', 'Profile view')">
			<NcButton
				role="tab"
				:aria-selected="String(view === 'grid')"
				:variant="view === 'grid' ? 'secondary' : 'tertiary'"
				:aria-label="t('social', 'Grid')"
				@click="setView('grid')">
				<template #icon>
					<ViewGridOutline :size="20" />
				</template>
			</NcButton>
			<NcButton
				role="tab"
				:aria-selected="String(view === 'timeline')"
				:variant="view === 'timeline' ? 'secondary' : 'tertiary'"
				:aria-label="t('social', 'Timeline')"
				@click="setView('timeline')">
				<template #icon>
					<FormatListBulletedSquare :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- pinned posts belong to the account, not to a kind of attachment:
		     on Photos they would be whatever that account pinned, pictures or
		     not, above a page that promised pictures -->
		<ul v-if="pinned.length && view === 'timeline' && kind === ''" class="profile-pinned">
			<TimelineEntry
				v-for="entry in pinned"
				:key="`pinned-${entry.id}`"
				:item="entry"
				type="account" />
		</ul>

		<TimelineList :display="view" :account="$route.params.account" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import FormatListBulletedSquare from 'vue-material-design-icons/FormatListBulletedSquare.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconTextBoxMultiple from 'vue-material-design-icons/TextBoxMultiple.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import TimelineEntry from './../components/TimelineEntry.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

const VIEW_KEY = 'social-profile-view'

/**
 * @return {string} the remembered choice, defaulting to the timeline -- which
 *                  is what this app has always shown and what somebody who
 *                  never asks for the grid should keep getting.
 */
function readStoredView() {
	try {
		return window.localStorage.getItem(VIEW_KEY) === 'grid' ? 'grid' : 'timeline'
	} catch {
		return 'timeline'
	}
}

export default {
	name: 'ProfileTimeline',
	components: {
		FormatListBulletedSquare,
		NcButton,
		TimelineEntry,
		TimelineList,
		TimelineSwitcher,
		ViewGridOutline,
	},

	data() {
		return {
			pinnedIds: [],
			view: readStoredView(),
		}
	},

	computed: {
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
		 * @return {string} '', 'image' or 'video'
		 */
		kind() {
			const media = String(this.$route.query?.media ?? '')

			return ['image', 'video'].includes(media) ? media : ''
		},

		/**
		 * @return {object[]} the three tabs, for the switcher
		 */
		kinds() {
			const to = (media) => ({
				name: 'profile',
				params: { account: this.$route.params.account },
				query: media === '' ? {} : { media },
			})

			return [
				{ value: '', label: t('social', 'Posts'), icon: IconTextBoxMultiple, to: to('') },
				{ value: 'image', label: t('social', 'Photos'), icon: IconImageMultiple, to: to('image') },
				{ value: 'video', label: t('social', 'Videos'), icon: IconPlayBoxMultiple, to: to('video') },
			]
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

		/**
		 * Remembered across profiles and reloads. A failure to write is
		 * ignored: the choice is a convenience, and a browser that refuses
		 * storage should still get a working profile.
		 *
		 * @param {string} view either 'grid' or 'timeline'
		 */
		setView(view) {
			this.view = view
			try {
				window.localStorage.setItem(VIEW_KEY, view)
			} catch (error) {
				logger.debug('Could not remember the profile view', { error })
			}
		},

		load() {
			this.loadTimeline()
			this.loadPinned()
		},

		loadTimeline() {
			if (this.$route.params.account) {
				this.timelineStore.changeTimelineTypeAccount(this.$route.params.account, this.kind)
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

.profile-views {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}
</style>
