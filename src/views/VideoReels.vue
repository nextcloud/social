<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="reels" :aria-label="t('social', 'Videos, one at a time')">
		<div
			ref="track"
			class="reels__track"
			tabindex="0"
			@keydown="onKey"
			@scroll.passive="onScroll">
			<article
				v-for="(entry, index) in reels"
				:key="entry.status.id"
				:ref="(el) => setSlide(el, index)"
				class="reel"
				:data-index="index">
				<video
					:ref="(el) => setVideo(el, index)"
					class="reel__video"
					:src="entry.video.url"
					:poster="entry.video.preview_url || undefined"
					:aria-label="entry.video.description || entry.text"
					:muted="muted"
					playsinline
					loop
					preload="none"
					@click="togglePlay(index)" />

				<!-- the one control that is not a gesture: a pointer has no swipe -->
				<button
					type="button"
					class="reel__sound"
					:aria-label="muted ? t('social', 'Unmute') : t('social', 'Mute')"
					@click.stop="toggleSound">
					<IconVolumeOff v-if="muted" :size="20" />
					<IconVolumeHigh v-else :size="20" />
				</button>

				<div class="reel__caption">
					<router-link
						class="reel__author"
						:to="{ name: 'profile', params: { account: entry.status.account.acct } }">
						<img
							v-if="entry.status.account.avatar"
							class="reel__avatar"
							:src="entry.status.account.avatar"
							alt="">
						<span class="reel__names">
							<span class="reel__name">{{ entry.status.account.display_name || entry.status.account.username }}</span>
							<span class="reel__handle">@{{ entry.status.account.acct }}</span>
						</span>
					</router-link>
					<p v-if="entry.text" class="reel__text">
						{{ entry.text }}
					</p>
					<router-link
						class="reel__open"
						:to="{ name: 'single-post', params: { account: entry.status.account.acct, id: entry.status.id } }">
						{{ t('social', 'Open the post') }}
					</router-link>
				</div>
			</article>

			<div v-if="reels.length === 0 && !loading" class="reels__empty">
				<p>{{ t('social', 'No videos here yet.') }}</p>
				<NcButton :to="{ name: 'timeline', params: { type: 'videos' } }">
					{{ t('social', 'Back to Videos') }}
				</NcButton>
			</div>
		</div>

		<NcButton
			class="reels__close"
			:aria-label="t('social', 'Back to Videos')"
			:to="{ name: 'timeline', params: { type: 'videos' } }">
			<template #icon>
				<IconClose :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
/**
 * Videos one at a time, full height, the way the app people are leaving shows
 * them.
 *
 * The Videos page is a grid, which is right for choosing and wrong for
 * watching: every video on it is a still until it is clicked, and the thing
 * somebody arriving from TikTok is used to is a stack that plays by itself as
 * it goes past. This is that stack, over the same timeline — no new endpoint,
 * no second idea of what a video is.
 *
 * Three rules hold it together:
 *
 *  - **One plays at a time.** An IntersectionObserver decides which, and every
 *    other element is paused rather than left buffering; a dozen videos
 *    playing behind the one on screen is a phone getting hot for nothing.
 *  - **Muted until asked.** A page that makes noise on open is a page people
 *    close, and no browser will autoplay with sound anyway. The choice is
 *    remembered for the session and applies to every video, because it is a
 *    statement about this page rather than about one video.
 *  - **The scroll does the work.** CSS scroll-snap rather than a transform per
 *    slide: it is the one thing that behaves the same under a finger, a
 *    trackpad, a wheel and a keyboard, and it keeps working when the
 *    JavaScript that observes it does not.
 */
import { mapStores } from 'pinia'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconVolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { useTimelineStore } from '../store/timeline.js'
import { oldestId } from '../utils/snowflake.js'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'

/** How close to the end the reader gets before the next page is asked for. */
const LOOK_AHEAD = 3

export default {
	name: 'VideoReels',
	components: {
		IconClose,
		IconVolumeHigh,
		IconVolumeOff,
		NcButton,
	},

	props: {
		/**
		 * Which circle of people: 'home' (the ones you follow), 'timeline'
		 * (this instance) or 'federated' (everywhere) — the same three the
		 * Videos page is read at, carried here so that leaving the grid for
		 * the stack does not silently change what is in it.
		 */
		scope: {
			type: String,
			default: 'home',
		},
	},

	data() {
		return {
			muted: true,
			playing: 0,
			loading: false,
			allLoaded: false,
			/** the <video> of each slide, by index */
			videos: [],
			slides: [],
			/** tells onVisible which slide is on screen */
			observer: null,
		}
	},

	computed: {
		...mapStores(useTimelineStore),

		/**
		 * One entry per video, not per post: a post with three videos on it is
		 * three things to watch, and a stack that showed only the first would
		 * be hiding two of them behind a grid tile nobody goes back to.
		 *
		 * @return {object[]} the video, the post it is on, and its words
		 */
		reels() {
			const entries = []

			for (const status of this.timelineStore.getTimeline) {
				for (const media of status.media_attachments ?? []) {
					if (media.type === 'video' || media.type === 'gifv') {
						entries.push({
							status,
							video: media,
							text: htmlToPlainText(status.content ?? '').trim(),
						})
					}
				}
			}

			return entries
		},
	},

	mounted() {
		this.timelineStore.changeTimelineType({
			type: 'videos',
			params: { scope: this.scope },
		})
		this.load()

		// `threshold: 0.6` rather than a bare intersection: two slides touch
		// the viewport for most of a scroll, and whichever was observed last
		// would win. A slide is the one being watched when most of it is there.
		this.observer = new IntersectionObserver(this.onVisible, { threshold: 0.6 })
		this.$refs.track?.focus?.()
	},

	beforeUnmount() {
		this.observer?.disconnect()
		for (const video of this.videos) {
			video?.pause?.()
		}
	},

	updated() {
		// slides arrive a page at a time, so each new one is taken under
		// observation as it appears rather than all of them once at mount
		for (const slide of this.slides) {
			if (slide && !slide.dataset.observed) {
				slide.dataset.observed = '1'
				this.observer?.observe(slide)
			}
		}
	},

	methods: {
		t,

		setSlide(el, index) {
			this.slides[index] = el
		},

		setVideo(el, index) {
			this.videos[index] = el
		},

		/**
		 * Whichever slide is mostly on screen becomes the one playing, and
		 * every other one stops. Pausing rather than leaving them be: a video
		 * scrolled past keeps downloading otherwise, and a page of them is a
		 * phone getting hot to buffer what nobody is watching.
		 *
		 * @param {IntersectionObserverEntry[]} entries what moved
		 */
		onVisible(entries) {
			for (const entry of entries) {
				if (!entry.isIntersecting) {
					continue
				}

				const index = Number(entry.target.dataset.index)
				if (Number.isNaN(index)) {
					continue
				}

				this.playing = index
				this.play(index)
			}
		},

		play(index) {
			this.videos.forEach((video, at) => {
				if (!video) {
					return
				}

				if (at === index) {
					video.muted = this.muted
					// a rejected play is the browser's autoplay rule, which is
					// an answer rather than a fault: the poster stays up and
					// the reader can press it
					video.play?.().catch((error) => logger.debug('autoplay refused', { error }))
				} else {
					video.pause?.()
					// back to the first frame, so coming back to it is the
					// video again rather than its last second
					if (video.currentTime > 0) {
						video.currentTime = 0
					}
				}
			})

			if (index >= this.reels.length - LOOK_AHEAD) {
				this.load()
			}
		},

		togglePlay(index) {
			const video = this.videos[index]
			if (!video) {
				return
			}

			if (video.paused) {
				video.play?.().catch((error) => logger.debug('play refused', { error }))
			} else {
				video.pause?.()
			}
		},

		toggleSound() {
			this.muted = !this.muted
			const video = this.videos[this.playing]
			if (video) {
				video.muted = this.muted
			}
		},

		/**
		 * Up and down, and space for pause — a stack is reachable without a
		 * finger or it is reachable by nobody using a keyboard.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKey(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				const next = this.playing + ((event.key === 'ArrowDown') ? 1 : -1)
				this.slides[next]?.scrollIntoView({ behavior: 'smooth' })
			} else if (event.key === ' ') {
				event.preventDefault()
				this.togglePlay(this.playing)
			} else if (event.key === 'm') {
				this.toggleSound()
			}
		},

		/**
		 * A fallback for the observer: a browser that does not fire it, or a
		 * scroll that lands between two thresholds, still ends up with the
		 * right slide playing.
		 */
		onScroll() {
			const track = this.$refs.track
			if (!track) {
				return
			}

			const at = Math.round(track.scrollTop / track.clientHeight)
			if (at !== this.playing && this.slides[at]) {
				this.playing = at
				this.play(at)
			}
		},

		/**
		 * The next page of videos, paged on the ids themselves.
		 *
		 * As strings end to end: an id here is a snowflake, and one rounded
		 * through a Number either re-fetches the post it points at or skips
		 * the rows between the two.
		 */
		async load() {
			if (this.loading || this.allLoaded) {
				return
			}

			this.loading = true
			const params = {}
			const ids = this.timelineStore.getTimeline.map((status) => status.id)
			const cursor = oldestId(ids)
			if (cursor !== undefined) {
				params.max_id = cursor
			}

			try {
				const page = await this.timelineStore.fetchTimeline(params)
				this.allLoaded = Array.isArray(page) ? page.length === 0 : true
			} catch (error) {
				logger.error('Could not load more videos', { error })
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.reels {
	position: relative;
	/* the app's own content area, not the window: the navigation stays where
	   it is and the stack fills what is left of the page.
	   Measured off the viewport rather than given `100%`, because nothing in
	   the chain above this has a height for a percentage to be of — so the
	   stack was as tall as one slide's contents and sat in the top half of a
	   white page. Both of the server's own offsets are taken off, or the
	   stack overhangs its column by the eight pixels of the one that was
	   missed and the column scrolls behind the slides. */
	block-size: calc(
		100vh - var(--header-height, 50px) - var(--body-container-margin, 8px)
	);
	inline-size: 100%;
	background: #000;

	&__track {
		block-size: 100%;
		overflow-y: auto;
		scroll-snap-type: y mandatory;
		/* the one gesture this page has, and it must not also pull the page
		   behind it down to refresh */
		overscroll-behavior-y: contain;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		block-size: 100%;
		color: #fff;
	}

	&__close {
		position: absolute;
		inset-block-start: 12px;
		inset-inline-end: 12px;
	}
}

.reel {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	block-size: 100%;
	scroll-snap-align: start;
	scroll-snap-stop: always;

	&__video {
		inline-size: 100%;
		block-size: 100%;
		/* contain rather than cover: a video shot wide is not improved by
		   having its sides cut off to fill a tall window */
		object-fit: contain;
		background: #000;
	}

	&__sound {
		position: absolute;
		/* bottom right, clear of both the app's own sidebar toggle in the top
		   left and the way out in the top right */
		inset-block-end: 16px;
		inset-inline-end: 16px;
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 40px;
		block-size: 40px;
		border: none;
		border-radius: 50%;
		background: rgba(0, 0, 0, 0.55);
		color: #fff;
		cursor: pointer;
	}

	&__caption {
		position: absolute;
		inset-block-end: 0;
		inset-inline: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
		/* room on the end for the sound button, which sits over this */
		padding: 16px 72px 16px 16px;
		color: #fff;
		/* the words sit on whatever the video happens to be showing, so they
		   carry their own ground rather than hoping it is dark there */
		background: linear-gradient(to top, rgba(0, 0, 0, 0.75), transparent);
	}

	&__author {
		display: flex;
		align-items: center;
		gap: 8px;
		color: inherit;
		text-decoration: none;
	}

	&__avatar {
		inline-size: 36px;
		block-size: 36px;
		border-radius: 50%;
	}

	&__names {
		display: flex;
		flex-direction: column;
	}

	&__name {
		font-weight: bold;
	}

	&__handle {
		font-size: 90%;
		opacity: 0.8;
	}

	&__text {
		margin: 0;
		/* a caption is a caption, not the post: three lines and the rest is
		   behind Open the post */
		display: -webkit-box;
		-webkit-line-clamp: 3;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__open {
		color: #fff;
		text-decoration: underline;
	}
}
</style>
