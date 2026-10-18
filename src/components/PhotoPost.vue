<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		class="photo-post"
		:style="authorStyle"
		:data-social-status="item.id"
		:aria-label="postLabel">
		<p v-if="item.hidden_by_author === true" class="photo-post__note">
			{{ t('social', 'Hidden by the author of the conversation') }}
		</p>
		<header class="photo-post__header">
			<ActorAvatar :actor="item.account" :size="32" />
			<div class="photo-post__byline">
				<span class="photo-post__byline-row">
					<component
						:is="isLocalAccount(item.account) ? 'a' : 'router-link'"
						class="photo-post__author"
						:title="item.account.acct"
						:href="isLocalAccount(item.account) ? localProfileUrl(item.account) : undefined"
						:to="isLocalAccount(item.account) ? undefined : { name: 'profile', params: { account: item.account.acct } }">
						<DisplayName v-if="hasDisplayName" :text="item.account.display_name" :emojis="item.account.emojis" />
						<template v-else>
							{{ item.account.username }}
						</template>
					</component>
					<VerifiedBadge :account="item.account" />
					<span class="photo-post__dot" aria-hidden="true">·</span>
					<button
						type="button"
						class="photo-post__age"
						:data-timestamp="timestamp"
						:title="formattedDate"
						:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })"
						@click="getSinglePostTimeline">
						{{ relativeTimestamp }}
					</button>
				</span>
				<!-- where it was taken, when the poster said: a place is never
				     inferred, so this is only ever what somebody chose to say -->
				<router-link
					v-if="item.place && item.place.id"
					class="photo-post__place"
					:to="{ name: 'place', params: { id: item.place.id } }"
					:title="t('social', 'Posts from {place}', { place: placeLabel })">
					{{ placeLabel }}
				</router-link>
			</div>
			<PostMenu
				v-if="!serverData.public"
				class="photo-post__menu"
				v-bind="menuProps"
				v-on="menuListeners" />
		</header>

		<!-- a cover the reader's own filters drew, or the author's warning, or
		     the reader's choice about sensitive pictures: in the shape the
		     picture will take, so lifting it moves nothing below -->
		<div v-if="filterCovers" class="photo-post__cover" :style="coverStyle">
			<p class="photo-post__cover-text">
				{{ filterLabel }}
			</p>
			<NcButton variant="secondary" @click="filterLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show anyway') }}
			</NcButton>
		</div>
		<div v-else-if="!mediaRevealed" class="photo-post__cover" :style="coverStyle">
			<p v-if="hasSpoiler" class="photo-post__cover-text" dir="auto">
				{{ item.spoiler_text }}
			</p>
			<NcButton
				v-if="mediaRevealable"
				variant="secondary"
				@click="warningLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ hasSpoiler ? t('social', 'Show more') : t('social', 'Show sensitive content') }}
			</NcButton>
			<span v-else class="photo-post__cover-text">
				{{ t('social', 'Sensitive media, hidden by your settings') }}
			</span>
		</div>
		<!-- a press on the picture is half of a double tap, which likes the
		     post; the controls below are what open, comment and share it -->
		<div
			v-else-if="photos.length > 0"
			class="photo-post__media"
			:style="mediaStyle"
			@click="onMediaClick">
			<GalleryCarousel
				v-if="photos.length > 1"
				:attachments="photos"
				:ratio="ratio"
				fit="cover"
				:openable="false" />
			<GalleryMedia
				v-else
				:attachment="photos[0]"
				:ratio="ratio"
				fit="cover"
				:openable="false" />
			<span v-if="heartShown" class="photo-post__heart" aria-hidden="true">
				<Heart :size="96" />
			</span>
		</div>

		<div v-if="!serverData.public" class="photo-post__actions">
			<span
				class="photo-post__action"
				:class="{ 'photo-post__action--refused': refused === 'like' }"
				:title="refusedBecause.like || undefined">
				<!-- one button whose label changes, not two swapped by v-if:
				     unmounting the button someone just pressed drops their focus -->
				<NcButton
					:disabled="!canLike && !isLiked"
					:title="refusedBecause.like || (isLiked ? t('social', 'Undo Like') : t('social', 'Like — hold for a reaction'))"
					:aria-label="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
					:aria-pressed="isLiked ? 'true' : 'false'"
					variant="tertiary"
					:class="{ 'photo-post__action--popped': isLiked && celebrate === 'like' }"
					@click="like"
					@pointerdown="startHold"
					@pointerup="endHold"
					@pointerleave="endHold"
					@pointercancel="endHold"
					@contextmenu.prevent="askForReaction">
					<template #icon>
						<Heart v-if="isLiked" :size="24" fillColor="var(--color-element-error)" />
						<HeartOutline v-else :size="24" />
					</template>
				</NcButton>
			</span>
			<NcButton
				ref="commentButton"
				variant="tertiary"
				:title="t('social', 'Comments')"
				:aria-label="t('social', 'Comments')"
				:aria-expanded="commentsOpen ? 'true' : 'false'"
				@click="openComments">
				<template #icon>
					<CommentOutline :size="24" />
				</template>
			</NcButton>
			<span
				class="photo-post__action"
				:class="{ 'photo-post__action--refused': refused === 'boost' }"
				:title="refusedBecause.boost || undefined">
				<NcButton
					:disabled="!canBoost"
					:title="refusedBecause.boost || (isBoosted ? t('social', 'Undo boost') : t('social', 'Boost'))"
					:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
					:aria-pressed="isBoosted ? 'true' : 'false'"
					variant="tertiary"
					:class="{ 'photo-post__action--spun': celebrate === 'boost' }"
					@click="boost">
					<template #icon>
						<Repeat :size="24" :fillColor="isBoosted ? 'var(--color-primary-element)' : undefined" />
					</template>
				</NcButton>
			</span>
			<NcButton
				variant="tertiary"
				:title="t('social', 'Share')"
				:aria-label="t('social', 'Share')"
				@click="share">
				<template #icon>
					<ShareVariantOutline :size="24" />
				</template>
			</NcButton>
			<NcButton
				class="photo-post__bookmark"
				variant="tertiary"
				:title="item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark')"
				:aria-label="item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark')"
				:aria-pressed="item.bookmarked ? 'true' : 'false'"
				@click="toggleBookmark">
				<template #icon>
					<Bookmark v-if="item.bookmarked" :size="24" />
					<BookmarkOutline v-else :size="24" />
				</template>
			</NcButton>
		</div>

		<!-- the ref is what holding the heart opens; see askForReaction() -->
		<!-- eslint-disable vue/no-unused-refs -->
		<ReactionBar
			ref="reactionBar"
			class="photo-post__reactions"
			:statusId="String(item.id || '')"
			:modelValue="item.reactions || []"
			:canReact="!serverData.public"
			:offerAdd="false"
			@update:modelValue="onReactionsChanged" />
		<!-- eslint-enable vue/no-unused-refs -->

		<p v-if="!hidesCounts && likeCount > 0" class="photo-post__likes">
			{{ n('social', '%n like', '%n likes', likeCount) }}
		</p>

		<PostEditForm
			v-if="isEditing"
			class="photo-post__edit"
			:item="item"
			@close="isEditing = false" />
		<template v-else-if="captionShown">
			<!-- the author's name and the first two lines, run together the way
			     a caption is read; the rest is one press away -->
			<div
				ref="caption"
				class="photo-post__caption"
				:class="{ 'photo-post__caption--clamped': !captionOpen }">
				<component
					:is="isLocalAccount(item.account) ? 'a' : 'router-link'"
					class="photo-post__caption-author"
					:href="isLocalAccount(item.account) ? localProfileUrl(item.account) : undefined"
					:to="isLocalAccount(item.account) ? undefined : { name: 'profile', params: { account: item.account.acct } }">
					<DisplayName v-if="hasDisplayName" :text="item.account.display_name" :emojis="item.account.emojis" />
					<template v-else>
						{{ item.account.username }}
					</template>
				</component>
				<MessageContent class="photo-post__caption-text" :item="displayedItem" />
			</div>
			<button
				v-if="captionClamps && !captionOpen"
				type="button"
				class="photo-post__more"
				@click="captionOpen = true">
				{{ t('social', 'more') }}
			</button>
		</template>
		<p v-if="translation !== null && !filterCovers" class="photo-post__translated">
			{{ translatedFrom === ''
				? t('social', 'Translated by {provider}', { provider: translation.provider || t('social', 'this server') })
				: t('social', 'Translated from {language} by {provider}', {
					language: translatedFrom,
					provider: translation.provider || t('social', 'this server'),
				}) }}
		</p>
		<template v-if="mediaRevealed && !filterCovers">
			<QuotedPost v-if="item.quote" :quote="item.quote" />
			<Poll v-if="localPoll" :poll="localPoll" @update:poll="updatePoll" />
		</template>

		<!-- with the numbers hidden the server sends none, so the way to the
		     comments is offered on every post rather than on none -->
		<button
			v-if="hidesCounts || repliesCount > 0"
			type="button"
			class="photo-post__view-comments"
			@click="openComments">
			{{ hidesCounts
				? t('social', 'View comments')
				: n('social', 'View %n comment', 'View all %n comments', repliesCount) }}
		</button>

		<!-- what the reader has just said here, so a comment does not vanish
		     into a thread they would have to open to see it went out -->
		<ul v-if="sentComments.length > 0" class="photo-post__sent">
			<li v-for="sent in sentComments" :key="sent.id" class="photo-post__sent-item">
				<strong class="photo-post__sent-author">{{ currentAccount?.display_name || currentAccount?.username }}</strong>
				<span dir="auto">{{ sent.text }}</span>
			</li>
		</ul>

		<form
			v-if="!serverData.public && canReply"
			class="photo-post__comment"
			@submit.prevent="sendComment">
			<input
				v-model="comment"
				type="text"
				class="photo-post__comment-input"
				dir="auto"
				:maxlength="instanceStore.maxCharacters"
				:placeholder="t('social', 'Add a comment…')"
				:aria-label="t('social', 'Add a comment')"
				:disabled="sending">
			<button
				v-if="comment.trim() !== ''"
				type="submit"
				class="photo-post__comment-send"
				:disabled="sending">
				{{ t('social', 'Post') }}
			</button>
		</form>

		<time class="photo-post__date" :datetime="item.created_at">
			{{ formattedDate }}
		</time>

		<PhotoComments
			v-if="commentsOpen"
			:item="item"
			@close="closeComments" />
		<PostDialogs v-model:open="dialog" :item="item" :type="type" />
	</article>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { defineAsyncComponent, ref } from 'vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ActorAvatar from './ActorAvatar.vue'
import DisplayName from './DisplayName.js'
import GalleryCarousel from './GalleryCarousel.vue'
import GalleryMedia from './GalleryMedia.vue'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import PostDialogs from './PostDialogs.vue'
import PostEditForm from './PostEditForm.vue'
import PostMenu from './PostMenu.vue'
import QuotedPost from './QuotedPost.vue'
import ReactionBar from './ReactionBar.vue'
import TimelinePost from './TimelinePost.vue'
import VerifiedBadge from './VerifiedBadge.vue'
import { feedRatioOf, unscaledWidth } from './GalleryRatio.js'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'
import { defaultLanguage, rememberedLanguage } from '../utils/postLanguage.js'
import { fullHandle, participantsOf } from '../utils/replyMentions.js'
import { savesData } from '../utils/saveData.js'
import { statusPayload } from '../utils/statusPayload.js'

// the thread is read only when somebody asks for it, and it brings the
// composer with it
const PhotoComments = defineAsyncComponent(() => import(/* webpackChunkName: "photo-comments" */'./PhotoComments.vue'))

/**
 * Whose comments are open, by post id; '' when none are. One panel at a time
 * for the whole page, so opening another post's comments closes these.
 */
const openThread = ref('')

/** two presses on the picture closer together than this are a double tap */
const DOUBLE_TAP_MS = 350

/** how long the heart a double tap draws stays on the picture */
const HEART_MS = 900

/**
 * How far below the screen a post starts fetching its pictures: about two
 * screens ahead of the reader, which is the next few posts.
 */
const PRELOAD_AHEAD = '0px 0px 200% 0px'

/**
 * A post on the Photos page, drawn the way a photo feed is read: the
 * picture full width, the actions under it, then who liked it, the caption
 * and the way to the comments.
 *
 * Everything a post does is `TimelinePost`'s — liking, boosting, the
 * keyboard shortcuts, the menu and its dialogs, the covers for content
 * warnings, filters and sensitive media — so this is that component with
 * another template, and the two cannot drift apart in what they do.
 */
export default {
	name: 'PhotoPost',

	components: {
		ActorAvatar,
		Bookmark,
		BookmarkOutline,
		CommentOutline,
		DisplayName,
		EyeOff,
		GalleryCarousel,
		GalleryMedia,
		Heart,
		HeartOutline,
		MessageContent,
		NcButton,
		PhotoComments,
		Poll,
		PostDialogs,
		PostEditForm,
		PostMenu,
		QuotedPost,
		ReactionBar,
		Repeat,
		ShareVariantOutline,
		VerifiedBadge,
	},

	extends: TimelinePost,

	// `setup` is not inherited through `extends`: what TimelinePost's gives
	// it, and what a comment needs to address its mentions
	setup() {
		const { serverData, hostname } = useServerData()
		const { currentUser, username } = useCurrentUser()

		return { serverData, currentUser, hostname, username }
	},

	data() {
		return {
			/** when the picture was last pressed, for telling a double tap */
			lastTap: 0,
			/** whether the heart of a double tap is on the picture */
			heartShown: false,
			heartTimer: 0,
			/** whether the whole caption is shown */
			captionOpen: false,
			/** whether the caption runs past the two lines it is shown at */
			captionClamps: false,
			/** what is typed into the comment field */
			comment: '',
			/** while a comment is on its way */
			sending: false,
			/** @type {Array<{id: string, text: string}>} the comments sent from here */
			sentComments: [],
			/** @type {IntersectionObserver|null} */
			preloadObserver: null,
		}
	},

	computed: {
		/** @return {import('../types/Mastodon.js').MediaAttachment[]} what can be drawn, in the post's order */
		photos() {
			return (this.item.media_attachments ?? []).filter((attachment) => attachment?.type !== 'unknown')
		},

		/** @return {number} the shape every picture of the post is shown in: the first one's, held to the feed's range */
		ratio() {
			return this.photos.length > 0 ? feedRatioOf(this.photos[0]) : 1
		},

		/**
		 * The pictures fill the column, except where that would stretch one
		 * past its own pixels: a small picture is drawn at its own size,
		 * centred, rather than blown up into a blur.
		 *
		 * @return {object|undefined}
		 */
		mediaStyle() {
			const widest = Math.min(...this.photos.map((photo) => unscaledWidth(photo, this.ratio)))

			return Number.isFinite(widest) ? { '--photo-max': `${widest}px` } : undefined
		},

		/** @return {object} the shape a cover takes: the picture's own */
		coverStyle() {
			return { aspectRatio: String(this.ratio) }
		},

		/** @return {number} */
		likeCount() {
			return Number(this.item.favourites_count) || 0
		},

		/** @return {number} */
		repliesCount() {
			return Number(this.item.replies_count) || 0
		},

		/** @return {boolean} whether the caption is on the page: never under a cover */
		captionShown() {
			return Boolean(this.item.content) && !this.filterCovers && (!this.hasSpoiler || this.warningLifted)
		},

		/** @return {boolean} */
		commentsOpen() {
			return openThread.value !== '' && openThread.value === String(this.item.id)
		},
	},

	watch: {
		displayedItem() {
			this.$nextTick(() => this.measureCaption())
		},

		captionShown() {
			this.$nextTick(() => this.measureCaption())
		},
	},

	mounted() {
		this.measureCaption()
		this.watchForPreload()
	},

	beforeUnmount() {
		window.clearTimeout(this.heartTimer)
		this.preloadObserver?.disconnect()
		if (this.commentsOpen) {
			openThread.value = ''
		}
	},

	methods: {
		/**
		 * Two presses on the picture in quick succession like the post. A
		 * second double tap does nothing more: it never takes the like back,
		 * which is what the heart below the picture is for.
		 *
		 * @param {MouseEvent} event a press on the picture
		 */
		onMediaClick(event) {
			// the carousel's arrows and dots, the ALT badge, a video's own controls
			if (this.pressedControl(event)) {
				return
			}

			const now = Date.now()
			if (now - this.lastTap > DOUBLE_TAP_MS) {
				this.lastTap = now
				return
			}

			this.lastTap = 0
			this.likeByDoubleTap()
		},

		likeByDoubleTap() {
			if (!this.canLike && !this.isLiked) {
				return
			}

			this.heartShown = true
			window.clearTimeout(this.heartTimer)
			this.heartTimer = window.setTimeout(() => {
				this.heartShown = false
			}, HEART_MS)

			if (!this.isLiked) {
				this.like()
			}
		},

		/** `r`, and anything else that asks to reply: here that is the comments. */
		reply() {
			this.openComments()
		},

		openComments() {
			openThread.value = String(this.item.id)
		},

		/** Closes the comments and gives the focus back to what opened them. */
		closeComments() {
			if (this.commentsOpen) {
				openThread.value = ''
			}
			this.$nextTick(() => {
				const button = /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.commentButton)
				button?.$el?.focus()
			})
		},

		/**
		 * Hands the post to whatever the reader's device shares with, and
		 * copies its address where there is nothing to hand it to.
		 *
		 * @return {Promise<void>}
		 */
		async share() {
			const url = this.item.url || this.item.uri || ''
			if (url === '') {
				return
			}

			if (typeof navigator.share === 'function') {
				try {
					await navigator.share({ url })
				} catch (error) {
					// the reader closing the share sheet is not a failure
					if (error?.name !== 'AbortError') {
						logger.debug('Could not share the post', { error })
					}
				}
				return
			}

			try {
				await navigator.clipboard.writeText(url)
				showSuccess(t('social', 'Link copied'))
			} catch (error) {
				logger.debug('Could not copy the link of a post', { error })
				showError(t('social', 'Could not copy the link'))
			}
		},

		/**
		 * Sends what is in the comment field as a reply to the post: to the
		 * people the post's own composer would address, in the post's
		 * audience, the way the reply composer sends one.
		 *
		 * @return {Promise<void>}
		 */
		async sendComment() {
			const text = this.comment.trim()
			if (text === '' || this.sending) {
				return
			}

			const mentions = participantsOf(this.item, this.username, this.hostname)
				.map((account) => `@${fullHandle(account, this.hostname)} `)
				.join('')

			this.sending = true
			let created
			try {
				created = await this.timelineStore.post(statusPayload({
					text: mentions + text,
					warning: '',
					mediaIds: [],
					inReplyToId: this.item.id,
					visibility: this.item.visibility,
					postAs: '',
					language: rememberedLanguage() || defaultLanguage(),
					video: null,
					place: null,
					scheduledAt: null,
					poll: null,
				}))
			} finally {
				this.sending = false
			}

			// the store has said what went wrong, and the words stay in the field
			if (created === undefined) {
				return
			}

			this.comment = ''
			if (created.held_for_review === true) {
				return
			}

			this.sentComments.push({ id: String(created.id), text })
			this.timelineStore.updateStatus({ ...this.item, replies_count: this.repliesCount + 1 })
			eventBus.emit('post-published', created)
		},

		/** Whether the caption runs past its two lines, which is when "more" is offered. */
		measureCaption() {
			const caption = /** @type {HTMLElement|undefined} */ (this.$refs.caption)
			this.captionClamps = caption !== undefined && caption.scrollHeight > caption.clientHeight + 1
		},

		/**
		 * Fetches the post's pictures — every one of a carousel, not only the
		 * one on show — once it is a couple of screens away, so they are there
		 * by the time the reader is. Not for a reader who asked to save data:
		 * the pictures still arrive as they come into view.
		 */
		watchForPreload() {
			if (typeof IntersectionObserver === 'undefined' || savesData() || !this.$el) {
				return
			}

			this.preloadObserver = new IntersectionObserver((entries) => {
				if (entries.some((entry) => entry.isIntersecting)) {
					this.preloadObserver?.disconnect()
					this.preloadObserver = null
					this.preload()
				}
			}, { rootMargin: PRELOAD_AHEAD })
			this.preloadObserver.observe(this.$el)
		},

		/** Asks the browser for each picture, so the ones on screen come from its cache. */
		preload() {
			// a covered post keeps its pictures unfetched until it is lifted
			if (!this.mediaRevealed || this.filterCovers) {
				return
			}

			for (const photo of this.photos) {
				if (photo.type === 'image' && typeof photo.preview_url === 'string' && photo.preview_url !== '') {
					const image = new Image()
					image.decoding = 'async'
					image.src = photo.preview_url
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.photo-post {
	position: relative;
	/* Instagram's column is 470 pixels; a little more here, where the
	   pictures share the column with a caption that is often longer */
	max-width: 600px;
	margin: 0 auto;
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-main-text);

	&__note {
		margin: 0 0 4px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__header {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 10px;
	}

	&__byline {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
	}

	&__byline-row {
		display: flex;
		align-items: center;
		gap: 4px;
		min-width: 0;
	}

	&__author,
	&__caption-author {
		font-weight: 650;
		color: var(--color-main-text);

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__author {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__dot {
		color: var(--color-text-maxcontrast);
	}

	&__age {
		flex-shrink: 0;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: none;
		background: none;
		font: inherit;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
		cursor: pointer;

		&:hover {
			background: none;
			color: var(--color-primary-element);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__place {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: 12px;
		color: var(--color-text-maxcontrast);

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__menu {
		flex-shrink: 0;
	}

	&__media,
	&__cover {
		position: relative;
		max-width: var(--photo-max, none);
		margin-inline: auto;
		border-radius: var(--border-radius-large, 8px);
		overflow: hidden;
		background: var(--color-background-dark);
		touch-action: manipulation;
		user-select: none;
	}

	/* the frame is the shape the feed gives it, at the column's width; the
	   height cap the gallery keeps for a timeline would undo the shape */
	&__media :deep(.photo),
	&__media :deep(.gallery__stage) {
		max-height: none;
		border-radius: 0;
	}

	&__media :deep(.gallery) {
		margin: 0;
	}

	&__media :deep(.gallery__pager) {
		justify-content: center;
		padding-block: 6px;
	}

	&__cover {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		padding: 20px;
		text-align: center;
	}

	&__cover-text {
		margin: 0;
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	&__heart {
		position: absolute;
		inset: 0;
		/* over the picture and its placeholder, which stack themselves */
		z-index: 5;
		display: flex;
		align-items: center;
		justify-content: center;
		pointer-events: none;
		color: #fff;
		filter: drop-shadow(0 2px 12px rgb(0 0 0 / 45%));
		animation: photo-heart .9s cubic-bezier(.2, .9, .3, 1.2) both;
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: 2px;
		margin: 4px -8px 0;
	}

	&__bookmark {
		margin-inline-start: auto;
	}

	&__action {
		position: relative;

		&--refused :deep(button) {
			animation: photo-refused .4s ease;
		}
	}

	&__action--popped :deep(.material-design-icon) {
		animation: photo-pop .45s cubic-bezier(.34, 1.56, .64, 1);
	}

	&__action--spun :deep(.material-design-icon) {
		animation: photo-spin .5s cubic-bezier(.4, 0, .2, 1);
	}

	&__reactions {
		margin-block: 4px 0;
	}

	&__likes {
		margin: 2px 0 4px;
		font-weight: 650;
	}

	&__caption {
		overflow-wrap: break-word;

		/* the name and the words are one run of text, the way a caption
		   reads, and the clamp counts its two lines across both */
		:deep(.photo-post__caption-text),
		:deep(.photo-post__caption-text p) {
			display: inline;
		}

		:deep(.photo-post__caption-text p + p)::before {
			content: ' ';
		}

		:deep(a:not(.photo-post__caption-author)) {
			color: var(--color-primary-element);
		}

		&-author {
			margin-inline-end: 4px;
		}

		&--clamped {
			display: -webkit-box;
			-webkit-box-orient: vertical;
			-webkit-line-clamp: 2;
			line-clamp: 2;
			overflow: hidden;
		}
	}

	&__more,
	&__view-comments {
		display: block;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: none;
		background: none;
		font: inherit;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
		cursor: pointer;

		&:hover {
			background: none;
			color: var(--color-main-text);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__view-comments {
		margin-top: 4px;
	}

	&__translated {
		margin: 4px 0 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__sent {
		margin: 4px 0 0;
		padding: 0;
		list-style: none;
	}

	&__sent-author {
		margin-inline-end: 4px;
	}

	&__comment {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 6px;
		border-block-end: 1px solid var(--color-border);
	}

	&__comment-input {
		flex: 1 1 auto;
		min-width: 0;
		margin: 0;
		padding: 6px 0;
		border: none;
		border-radius: 0;
		background: transparent;
		color: var(--color-main-text);
		font: inherit;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__comment-send {
		min-height: 0;
		margin: 0;
		padding: 4px;
		border: none;
		background: none;
		font: inherit;
		font-weight: 650;
		color: var(--color-primary-element);
		cursor: pointer;

		&:hover {
			background: none;
			color: var(--color-primary-element-hover);
		}

		&:disabled {
			opacity: .5;
			cursor: default;
		}
	}

	&__date {
		display: block;
		margin-top: 6px;
		font-size: 11px;
		letter-spacing: .02em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}
}

/* on a phone the row is the screen's width (TimelineEntry): the words keep a
   margin and the pictures run from one edge to the other, except a small one,
   which stays its own size, centred */
@include layout.below(layout.$phone) {
	.photo-post {
		padding-inline: 12px;
	}

	.photo-post__media,
	.photo-post__cover {
		width: min(100% + 24px, var(--photo-max, 100vw));
		max-width: none;
		margin-inline: calc((100% - min(100% + 24px, var(--photo-max, 100vw))) / 2);
		border-radius: 0;
	}
}

/* the heart grows out of the picture, holds, and fades */
@keyframes photo-heart {
	0% { opacity: 0; transform: scale(.3); }
	20% { opacity: 1; transform: scale(1.15); }
	35% { transform: scale(.95); }
	50% { transform: scale(1); }
	80% { opacity: 1; }
	100% { opacity: 0; transform: scale(1); }
}

@keyframes photo-pop {
	0% { transform: scale(1); }
	40% { transform: scale(1.35); }
	70% { transform: scale(.92); }
	100% { transform: scale(1); }
}

@keyframes photo-spin {
	0% { transform: rotate(0); }
	100% { transform: rotate(360deg); }
}

@keyframes photo-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

/* the heart still says the post was liked; it only stops moving */
@media (prefers-reduced-motion: reduce) {
	.photo-post__heart,
	.photo-post__action--refused :deep(button),
	.photo-post__action--popped :deep(.material-design-icon),
	.photo-post__action--spun :deep(.material-design-icon) {
		animation: none;
	}
}
</style>
