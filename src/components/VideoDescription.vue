<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section
		class="video-description"
		:class="{ 'video-description--open': open }"
		:aria-label="t('social', 'Description')"
		@click="openFromBox">
		<p class="video-description__facts">
			<span v-if="views !== ''">{{ views }}</span>
			<span v-if="views !== '' && published !== ''" aria-hidden="true">·</span>
			<time v-if="published !== ''" :datetime="card.published" :title="publishedInFull">{{ published }}</time>
		</p>

		<div :id="bodyId" class="video-description__body">
			<!-- a warning is said first, and what it warns about only once the
			     reader opens the box -->
			<p v-if="warning !== ''" class="video-description__warning">
				{{ warning }}
			</p>
			<div v-if="status.content && (open || warning === '')" class="video-description__text">
				<MessageContent :item="status" />
			</div>

			<template v-if="open">
				<ul v-if="chips.length" class="video-description__chips">
					<li v-for="chip in chips" :key="chip.kind" class="video-description__chip">
						<span class="hidden-visually">{{ chip.label }}: </span>{{ chip.value }}
					</li>
				</ul>

				<div v-if="chapters.length" class="video-description__chapters">
					<h3 class="video-description__heading">
						{{ t('social', 'Chapters') }}
					</h3>
					<ol class="video-description__chapter-list">
						<li v-for="chapter in chapters" :key="chapter.start">
							<button class="video-description__chapter" type="button" @click.stop="$emit('seek', chapter.start)">
								<span class="video-description__chapter-time">{{ clock(chapter.start) }}</span>
								<span class="video-description__chapter-title">{{ chapter.title }}</span>
							</button>
						</li>
					</ol>
				</div>

				<p v-if="video.support" class="video-description__support">
					{{ video.support }}
				</p>
				<p v-if="video.download === false" class="video-description__note">
					{{ t('social', 'The author has asked that this video not be downloaded.') }}
				</p>

				<PostDetails :status="status" />
				<PostReactedBy :status="status" />
			</template>
		</div>

		<button
			type="button"
			class="video-description__toggle"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="bodyId"
			@click.stop="open = !open">
			{{ open ? t('social', 'Show less') : t('social', '…more') }}
		</button>
	</section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import MessageContent from './MessageContent.js'
import PostDetails from './PostDetails.vue'
import PostReactedBy from './PostReactedBy.vue'
import { formatTime } from '../utils/shortVideo.js'
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import { compactCount, videoCardOf } from '../utils/videoCard.js'

/**
 * The box under a video that says what it is: "1.2K views · 3 days ago",
 * then the words the post came with, at most three lines of them until it is
 * opened. Open, it adds the category, the language and the licence, the
 * chapters to jump to, the line the author wrote asking for support, and the
 * fine print a post's own page has (`PostDetails`, `PostReactedBy`).
 *
 * The box opens from its button, which a keyboard and a screen reader reach,
 * and from a press anywhere on it that is not a link, as on a video site.
 */
export default {
	name: 'VideoDescription',

	components: {
		MessageContent,
		PostDetails,
		PostReactedBy,
	},

	props: {
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	/** `seek` asks the player to go to a chapter, in seconds. */
	emits: ['seek'],

	data() {
		return {
			open: false,
		}
	},

	computed: {
		/** @return {object} what the post said about the video */
		video() {
			return this.status.video ?? {}
		},

		/** @return {{views?: number|null, published?: string}} */
		card() {
			return videoCardOf(this.status) ?? { views: null, published: this.status.created_at }
		},

		/** @return {string} how many watched it, short while closed and exact once open; '' when nobody counted */
		views() {
			const views = this.card.views
			if (typeof views !== 'number') {
				return ''
			}

			return this.open
				? n('social', '%n view', '%n views', views)
				: n('social', '{count} view', '{count} views', views, { count: compactCount(views) })
		},

		/** @return {string} how long ago, or the full date once open */
		published() {
			return this.open ? fullDateTime(this.card.published) : fromNow(this.card.published)
		},

		/** @return {string} */
		publishedInFull() {
			return fullDateTime(this.card.published)
		},

		/** @return {string} the content warning, '' when there is none */
		warning() {
			return String(this.status.spoiler_text ?? '').trim()
		},

		/** @return {Array<{kind: string, label: string, value: string}>} what kind of video it is, as chips */
		chips() {
			return [
				{ kind: 'category', label: t('social', 'Category'), value: this.video.category },
				{ kind: 'language', label: t('social', 'Language'), value: this.video.language },
				{ kind: 'licence', label: t('social', 'Licence'), value: this.video.licence },
			].filter((chip) => typeof chip.value === 'string' && chip.value !== '')
		},

		/** @return {Array<{title: string, start: number}>} */
		chapters() {
			return Array.isArray(this.video.chapters) ? this.video.chapters : []
		},

		/** @return {string} */
		bodyId() {
			return `video-description-${this.status.id}`
		},
	},

	watch: {
		// another video opened in the same page starts closed
		'status.id': {
			handler() {
				this.open = false
			},
		},
	},

	methods: {
		t,

		/**
		 * @param {number} seconds a moment in the video
		 * @return {string} it as a clock, `1:02:03` or `2:03`
		 */
		clock(seconds) {
			return formatTime(seconds)
		},

		/**
		 * Opens the box from a press on it; a link pressed inside goes where it
		 * leads instead, and an open box closes only from its button.
		 *
		 * @param {MouseEvent} event the press
		 */
		openFromBox(event) {
			if (this.open || /** @type {HTMLElement} */ (event.target)?.closest?.('a, button')) {
				return
			}
			this.open = true
		},
	},
}
</script>

<style scoped lang="scss">
.video-description {
	position: relative;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	cursor: pointer;

	&:hover {
		background: var(--color-background-darker);
	}

	&--open {
		cursor: auto;

		&:hover {
			background: var(--color-background-dark);
		}
	}
}

.video-description__facts {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
	font-weight: 600;
}

.video-description__warning {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	font-weight: 600;
}

.video-description__text {
	margin-block-start: calc(var(--default-grid-baseline) * 2);
	overflow-wrap: anywhere;
}

/* three lines of the words until the box is opened */
.video-description:not(.video-description--open) .video-description__text {
	display: -webkit-box;
	overflow: hidden;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 3;
	line-clamp: 3;
}

.video-description__chips {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: calc(var(--default-grid-baseline) * 3) 0 0;
	padding: 0;
	list-style: none;
}

.video-description__chip {
	padding: 2px calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	font-size: var(--font-size-small, 13px);
}

.video-description__heading {
	margin-block: calc(var(--default-grid-baseline) * 4) var(--default-grid-baseline);
	font-size: 15px;
}

.video-description__chapter-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.video-description__chapter {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	inline-size: 100%;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: inherit;
	text-align: start;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.video-description__chapter-time {
	min-inline-size: 4em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.video-description__support,
.video-description__note {
	margin-block: calc(var(--default-grid-baseline) * 3) 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.video-description__toggle {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}
</style>
