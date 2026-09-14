<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper">
		<!-- the first thing a new account sees; gone for good once closed -->
		<FirstRun v-if="showInfo" @done="hideInfo" />

		<Composer v-if="type !== 'notifications' && type !== 'single-post'" :defaultVisibility="type === 'direct' ? 'direct' : undefined" />

		<!-- the three timelines that are the same place seen from three
		     distances: switching between them is something a reader does while
		     reading, not something they navigate to -->
		<TimelineSwitcher
			v-if="isFeed"
			:options="scopes"
			:value="scope"
			:label="t('social', 'Which posts to show')" />

		<div class="timeline-heading-row">
			<!-- the page had no heading at all outside tags and notifications, so
			     there was nothing to land on and nothing to say where you were -->
			<h1 class="timeline-heading" :class="{ 'hidden-visually': !headingIsVisible }">
				{{ heading }}
			</h1>
			<HashtagFollowButton
				v-if="type === 'tags'"
				:tag="$route.params.tag"
				@changed="onHashtagFollowChanged" />
		</div>

		<HashtagFollowedList v-if="type === 'tags'" ref="followedHashtags" />

		<!-- which kinds of activity to show; the same control as the scopes
		     above the feed, choosing a filter of this page rather than a page -->
		<TimelineSwitcher
			v-if="type === 'notifications'"
			class="notifications-filter"
			:options="notificationFilters"
			:value="notificationFilter"
			:label="t('social', 'Which activities to show')"
			@update:value="chooseNotificationFilter" />

		<TimelineList :type="type" :listTitle="listTitle" />

		<!-- the first post somebody ever publishes here, marked once -->
		<FirstPostCelebration v-if="celebratingFirstPost" @done="endCelebration" />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconAccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import IconAt from 'vue-material-design-icons/At.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import IconMessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import IconPoll from 'vue-material-design-icons/Poll.vue'
import IconRepeat from 'vue-material-design-icons/Repeat.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import FirstPostCelebration from './../components/FirstPostCelebration.vue'
import FirstRun from './../components/FirstRun.vue'
import HashtagFollowButton from './../components/HashtagFollowButton.vue'
import HashtagFollowedList from './../components/HashtagFollowedList.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import eventBus from './../services/eventBus.js'
import { rememberFilter, rememberedFilter } from './../services/notifications.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Timeline',
	components: {
		Composer,
		FirstPostCelebration,
		FirstRun,
		HashtagFollowButton,
		HashtagFollowedList,
		TimelineList,
		TimelineSwitcher,
	},

	data() {
		return {
			infoHidden: false,
			/** the title of the list being read, once the server has said */
			listTitle: '',
			/**
			 * Which kinds of activity the notifications page shows; the
			 * browser remembers the choice, so the page opens where it was left
			 */
			notificationFilter: rememberedFilter(),
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),
		/** What this timeline is, in the words the sidebar uses for it. */
		heading() {
			switch (this.type) {
				case 'tags':
					return '#' + this.$route.params.tag
				case 'list':
					// the sidebar knows the title; the page asks for it itself
					// so that a link opened cold has a heading too
					return this.listTitle || t('social', 'List')
				case 'photos':
					return t('social', 'Photos')
				case 'videos':
					return t('social', 'Videos')
				case 'notifications':
					// the sidebar calls it Activities; the route keeps the name
					// the API gives it
					return t('social', 'Activities')
				case 'direct':
					return t('social', 'Direct messages')
				case 'timeline':
				// what the sidebar calls Local: the store asks for `local: true`
					return t('social', 'Local timeline')
				case 'federated':
					return t('social', 'Global timeline')
				case 'favourites':
					return t('social', 'Liked posts')
				case 'bookmarks':
					return t('social', 'Bookmarks')
				case 'single-post':
					return t('social', 'Post')
				default:
					return t('social', 'Home timeline')
			}
		},

		/**
		 * @return {boolean} whether the three scopes are what this page shows
		 */
		isFeed() {
			return ['home', 'timeline', 'federated', 'photos', 'videos'].includes(this.type)
		},

		/**
		 * Whether this page is one page read at three scopes rather than three
		 * pages. Photos and Videos both are: the sidebar entry stays lit
		 * whichever of the three the reader chose, which it would not if each
		 * scope were a `type` of its own.
		 *
		 * @return {boolean}
		 */
		isScopedPage() {
			return this.type === 'photos' || this.type === 'videos'
		},

		/**
		 * The three distances the same posts can be read at, as the switcher
		 * above them offers them.
		 *
		 * On Photos and Videos they are the same three, so those stay one page
		 * with a scope on it: the scope rides in the query, which keeps the
		 * sidebar entry lit whichever is chosen and keeps each of them one
		 * timeline rather than three that look alike. On the feed itself each
		 * scope is its own route, and `home` is the route with no `type` at
		 * all — passing `type: 'home'` would ask for a timeline of that name,
		 * which nothing serves.
		 *
		 * @return {object[]} what to give the switcher
		 */
		scopes() {
			const routeFor = (scope) => {
				if (this.isScopedPage) {
					return {
						name: 'timeline',
						params: { type: this.type },
						query: scope === 'home' ? {} : { scope },
					}
				}

				return scope === 'home'
					? { name: 'timeline' }
					: { name: 'timeline', params: { type: scope } }
			}

			return [
				// "My Feed" rather than "Home": next to Local and Global, what
				// distinguishes it is whose posts it holds, not where it sits
				{ value: 'home', label: t('social', 'My Feed'), icon: IconHome, to: routeFor('home') },
				{ value: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple, to: routeFor('timeline') },
				{ value: 'federated', label: t('social', 'Global'), icon: IconEarth, to: routeFor('federated') },
			]
		},

		/**
		 * Which of the three scopes is being read.
		 *
		 * Photos and Videos are each one page with a scope on it rather than
		 * three pages, so on them the scope comes from the query; everywhere
		 * else the type *is* the scope. A query that says anything else is read
		 * as the default rather than trusted: it arrives from the address bar.
		 *
		 * @return {string} `home`, `timeline` or `federated`
		 */
		scope() {
			if (!this.isScopedPage) {
				return this.type
			}

			const scope = String(this.$route.query.scope ?? '')

			return ['timeline', 'federated'].includes(scope) ? scope : 'home'
		},

		/**
		 * The two that were on the page before stay on the page; the rest name
		 * the view for a screen reader without changing what anyone sees.
		 */
		headingIsVisible() {
			// Photos and Videos are views of their own rather than a filter of
			// a list you were already on, so they say which one you are
			// looking at
			return this.type === 'tags' || this.type === 'list' || this.type === 'notifications' || this.isScopedPage
		},

		/**
		 * The filters over the notifications, in the words of the sidebar:
		 * everything, or one kind of activity at a time.
		 *
		 * @return {object[]} what to give the switcher
		 */
		notificationFilters() {
			return [
				{ value: 'all', label: t('social', 'All'), icon: IconBell },
				{ value: 'mentions', label: t('social', 'Mentions'), icon: IconAt },
				{ value: 'favourites', label: t('social', 'Favourites'), icon: IconHeart },
				{ value: 'boosts', label: t('social', 'Boosts'), icon: IconRepeat },
				{ value: 'follows', label: t('social', 'Follows'), icon: IconAccountPlusOutline },
				{ value: 'polls', label: t('social', 'Polls'), icon: IconPoll },
				{ value: 'edits', label: t('social', 'Edits'), icon: IconMessagePlusOutline },
			]
		},

		/** @return {string} what identifies this timeline, params included */
		timelineKey() {
			return this.type + '|' + JSON.stringify(this.params)
		},

		params() {
			if (this.$route.name === 'tags') {
				return { tag: this.$route.params.tag }
			} else if (this.$route.name === 'list') {
				return { id: this.$route.params.id }
			} else if (this.$route.name === 'single-post') {
				return this.$route.params
			} else if (this.isScopedPage) {
				// part of what identifies this timeline, so that changing the
				// scope refetches rather than leaving the previous photos up
				return { scope: this.scope }
			} else if (this.type === 'notifications') {
				// the same reason: a filter is a question for the server, and
				// a different one is a different list
				return { filter: this.notificationFilter }
			}
			return {}
		},

		type() {
			if (this.$route.name === 'tags') {
				return 'tags'
			}
			if (this.$route.name === 'list') {
				return 'list'
			}
			if (this.$route.params.type) {
				return this.$route.params.type
			}
			return 'home'
		},

		showInfo() {
			// `firstrun` from the server, or `?welcome=1`, which is what the
			// setup screen reloads with once the account exists
			return (this.settingsStore.getServerData.firstrun || this.$route.query?.welcome === '1') && !this.infoHidden
		},

		/** @return {boolean} whether the first-post celebration is on screen */
		celebratingFirstPost() {
			return this.timelineStore.isCelebratingFirstPost
		},

	},

	watch: {
		// the router-view is no longer keyed on the full path, so switching
		// from Home to Global reuses this view: without this the store would
		// keep serving the previous timeline
		timelineKey() {
			this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
			this.fetchListTitle()
		},
	},

	beforeMount() {
		this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
		this.fetchListTitle()
	},

	mounted() {
		eventBus.on('post-published', this.onPostPublished)
	},

	beforeUnmount() {
		eventBus.off('post-published', this.onPostPublished)
		// navigating away mid-celebration must not leave the flag standing for
		// whatever timeline mounts next
		if (this.celebratingFirstPost) {
			this.timelineStore.endFirstPostCelebration()
		}
	},

	methods: {
		/** Asks for the list's title; nothing to ask when this is not a list. */
		async fetchListTitle() {
			if (this.type !== 'list') {
				this.listTitle = ''
				return
			}
			const id = this.$route.params.id
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/lists/${id}`))
				// the reader may have moved on while the server was answering
				if (this.type === 'list' && this.$route.params.id === id) {
					this.listTitle = data?.title ?? ''
				}
			} catch {
				this.listTitle = ''
			}
		},

		hideInfo() {
			this.infoHidden = true
		},

		/**
		 * @param {string} filter one of the keys of NOTIFICATION_FILTERS
		 */
		chooseNotificationFilter(filter) {
			this.notificationFilter = filter
			rememberFilter(filter)
		},

		/**
		 * A post went out. Whether that is the reader's first is the store's
		 * decision; asking costs nothing and nothing here waits on the answer,
		 * so the post itself appears exactly as it did before.
		 */
		onPostPublished() {
			this.timelineStore.celebrateFirstPost()
		},

		/** The list of followed hashtags is stale the moment one is followed. */
		onHashtagFollowChanged() {
			this.$refs.followedHashtags?.refresh()
		},

		endCelebration() {
			this.timelineStore.endFirstPostCelebration()
		},
	},
}
</script>

<style scoped lang="scss">
/*
 * The column, and nothing about what is in it. `.social__timeline` is another
 * component's root element, and a scoped style still reaches a child's root —
 * so a rule here lands on it with the same specificity as the list's own and
 * wins or loses on bundle order. This view used to set `margin: 0` on it, which
 * beat the list's own `margin: 0 auto` and left the timeline flush to one side
 * while the composer beside it stayed centred.
 */
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0;
}

.timeline-heading-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

.timeline-heading {
	font-size: 20px;
	font-weight: 700;
	margin: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-lighter);
	letter-spacing: -.01em;
}

/*
 * Seven options where the switcher was drawn for three.
 *
 * The track is as wide as its labels and its options do not wrap, so at the
 * switcher's own padding seven of them run past the column. They are given
 * less of it here, and below the width where even that stops fitting the
 * labels give way to the icons they sit beside -- staying in the
 * accessibility tree, because the label is the option's name. The switcher
 * does the same thing itself at 500px; this only brings the point forward for
 * a row that is twice as long as the ones it was built for.
 */
.notifications-filter {
	:deep(.switcher__option) {
		padding: 0 12px;
	}
}

@media (max-width: 800px) {
	.notifications-filter :deep(.switcher__label) {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
}

#app-content {
	position: relative;
}

/* while the sidebar is collapsed its toggle sits over the top-left corner of
   the content, where the composer or the heading begins; the first thing on
   the page starts below it */
@media (max-width: 1024px) {
	.social__wrapper > :first-child {
		margin-top: calc(var(--default-clickable-area, 44px) + var(--default-grid-baseline, 4px) * 2);
	}
}

</style>
