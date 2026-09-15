<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="modalName"
		:hasPrevious="groupIndex > 0"
		:hasNext="groupIndex < groups.length - 1"
		size="full"
		class="story-viewer"
		@close="$emit('close')"
		@previous="previousGroup"
		@next="nextGroup">
		<div
			v-if="story"
			class="story-viewer__stage"
			@pointerdown="pause"
			@pointerup="resume"
			@pointercancel="resume">
			<!-- one segment per story of the account, the current one filling -->
			<ol class="story-viewer__progress" aria-hidden="true">
				<li
					v-for="(one, index) in group.stories"
					:key="one.id"
					class="story-viewer__segment"
					:class="{ 'story-viewer__segment--done': index < storyIndex }">
					<span
						v-if="index === storyIndex"
						class="story-viewer__fill"
						:style="{ width: Math.round(progress * 100) + '%' }" />
				</li>
			</ol>

			<header class="story-viewer__head">
				<router-link
					class="story-viewer__author"
					:to="{ name: 'profile', params: { account: group.account.acct } }"
					@click="$emit('close')">
					<ActorAvatar
						:actor="group.account"
						:size="32"
						:link="false"
						:hoverCard="false" />
					<span class="story-viewer__author-name">{{ group.account.display_name || group.account.username }}</span>
				</router-link>
				<span class="story-viewer__when">{{ ago(story) }}</span>
				<span v-if="isOwn" class="story-viewer__views" :title="t('social', 'Who has seen it')">
					<IconEye :size="16" />
					{{ n('social', '%n view', '%n views', story.view_count ?? 0) }}
				</span>
				<NcButton
					v-if="isOwn"
					variant="tertiary"
					class="story-viewer__delete"
					:ariaLabel="t('social', 'Delete this story')"
					:disabled="deleting"
					@click="remove">
					<template #icon>
						<IconDelete :size="20" />
					</template>
				</NcButton>
			</header>

			<div class="story-viewer__media">
				<video
					v-if="story.media && story.media.type === 'video'"
					:key="story.id"
					class="story-viewer__video"
					:src="story.media.url"
					:aria-label="story.media.description || story.caption || ''"
					autoplay
					muted
					playsinline
					@ended="next" />
				<img
					v-else-if="story.media"
					:key="story.id"
					class="story-viewer__image"
					:src="story.media.url"
					:alt="story.media.description || story.caption || ''">
				<p v-else class="story-viewer__gone">
					{{ t('social', 'The picture of this story is gone.') }}
				</p>
			</div>

			<p v-if="story.caption" class="story-viewer__caption">
				{{ story.caption }}
			</p>

			<!-- the two halves of the stage: back and forward, as every story
			     player has them, without a control drawn over the picture -->
			<button
				type="button"
				class="story-viewer__tap story-viewer__tap--back"
				:aria-label="t('social', 'Previous story')"
				@click="previous" />
			<button
				type="button"
				class="story-viewer__tap story-viewer__tap--forward"
				:aria-label="t('social', 'Next story')"
				@click="next" />
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import ActorAvatar from './ActorAvatar.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'

/** how often the progress bar moves, in ms */
const TICK = 100

/**
 * Plays the stories of one account after another, full screen.
 *
 * Each story shows for the seconds its poster gave it and is marked seen the
 * moment it is on screen — the server's rule, not this component's — so the
 * ring on the bar behind can follow. Holding the stage pauses; the halves of
 * it go back and forward; the modal's own arrows move between accounts. The
 * poster of a story sees how many people watched it and can take it down;
 * nobody else sees either.
 */
export default {
	name: 'StoryViewer',

	components: {
		ActorAvatar,
		IconDelete,
		IconEye,
		NcButton,
		NcModal,
	},

	props: {
		/** the bar's groups: `{account, stories, seen, own}` each */
		groups: {
			type: Array,
			required: true,
		},

		/** which group to start on */
		start: {
			type: Number,
			default: 0,
		},
	},

	emits: ['close', 'seen', 'deleted'],

	data() {
		return {
			groupIndex: Math.min(Math.max(0, this.start), Math.max(0, this.groups.length - 1)),
			storyIndex: 0,
			/** 0..1 of the current story's time */
			progress: 0,
			timer: null,
			paused: false,
			deleting: false,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {object|undefined} */
		group() {
			return this.groups[this.groupIndex]
		},

		/** @return {object|undefined} */
		story() {
			return this.group?.stories[this.storyIndex]
		},

		/** @return {boolean} whether the story on screen is the reader's own */
		isOwn() {
			return Boolean(this.group?.own)
				|| this.story?.account?.acct === this.accountStore.currentAccount?.acct
		},

		/** @return {string} */
		modalName() {
			const name = this.group?.account?.display_name || this.group?.account?.username || ''

			return t('social', 'Stories of {name}', { name })
		},
	},

	watch: {
		story: {
			handler: 'begin',
			immediate: true,
		},
	},

	beforeUnmount() {
		this.stop()
	},

	methods: {
		t,
		n,

		/** Starts the clock on the story that just came on screen, and tells the server. */
		begin() {
			this.stop()
			this.progress = 0
			if (!this.story) {
				this.$emit('close')

				return
			}

			this.markSeen(this.story)

			// a video runs for as long as it runs; a picture for the seconds
			// its poster chose
			if (this.story.media?.type === 'video') {
				return
			}

			const duration = Math.max(3, Number(this.story.duration) || 5) * 1000
			const started = Date.now()
			let elapsedBeforePause = 0
			let pausedAt = null
			this.timer = window.setInterval(() => {
				if (this.paused) {
					pausedAt ??= Date.now()

					return
				}
				if (pausedAt !== null) {
					elapsedBeforePause += Date.now() - pausedAt
					pausedAt = null
				}
				const elapsed = Date.now() - started - elapsedBeforePause
				this.progress = Math.min(1, elapsed / duration)
				if (elapsed >= duration) {
					this.next()
				}
			}, TICK)
		},

		stop() {
			if (this.timer !== null) {
				window.clearInterval(this.timer)
				this.timer = null
			}
		},

		pause() {
			this.paused = true
		},

		resume() {
			this.paused = false
		},

		/**
		 * @param {object} story the one on screen
		 * @return {Promise<void>}
		 */
		async markSeen(story) {
			if (story.seen || this.isOwn) {
				return
			}

			try {
				await axios.post(generateUrl(`apps/social/api/v1/stories/${story.id}/seen`))
				this.$emit('seen', story.id)
			} catch (error) {
				// a view the server did not record is not worth interrupting
				// the story for
				logger.debug('could not mark the story seen', { error })
			}
		},

		next() {
			if (!this.group) {
				this.$emit('close')

				return
			}
			if (this.storyIndex < this.group.stories.length - 1) {
				this.storyIndex++

				return
			}
			this.nextGroup()
		},

		previous() {
			if (this.storyIndex > 0) {
				this.storyIndex--

				return
			}
			if (this.groupIndex > 0) {
				this.groupIndex--
				this.storyIndex = Math.max(0, this.group.stories.length - 1)

				return
			}
			// the very first: start it over
			this.begin()
		},

		nextGroup() {
			if (this.groupIndex < this.groups.length - 1) {
				this.groupIndex++
				this.storyIndex = 0

				return
			}
			this.$emit('close')
		},

		previousGroup() {
			if (this.groupIndex > 0) {
				this.groupIndex--
				this.storyIndex = 0
			}
		},

		/** @return {Promise<void>} */
		async remove() {
			if (!this.story || this.deleting) {
				return
			}

			this.deleting = true
			const story = this.story
			try {
				await axios.delete(generateUrl(`apps/social/api/v1/stories/${story.id}`))
				showSuccess(t('social', 'Story deleted'))
				this.$emit('deleted', story)
				// the bar takes the story out of the group; if the group is now
				// empty the index points past it and begin() closes the viewer
				if (this.storyIndex >= (this.group?.stories.length ?? 0)) {
					this.storyIndex = Math.max(0, (this.group?.stories.length ?? 1) - 1)
				}
				this.begin()
			} catch (error) {
				logger.error('could not delete the story', { error })
				showError(t('social', 'Could not delete the story'))
			} finally {
				this.deleting = false
			}
		},

		/**
		 * @param {object} story one story
		 * @return {string} how long ago it went up, short
		 */
		ago(story) {
			const then = new Date(story.created_at)
			if (Number.isNaN(then.getTime())) {
				return ''
			}
			const minutes = Math.max(0, Math.round((Date.now() - then.getTime()) / 60000))
			if (minutes < 60) {
				return n('social', '%n minute ago', '%n minutes ago', Math.max(1, minutes))
			}

			return n('social', '%n hour ago', '%n hours ago', Math.round(minutes / 60))
		},
	},
}
</script>

<style scoped lang="scss">
.story-viewer__stage {
	position: relative;
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 60vh;
	color: #fff;
	background: #000;
	user-select: none;
}

.story-viewer__progress {
	display: flex;
	gap: 4px;
	margin: 0;
	padding: 8px 12px 0;
	list-style: none;
}

.story-viewer__segment {
	position: relative;
	flex: 1;
	height: 3px;
	overflow: hidden;
	border-radius: 2px;
	background: rgba(255, 255, 255, 0.35);

	&--done {
		background: #fff;
	}
}

.story-viewer__fill {
	display: block;
	height: 100%;
	background: #fff;
	transition: width 0.1s linear;
}

@media (prefers-reduced-motion: reduce) {
	.story-viewer__fill {
		transition: none;
	}
}

.story-viewer__head {
	position: relative;
	z-index: 2;
	display: flex;
	gap: 10px;
	align-items: center;
	padding: 10px 12px;
}

.story-viewer__author {
	display: inline-flex;
	gap: 8px;
	align-items: center;
	color: #fff;
	font-weight: bold;
}

.story-viewer__when,
.story-viewer__views {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	color: rgba(255, 255, 255, 0.8);
	font-size: 13px;
}

.story-viewer__delete {
	margin-inline-start: auto;
	color: #fff !important;
}

.story-viewer__media {
	display: flex;
	flex: 1;
	align-items: center;
	justify-content: center;
	min-height: 0;
}

.story-viewer__image,
.story-viewer__video {
	max-width: 100%;
	max-height: 80vh;
	object-fit: contain;
}

.story-viewer__gone {
	color: rgba(255, 255, 255, 0.7);
}

.story-viewer__caption {
	position: relative;
	z-index: 2;
	margin: 0;
	padding: 12px 16px 18px;
	text-align: center;
	overflow-wrap: anywhere;
}

// the two halves: invisible, but not to a keyboard
.story-viewer__tap {
	position: absolute;
	top: 60px;
	bottom: 60px;
	width: 35%;
	border: 0;
	background: transparent;
	cursor: pointer;

	&--back {
		inset-inline-start: 0;
	}

	&--forward {
		inset-inline-end: 0;
		width: 65%;
	}

	&:focus-visible {
		outline: 2px solid #fff;
		outline-offset: -4px;
	}
}
</style>
