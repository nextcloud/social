<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__timeline">
		<!-- the timeline changes under the reader without a word otherwise: new
		     posts appear, a page loads, the end is reached, and none of it is
		     announced. Polite, so it waits for a gap rather than interrupting -->
		<div class="hidden-visually" role="status" aria-live="polite">
			{{ announcement }}
		</div>
		<transition name="pill">
			<button
				v-if="arrived > 0"
				class="new-posts-pill"
				:aria-label="n('social', 'Show %n new post', 'Show %n new posts', arrived)"
				@click="showArrived">
				<ArrowUp :size="18" />
				{{ n('social', '%n new post', '%n new posts', arrived) }}
			</button>
		</transition>
		<!-- The grid is a different way of drawing the same timeline, not a
		     different timeline: paging, the new-post pill, the error state and
		     the empty state all stay here, because otherwise the grid would
		     have to reimplement every one of them. -->
		<ProfileMediaGrid
			v-if="display === 'grid'"
			:posts="timeline"
			:account="account"
			:loading="loading" />
		<transition-group v-else name="list" tag="ul">
			<TimelineEntry
				v-for="(entry, index) in timeline"
				:key="entry.id"
				:class="{ 'timeline-entry--focused': index === focused }"
				:item="entry"
				:type="type" />
		</transition-group>
		<TimelineSkeleton v-if="display !== 'grid' && loading && timeline.length === 0" />
		<!--
		  A failure used to set allLoaded, so the reader was shown "No posts
		  found / Posts from people you follow will show up here" for what was
		  a server error, and the observer never fired again: paging was dead
		  until a full reload. An error is now its own state, with a retry.
		-->
		<div v-if="error !== null" class="timeline-error" role="alert">
			<p class="timeline-error__message">
				{{ error }}
			</p>
			<NcButton variant="primary" :disabled="loading" @click="retry">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>
		<div ref="sentinel" class="list-sentinel">
			<div v-if="loading && timeline.length > 0" class="icon-loading" />
			<div v-else-if="!loading && !allLoaded" class="list-end" />
			<EmptyContent v-if="showEmptyContent && display !== 'grid'" :item="emptyContentData" />
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { listen } from '@nextcloud/notify_push'

import { translate, translatePlural } from '@nextcloud/l10n'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ProfileMediaGrid from './ProfileMediaGrid.vue'
import TimelineEntry from './TimelineEntry.vue'
import TimelineSkeleton from './TimelineSkeleton.vue'
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'
import eventBus from '../services/eventBus.js'
import { mapStores } from 'pinia'
import { useNotificationsStore } from '../store/notifications.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

/**
 * How many times fetchNewStatuses() may follow itself in one tick. It recursed
 * with no cap and terminated only because the server honours min_id; a server
 * that ignores it looped for as long as the tab was open.
 */
const MAX_CATCHUP_PAGES = 10

export default {
	name: 'TimelineList',
	components: {
		ProfileMediaGrid,
		ArrowUp,
		Refresh,
		NcButton,
		TimelineEntry,
		TimelineSkeleton,
		EmptyContent,
	},

	props: {
		type: {
			type: String,
			default: () => 'home',
		},

		showParents: {
			type: Boolean,
			default: false,
		},

		reverseOrder: {
			type: Boolean,
			default: false,
		},

		/**
		 * How to draw the posts: as a list of entries, or as the grid of
		 * squares a profile shows on Pixelfed. Only the drawing differs --
		 * the timeline, its paging and its states are the same.
		 */
		display: {
			type: String,
			default: 'list',
			validator: (value) => ['list', 'grid'].includes(value),
		},

		/** Whose profile the grid links its tiles into. */
		account: {
			type: String,
			default: '',
		},
	},

	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},

	data() {
		return {
			infoHidden: false,
			state: [],
			intervalId: -1,
			pollEvery: 30000,
			/** when the tab was last hidden, so returning can catch up */
			hiddenSince: 0,
			/** posts that arrived while the reader was further down the page */
			arrived: 0,
			/** index of the post the keyboard is on, -1 when none */
			focused: -1,
			loading: false,
			allLoaded: false,
			/**
			 * Which list the requests in flight belong to. Switching timeline
			 * used to leave `loading` set, so nothing was ever asked for the
			 * list now on screen, and the previous list's answer was committed
			 * under the new heading when it arrived.
			 */
			generation: 0,
			/** what went wrong, when something did; null while all is well */
			error: null,
			/** whether the polling failure has already been said once */
			pollFailureReported: false,
			observer: null,
			emptyContent: {
				default: {
					image: 'img/undraw/posts.svg',
					title: t('social', 'No posts found'),
					description: t('social', 'Posts from people you follow will show up here'),
				},

				direct: {
					image: 'img/undraw/direct.svg',
					title: t('social', 'No direct messages found'),
					description: t('social', 'Posts directed to you will show up here'),
				},

				timeline: {
					image: 'img/undraw/local.svg',
					title: t('social', 'No local posts found'),
					description: t('social', 'Posts from other people on this instance will show up here'),
				},

				notifications: {
					image: 'img/undraw/notifications.svg',
					title: t('social', 'No notifications found'),
					description: t('social', 'You have not received any notifications yet'),
				},

				federated: {
					image: 'img/undraw/global.svg',
					title: t('social', 'No global posts found'),
					description: t('social', 'Posts from federated instances will show up here'),
				},

				favourites: {
					image: 'img/undraw/likes.svg',
					title: t('social', 'No liked posts found'),
				},

				profile: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'You have not tooted yet'),
				},

				tags: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'No posts found for this tag'),
				},

				videos: {
					image: 'img/undraw/posts.svg',
					title: t('social', 'No videos found'),
					description: t('social', 'Videos posted here, and videos from the PeerTube channels you follow, will show up here'),
				},

				'single-post': {
					title: this.showParents ? '' : t('social', 'No replies found'),
				},
			},
		}
	},

	computed: {
		...mapStores(useNotificationsStore, useTimelineStore),
		/**
		 * What has just changed, in one sentence. Only the thing worth saying:
		 * a reader does not need to hear about every page that loads while
		 * they scroll, but they do need to know when nothing more is coming.
		 */
		announcement() {
			if (this.arrived > 0) {
				return translatePlural('social', '%n new post available', '%n new posts available', this.arrived)
			}
			if (this.loading) {
				return translate('social', 'Loading posts')
			}
			if (this.allLoaded && this.timeline.length > 0) {
				return translate('social', 'You have reached the end')
			}

			return ''
		},

		/** @return {string} which list the store is holding */
		timelineIdentity() {
			return this.timelineStore.getTimelineIdentity
		},

		/** @return {boolean} nothing to show, and nothing went wrong */
		showEmptyContent() {
			return this.error === null
				&& this.allLoaded
				&& this.timeline.length === 0
				&& this.emptyContentData.title !== ''
		},

		/**
		 * What to say when the list is empty. This used to write to
		 * `this.emptyContent[...]` from inside the computed, which mutated
		 * component data during evaluation and left the mutation behind for
		 * every later route.
		 *
		 * @return {object} an image, a title and a description
		 */
		emptyContentData() {
			// a filtered tab of a profile is empty for a different reason than
			// an account that has never posted: they may have posted plenty,
			// just none of this
			const media = String(this.$route.query?.media ?? '')
			const emptyTab = {
				image: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'No photos yet'),
					description: t('social', 'Posts with pictures appear here.'),
				},

				video: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'No videos yet'),
					description: t('social', 'Posts with videos appear here.'),
				},
			}[media]
			if (this.$route.name === 'profile' && emptyTab !== undefined) {
				return emptyTab
			}

			const byType = this.emptyContent[this.$route.params.type]
			if (byType !== undefined) {
				return byType
			}

			const byName = this.emptyContent[this.$route.name]
			if (byName !== undefined) {
				if (this.$route.name === 'timeline') {
					return this.emptyContent.default
				}
				if (this.$route.name === 'profile' && (this.serverData.public || this.$route.params.account !== this.currentUser.uid)) {
					return {
						...byName,
						title: this.$route.params.account + ' ' + t('social', 'hasn\'t tooted yet'),
					}
				}
				return byName
			}

			logger.debug('Did not find any empty content for this route', { routeType: this.$route.params.type, routeName: this.$route.name })
			return this.emptyContent.default
		},

		timeline() {
			const timeline = this.showParents
				? this.timelineStore.getParentsTimeline
				: this.timelineStore.getTimeline

			// a copy: .reverse() sorts in place, and this array comes from a
			// cached Vuex getter that every other reader shares
			return this.reverseOrder ? [...timeline].reverse() : timeline
		},
	},

	watch: {
		/**
		 * The router-view is no longer keyed on the full path, so switching
		 * timeline reuses this component: the paging state has to be put back
		 * by hand, or Home keeps Global's "you have reached the end" — and
		 * nothing would ask the server for the list that is now current.
		 */
		timelineIdentity() {
			if (!this.showParents) {
				this.resetAndLoad()
			}
		},

		// reading the notifications is what marks them read; the badge should
		// not survive the reader looking straight at what it is counting
		timeline: {
			immediate: true,
			handler(entries) {
				if (this.type !== 'notifications' || entries.length === 0) {
					return
				}

				// a Notification entity carries the row id as `id`, a string;
				// statuses carry the same number again as `nid`
				const newest = entries.reduce(
					(highest, entry) => Math.max(highest, Number(entry.id ?? entry.nid) || 0),
					0,
				)
				if (newest > 0) {
					this.notificationsStore.markNotificationsRead(newest)
				}
			},
		},
	},

	mounted() {
		// The ancestors list in the single-post view renders the same
		// /context response its sibling fetches: it used to page, poll and
		// observe on its own, so opening a thread made two identical
		// requests and left two 30-second intervals running. It also listened
		// for j/k, so one press moved the focus in both lists at once.
		if (this.showParents) {
			return
		}

		eventBus.on('shortcut:next', this.focusNext)
		eventBus.on('shortcut:previous', this.focusPrevious)

		this.infiniteHandler()
		// with notify_push the server tells us about new entries; polling
		// remains as a slow safety net. Without it, poll every 30 seconds.
		const hasPush = listen('social_timeline', () => this.fetchNewStatuses())
		this.pollEvery = (hasPush ? 300 : 30) * 1000
		this.intervalId = setInterval(() => this.pollIfVisible(), this.pollEvery)
		// a tab nobody is looking at does not need to ask; it catches up when
		// it comes back
		document.addEventListener('visibilitychange', this.pollOnReturn)
		this.setupIntersectionObserver()
	},

	unmounted() {
		document.removeEventListener('visibilitychange', this.pollOnReturn)
		eventBus.off('shortcut:next', this.focusNext)
		eventBus.off('shortcut:previous', this.focusPrevious)
		clearInterval(this.intervalId)
		if (this.observer) {
			this.observer.disconnect()
		}
	},

	methods: {
		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.loading && !this.allLoaded) {
					this.infiniteHandler()
				}
			}, { rootMargin: '200px' })
			this.$nextTick(() => {
				if (this.$refs.sentinel) {
					this.observer.observe(this.$refs.sentinel)
				}
			})
		},

		/** Starts this timeline over: a different type is a different list. */
		resetAndLoad() {
			this.generation += 1
			// whatever is still in flight belongs to the list that was here a
			// moment ago; it is disowned above, and this is what lets the new
			// list ask at all
			this.loading = false
			this.allLoaded = false
			this.error = null
			this.arrived = 0
			this.focused = -1
			this.pollFailureReported = false
			this.infiniteHandler()
		},

		/** What the retry button does. */
		retry() {
			this.error = null
			this.allLoaded = false
			this.infiniteHandler()
		},

		async infiniteHandler() {
			if (this.loading) {
				return
			}
			this.loading = true

			const generation = this.generation
			const params = {}

			if (this.timeline.length !== 0) {
				// The timeline getter sorts by created_at while min_id/max_id
				// filter on the numeric id, and a federated post can have a
				// high id with an old date — so page on the ids themselves,
				// or the cursor never advances and the same page loops forever.
				const ids = this.timeline.map((entry) => Number.parseInt(entry.id)).filter((id) => !Number.isNaN(id))
				if (ids.length !== 0) {
					if (this.reverseOrder) {
						params.min_id = Math.max(...ids)
					} else {
						params.max_id = Math.min(...ids)
					}
				}
			}

			try {
				const response = await this.timelineStore.fetchTimeline(params)
				if (generation !== this.generation) {
					return
				}
				this.error = null
				// a /context response is the whole thread at once rather than a
				// page of one, and its `.length` is undefined — so `=== 0` was
				// never true and the sentinel kept asking for a next page that
				// does not exist
				this.allLoaded = Array.isArray(response) ? response.length === 0 : true
			} catch (error) {
				if (generation !== this.generation) {
					return
				}
				logger.error('Failed to load more timeline entries', { error })
				// not allLoaded: that told the observer to stop watching and
				// showed the reader an empty timeline for a server error
				this.error = this.timeline.length === 0
					? translate('social', 'The posts could not be loaded.')
					: translate('social', 'No more posts could be loaded.')
			} finally {
				// the newer request owns `loading` now
				if (generation === this.generation) {
					this.loading = false
				}
			}
		},

		/**
		 * The polling tick. Asking while the tab is hidden is traffic nobody
		 * is waiting for — on an instance with many open tabs it is most of
		 * the traffic there is.
		 */
		pollIfVisible() {
			if (document.visibilityState === 'hidden') {
				this.hiddenSince = this.hiddenSince || Date.now()
				return
			}

			this.fetchNewStatuses()
		},

		/** Catches up once, on the way back to a tab that was left. */
		pollOnReturn() {
			if (document.visibilityState !== 'visible' || this.hiddenSince === 0) {
				return
			}

			const away = Date.now() - this.hiddenSince
			this.hiddenSince = 0
			if (away >= this.pollEvery) {
				this.fetchNewStatuses()
			}
		},

		focusNext() {
			this.moveFocus(1)
		},

		focusPrevious() {
			this.moveFocus(-1)
		},

		/**
		 * Moves the keyboard's attention through the list and scrolls it into
		 * view, so j/k reads a timeline without touching the mouse.
		 *
		 * @param {number} step 1 for the next post, -1 for the previous one
		 */
		moveFocus(step) {
			if (this.timeline.length === 0) {
				return
			}

			const next = Math.min(Math.max(this.focused + step, 0), this.timeline.length - 1)
			this.focused = next
			eventBus.emit('timeline:focused', this.timeline[next])

			this.$nextTick(() => {
				const entries = this.$el.querySelectorAll('.timeline-entry')
				const entry = entries[next]
				if (entry === undefined) {
					return
				}

				// real focus, not just a highlight: without it the reader most
				// likely to be using j/k is told nothing at all, and scrolling
				// alone leaves the keyboard somewhere else entirely
				entry.focus({ preventScroll: true })
				entry.scrollIntoView({
					block: 'center',
					behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
				})
			})
		},

		showArrived() {
			this.arrived = 0
			window.scrollTo({
				top: 0,
				behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
			})

			// scrolling moves the page, not the keyboard: send focus to the
			// first of the posts the reader just asked to see
			this.$nextTick(() => {
				this.focused = 0
				this.$el.querySelector('.timeline-entry')?.focus({ preventScroll: true })
			})
		},

		t: translate,
		n: translatePlural,
		/**
		 * Catches up on what arrived since the newest post on screen.
		 *
		 * @param {number} depth how many pages have already been followed
		 */
		async fetchNewStatuses(depth = 0) {
			if (this.showParents) {
				return
			}

			// Newest by id, not this.timeline[0] (sorted by created_at): a
			// federated post with a high id but an old date would otherwise
			// keep min_id stuck and this method would refetch forever.
			const ids = this.timeline.map((entry) => Number.parseInt(entry.id)).filter((id) => !Number.isNaN(id))

			try {
				const response = await this.timelineStore.fetchTimeline({
					min_id: ids.length === 0 ? undefined : Math.max(...ids),
				})
				this.pollFailureReported = false

				if (response.length > 0) {
					// only worth announcing when the top of the list is out of
					// sight; up there the posts simply appear
					if (window.scrollY > 240) {
						this.arrived += response.length
					}
					if (depth + 1 < MAX_CATCHUP_PAGES) {
						this.fetchNewStatuses(depth + 1)
					} else {
						logger.debug('Stopped catching up after the page cap', { pages: MAX_CATCHUP_PAGES })
					}
				}
			} catch (error) {
				logger.error('Failed to load newer timeline entries', { error })
				// once, not once per tick: a server that is down produced an
				// endless stream of identical toasts every 30 seconds
				if (!this.pollFailureReported) {
					this.pollFailureReported = true
					showError(translate('social', 'Could not load the newest posts'))
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__timeline {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0 var(--social-column-gutter);

	ul {
		margin: 0;
		padding: 0;
	}

	.icon-loading {
		height: 44px;
		margin: 20px auto;
	}

	.list-end {
		height: 1px;
	}

	.list-sentinel {
		min-height: 1px;
	}
}

.timeline-error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	margin: 20px 0;
	padding: 24px 20px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	text-align: center;

	&__message {
		margin: 0;
		color: var(--color-text-lighter);
		line-height: 1.5;
	}
}

.new-posts-pill {
	position: sticky;
	top: 8px;
	z-index: 10;
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 auto 8px;
	padding: 6px 16px;
	border: none;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: bold;
	cursor: pointer;
	box-shadow: var(--social-elevation-raised);

	&:hover,
	&:focus-visible {
		background: var(--color-primary-element-hover);
	}
}

.pill-enter-active,
.pill-leave-active {
	transition: opacity .2s ease, transform .2s ease;
}

.pill-enter-from,
.pill-leave-to {
	opacity: 0;
	transform: translateY(-8px);
}

@media (prefers-reduced-motion: reduce) {
	.pill-enter-active,
	.pill-leave-active {
		transition: none;
	}
}
/* where the keyboard is, for j/k readers */
.timeline-entry--focused :deep(.post-content),
.timeline-entry--focused :deep(.main-post) {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}
</style>
