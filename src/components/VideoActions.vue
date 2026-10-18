<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="video-actions" role="group" :aria-label="t('social', 'Video actions')">
		<!-- like and dislike are one pill, as the two halves of one opinion -->
		<div v-if="signedIn" class="video-actions__pill video-actions__pill--split">
			<button
				v-if="canLike"
				type="button"
				class="video-actions__button video-actions__like"
				:aria-pressed="liked ? 'true' : 'false'"
				:aria-label="liked ? t('social', 'Unlike') : t('social', 'Like')"
				:title="liked ? t('social', 'Unlike') : t('social', 'Like')"
				@click="toggleLike">
				<ThumbUp v-if="liked" :size="20" />
				<ThumbUpOutline v-else :size="20" />
				<span v-if="likeCount !== ''" class="video-actions__count">{{ likeCount }}</span>
			</button>
			<button
				type="button"
				class="video-actions__button video-actions__dislike"
				:aria-pressed="disliked ? 'true' : 'false'"
				:aria-label="disliked ? t('social', 'Undo dislike') : t('social', 'Dislike')"
				:title="disliked ? t('social', 'Undo dislike') : t('social', 'Dislike')"
				:disabled="disliking"
				@click="toggleDislike">
				<ThumbDown v-if="disliked" :size="20" />
				<ThumbDownOutline v-else :size="20" />
				<span v-if="dislikeCount !== ''" class="video-actions__count">{{ dislikeCount }}</span>
			</button>
		</div>

		<button
			v-if="signedIn && canBoost"
			type="button"
			class="video-actions__pill video-actions__button video-actions__boost"
			:class="{ 'video-actions__button--on': boosted }"
			:aria-pressed="boosted ? 'true' : 'false'"
			@click="toggleBoost">
			<Repeat :size="20" />
			<span>{{ boosted ? t('social', 'Boosted') : t('social', 'Boost') }}</span>
		</button>

		<button
			v-if="link !== ''"
			type="button"
			class="video-actions__pill video-actions__button video-actions__share"
			@click="share">
			<ShareVariantOutline :size="20" />
			<span>{{ t('social', 'Share') }}</span>
		</button>

		<button
			v-if="signedIn"
			type="button"
			class="video-actions__pill video-actions__button video-actions__save"
			:aria-pressed="saved ? 'true' : 'false'"
			@click="toggleSave">
			<Bookmark v-if="saved" :size="20" />
			<BookmarkOutline v-else :size="20" />
			<span>{{ saved ? t('social', 'Saved') : t('social', 'Save') }}</span>
		</button>

		<a
			v-if="downloadUrl !== ''"
			class="video-actions__pill video-actions__button video-actions__download"
			:href="downloadUrl"
			download>
			<TrayArrowDown :size="20" />
			<span>{{ t('social', 'Download') }}</span>
		</a>

		<!-- only where there is a column beside the player to give up -->
		<button
			type="button"
			class="video-actions__pill video-actions__button video-actions__theater"
			:aria-pressed="theater ? 'true' : 'false'"
			:aria-label="t('social', 'Theater mode')"
			:title="t('social', 'Theater mode')"
			@click="$emit('toggleTheater')">
			<ArrowCollapseHorizontal v-if="theater" :size="20" />
			<ArrowExpandHorizontal v-else :size="20" />
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import ArrowCollapseHorizontal from 'vue-material-design-icons/ArrowCollapseHorizontal.vue'
import ArrowExpandHorizontal from 'vue-material-design-icons/ArrowExpandHorizontal.vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ThumbDownOutline from 'vue-material-design-icons/ThumbDownOutline.vue'
import ThumbUp from 'vue-material-design-icons/ThumbUp.vue'
import ThumbUpOutline from 'vue-material-design-icons/ThumbUpOutline.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useServerData } from '../composables/useServerData.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'
import { isLocalAccount } from '../utils/accountLocality.js'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { allowsDownload, compactCount, videoAttachmentOf } from '../utils/videoCard.js'

/**
 * The row of pills under a video: like and dislike, boost, share, save, and
 * download where the author allows it, then the switch to the wide player.
 *
 * Each does what the same button does under a post — the same store actions,
 * the same author's policy deciding whether it is offered — drawn the way a
 * video site draws them. A reader who is not signed in can share and
 * download, and nothing else.
 */
export default {
	name: 'VideoActions',

	components: {
		ArrowCollapseHorizontal,
		ArrowExpandHorizontal,
		Bookmark,
		BookmarkOutline,
		Repeat,
		ShareVariantOutline,
		ThumbDown,
		ThumbDownOutline,
		ThumbUp,
		ThumbUpOutline,
		TrayArrowDown,
	},

	props: {
		/** The video post, as the store holds it. */
		status: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},

		/** Whether the wide player is on. */
		theater: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggleTheater'],

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** a dislike of ours is in flight */
			disliking: false,
			/** a boost of ours is in flight */
			boosting: false,
		}
	},

	computed: {
		...mapStores(useSettingsStore, useTimelineStore),

		/** @return {boolean} */
		signedIn() {
			return !this.serverData.public
		},

		/** @return {boolean} whether the reader has the counts hidden */
		hidesCounts() {
			return this.settingsStore.hidesCounts
		},

		/** @return {boolean} */
		liked() {
			return this.status.favourited === true
		},

		/** @return {boolean} */
		disliked() {
			return this.status.disliked === true
		},

		/** @return {boolean} */
		boosted() {
			return this.status.reblogged === true
		},

		/** @return {boolean} */
		saved() {
			return this.status.bookmarked === true
		},

		/** @return {boolean} whether the author lets this post be liked */
		canLike() {
			return allowedByAuthor(this.status, 'like')
		},

		/** @return {boolean} public or unlisted, and the author lets it be boosted */
		canBoost() {
			return isShareable(this.status) && allowedByAuthor(this.status, 'boost')
		},

		/** @return {string} the likes, '' when hidden or not counted */
		likeCount() {
			return this.countOf(this.status.favourites_count)
		},

		/**
		 * The dislikes: this server's own count of the `Dislike` activities it
		 * received where it has one, the number the origin stated otherwise.
		 *
		 * @return {string}
		 */
		dislikeCount() {
			return this.countOf(this.status.dislikes_count || this.status.video?.dislikes || 0)
		},

		/** @return {string} the address that opens the video, to hand on */
		link() {
			return String(this.status.url || this.status.uri || '')
		},

		/** @return {string} the file, where the author lets it be saved */
		downloadUrl() {
			if (!allowsDownload(this.status, isLocalAccount(this.status.account))) {
				return ''
			}

			return String(videoAttachmentOf(this.status)?.url ?? '')
		},
	},

	methods: {
		t,

		/**
		 * @param {number|null|undefined} count a count from the status
		 * @return {string} it as a video site writes it, '' when the reader hides counts or there is none
		 */
		countOf(count) {
			if (this.hidesCounts || typeof count !== 'number' || count <= 0) {
				return ''
			}

			return compactCount(count)
		},

		toggleLike() {
			this.timelineStore[this.liked ? 'postUnlike' : 'postLike']({ status: this.status })
		},

		async toggleDislike() {
			if (this.disliking) {
				return
			}
			this.disliking = true
			try {
				await this.timelineStore[this.disliked ? 'postUndislike' : 'postDislike']({ status: this.status })
			} finally {
				this.disliking = false
			}
		},

		async toggleBoost() {
			if (this.boosting) {
				return
			}
			this.boosting = true
			try {
				await this.timelineStore[this.boosted ? 'postUnBoost' : 'postBoost']({ status: this.status })
			} finally {
				this.boosting = false
			}
		},

		toggleSave() {
			this.timelineStore.postBookmark({ status: this.status, bookmarked: !this.saved })
		},

		/**
		 * Hands the video to whatever the reader's device shares with, and
		 * copies its address where there is nothing to hand it to.
		 *
		 * @return {Promise<void>}
		 */
		async share() {
			if (typeof navigator.share === 'function') {
				try {
					await navigator.share({ url: this.link })
				} catch (error) {
					// the reader closing the share sheet is not a failure
					if (error?.name !== 'AbortError') {
						logger.debug('Could not share the video', { error })
					}
				}
				return
			}

			try {
				await navigator.clipboard.writeText(this.link)
				showSuccess(t('social', 'Link copied'))
			} catch (error) {
				logger.debug('Could not copy the link of a video', { error })
				showError(t('social', 'Could not copy the link'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.video-actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.video-actions__pill {
	display: inline-flex;
	align-items: stretch;
	min-block-size: var(--default-clickable-area);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	overflow: hidden;
}

.video-actions__button {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
	min-block-size: var(--default-clickable-area);
	margin: 0;
	padding: 0 calc(var(--default-grid-baseline) * 4);
	border: none;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-darker);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&:disabled {
		cursor: default;
		opacity: .6;
	}

	&[aria-pressed='true'],
	&--on {
		color: var(--color-primary-element);
	}
}

/* the two halves share the pill's ground; the line between them is the only seam */
.video-actions__pill--split .video-actions__button {
	border-radius: 0;
}

.video-actions__like + .video-actions__dislike {
	border-inline-start: 1px solid var(--color-border-dark);
}

.video-actions__count {
	font-variant-numeric: tabular-nums;
}

.video-actions__theater {
	padding: 0 calc(var(--default-grid-baseline) * 3);
}

/* one column already: there is nothing beside the player to make room from */
@include layout.below(layout.$wide) {
	.video-actions__theater {
		display: none;
	}
}
</style>
