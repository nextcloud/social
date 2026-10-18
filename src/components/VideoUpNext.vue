<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="up-next" :aria-labelledby="headingId">
		<div class="up-next__head">
			<h2 :id="headingId" class="up-next__title">
				{{ t('social', 'Up next') }}
			</h2>
			<NcCheckboxRadioSwitch
				class="up-next__autoplay"
				type="switch"
				:modelValue="autoplay"
				@update:modelValue="setAutoplay">
				{{ t('social', 'Autoplay') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- said once when it starts, not every second it counts -->
		<p class="hidden-visually" role="status">
			{{ announcement }}
		</p>
		<div v-if="countdown > 0 && next !== null" class="up-next__countdown">
			<p class="up-next__countdown-text">
				{{ n('social', 'Playing the next video in %n second', 'Playing the next video in %n seconds', countdown) }}
			</p>
			<div class="up-next__countdown-actions">
				<NcButton variant="primary" @click="playNext">
					{{ t('social', 'Play now') }}
				</NcButton>
				<NcButton variant="tertiary" @click="cancelCountdown">
					{{ t('social', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<ol v-if="videos.length" class="up-next__list">
			<li v-for="entry in videos" :key="entry.id" class="up-next__item">
				<VideoCard :entry="entry" compact />
			</li>
		</ol>
		<ul v-else-if="loading" class="up-next__list" aria-hidden="true">
			<li v-for="index in 4" :key="index" class="up-next__bone">
				<span class="up-next__bone-thumb" />
				<span class="up-next__bone-lines">
					<span class="up-next__bone-line" />
					<span class="up-next__bone-line up-next__bone-line--short" />
				</span>
			</li>
		</ul>
		<p v-else class="up-next__none">
			{{ t('social', 'Nothing else to watch here yet.') }}
		</p>
	</section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { mapStores } from 'pinia'
import VideoCard from './VideoCard.vue'
import { loadRelated } from '../services/relatedVideos.js'
import { autoplayNext, setAutoplayNext } from '../services/watchPreferences.js'
import { useTimelineStore } from '../store/timeline.js'
import { videoPostOf } from '../utils/videoCard.js'

/** How long the page waits before opening the next video by itself, in seconds. */
export const COUNTDOWN = 5

/**
 * The column beside the player: what to watch after this video, more from
 * the same channel first (`GET /api/v1/videos/{id}/related`), each as a small
 * card. With Autoplay on, the end of the video starts a short countdown to the
 * first of them, which the reader can cut short or call off.
 *
 * The videos are put in the store as they arrive, so the one pressed opens at
 * once rather than after a request of its own.
 */
export default {
	name: 'VideoUpNext',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		VideoCard,
	},

	props: {
		/** The video being watched. */
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	data() {
		return {
			/** @type {import('../types/Mastodon.js').Status[]} */
			videos: [],
			loading: false,
			autoplay: autoplayNext(),
			/** seconds left before the next video opens; 0 when nothing is counting */
			countdown: 0,
			/** @type {ReturnType<typeof setInterval>|null} */
			timer: null,
			/** which video's list is being asked for, so a late answer is dropped */
			asking: '',
			/** what the live region says: the countdown once, when it starts */
			announcement: '',
		}
	},

	computed: {
		...mapStores(useTimelineStore),

		/** @return {string} */
		headingId() {
			return `up-next-${this.status.id}`
		},

		/** @return {import('../types/Mastodon.js').Status|null} what plays after this */
		next() {
			return this.videos[0] ?? null
		},
	},

	watch: {
		'status.id': {
			immediate: true,
			handler() {
				this.cancelCountdown()
				this.load()
			},
		},
	},

	beforeUnmount() {
		this.cancelCountdown()
	},

	methods: {
		t,
		n,

		async load() {
			const id = String(this.status.id ?? '')
			this.asking = id
			this.videos = []
			if (id === '') {
				return
			}

			this.loading = true
			const videos = await loadRelated(id)
			if (this.asking !== id) {
				return
			}
			for (const entry of videos) {
				this.timelineStore.addToStatuses(entry)
			}
			this.videos = videos
			this.loading = false
		},

		/** @param {boolean} on what the reader switched it to */
		setAutoplay(on) {
			this.autoplay = on
			setAutoplayNext(on)
			if (!on) {
				this.cancelCountdown()
			}
		},

		/**
		 * The video ended: with Autoplay on and something to play, the
		 * countdown to it starts. Called by the page, which hears the player.
		 */
		videoEnded() {
			if (!this.autoplay || this.next === null || this.timer !== null) {
				return
			}

			this.countdown = COUNTDOWN
			this.announcement = n('social', 'Playing the next video in %n second', 'Playing the next video in %n seconds', COUNTDOWN)
			this.timer = setInterval(() => {
				this.countdown -= 1
				if (this.countdown <= 0) {
					this.playNext()
				}
			}, 1000)
		},

		cancelCountdown() {
			if (this.timer !== null) {
				clearInterval(this.timer)
				this.timer = null
			}
			this.countdown = 0
			this.announcement = ''
		},

		/** Opens the first video of the list. */
		playNext() {
			const next = this.next
			this.cancelCountdown()
			if (next === null) {
				return
			}

			const post = videoPostOf(next)
			this.$router.push({ name: 'single-post', params: { account: post.account?.acct ?? '', id: post.id } })
		},
	},
}
</script>

<style scoped lang="scss">
.up-next__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}

.up-next__title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}

.up-next__countdown {
	margin-block-end: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.up-next__countdown-text {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	font-weight: 600;
}

.up-next__countdown-actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.up-next__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	margin: 0;
	padding: 0;
	list-style: none;
}

.up-next__none {
	color: var(--color-text-maxcontrast);
}

.up-next__bone {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.up-next__bone-thumb {
	flex: 0 0 auto;
	inline-size: min(168px, 42%);
	aspect-ratio: 16 / 9;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.up-next__bone-lines {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 6px;
}

.up-next__bone-line {
	block-size: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);

	&--short {
		inline-size: 60%;
	}
}
</style>
