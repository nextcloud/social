<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article class="post-content" :data-social-status="item.id" :aria-label="postLabel">
		<div class="post-header">
			<div class="post-author-wrapper" :title="item.account.acct">
				<router-link
					v-if="item.account"
					:to="{ name: 'profile',
						params: { account: item.account.acct },
					}">
					<span class="post-author">
						<DisplayName :text="item.account.display_name" :emojis="item.account.emojis" />
					</span>
					<span class="post-author-id">
						@{{ item.account.username }}
					</span>
					<span
						v-if="!origin.local"
						class="post-instance"
						:style="{ '--instance-colour': origin.colour }"
						:title="t('social', 'Posted from {instance}', { instance: origin.instance })">
						{{ origin.instance }}
					</span>
				</router-link>
			</div>
			<button
				:data-timestamp="timestamp"
				type="button"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</button>
			<span v-if="item.pinned" class="post-pinned" :title="t('social', 'Pinned post')">
				<Pin :size="14" />
				{{ t('social', 'Pinned') }}
			</span>
			<!-- the byline is 12px text; a 22px globe beside it read as the
			     loudest thing in the row, and it is the least important -->
			<VisibilityIcon
				v-if="visibility"
				:title="visibility.text"
				class="post-visibility"
				:size="14"
				:visibility="visibility.id" />
		</div>
		<div v-if="isEditing" class="post-edit-inline">
			<input
				v-model="editSpoiler"
				type="text"
				class="post-edit-warning"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')">
			<textarea
				ref="editInput"
				v-model="editContent"
				class="post-edit-textarea"
				:maxlength="MAX_LENGTH"
				:aria-describedby="editIsTooLong ? `post-edit-count-${item.id}` : undefined"
				:placeholder="t('social', 'Edit your post')"
				@keydown.ctrl.enter="saveEdit" />
			<div class="post-edit-actions">
				<span
					:id="`post-edit-count-${item.id}`"
					class="post-edit-count"
					:class="{ 'post-edit-count--over': editIsTooLong }"
					role="status">
					{{ editCharactersLeftLabel }}
				</span>
				<NcButton
					variant="primary"
					:disabled="!editCanSave"
					:aria-label="t('social', 'Save')"
					@click="saveEdit">
					{{ t('social', 'Save') }}
				</NcButton>
				<NcButton
					:aria-label="t('social', 'Cancel')"
					@click="cancelEdit">
					{{ t('social', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		<!--
		  A content warning covers the post, not only its text: the pictures,
		  the poll and the link preview used to be siblings rendered
		  unconditionally, so the one thing the feature exists to prevent
		  happened anyway.
		-->
		<div v-else-if="hasSpoiler" class="post-warning">
			<p class="post-warning__text">
				{{ item.spoiler_text }}
			</p>
			<NcButton
				variant="secondary"
				:aria-expanded="warningLifted ? 'true' : 'false'"
				@click="warningLifted = !warningLifted">
				{{ warningLifted ? t('social', 'Show less') : t('social', 'Show more') }}
			</NcButton>
			<div v-if="warningLifted" class="post-message post-message--behind-warning">
				<MessageContent v-if="item.content" :item="item" />
			</div>
		</div>
		<!--
		  A post carrying pictures is read pictures first: they lead at the
		  width of the card and the text reads as their caption. A warned post
		  is not, because its cover has to come before anything it covers.
		-->
		<template v-else-if="mediaLeads">
			<PostAttachment
				v-if="mediaRevealed"
				mediaFirst
				:attachments="item.media_attachments || []" />
			<div v-else class="post-sensitive post-sensitive--leading">
				<NcButton
					variant="secondary"
					@click="warningLifted = true">
					<template #icon>
						<EyeOff :size="20" />
					</template>
					{{ t('social', 'Show sensitive content') }}
				</NcButton>
			</div>
			<div v-if="item.content" class="post-message post-message--caption">
				<MessageContent :item="item" />
			</div>
		</template>
		<div v-else-if="item.content" class="post-message">
			<MessageContent :item="item" />
		</div>
		<template v-if="mediaRevealed">
			<QuotedPost v-if="item.quote" :quote="item.quote" />
			<Poll v-if="localPoll" :poll="localPoll" @update:poll="updatePoll" />
			<PostAttachment v-if="hasAttachments && !mediaLeads" :attachments="item.media_attachments || []" />
			<PostCard v-if="showCard" :card="item.card" />
		</template>
		<!-- not when there is a content warning: that already renders a
		     "Show more" for the very same flag, so a post with both offered
		     two buttons for one reveal. No aria-expanded either — this
		     control is gone the moment it would have to say "true". And not
		     when the media leads, which shows this same reveal in the place
		     the pictures will take. -->
		<div v-else-if="!hasSpoiler && !mediaLeads" class="post-sensitive">
			<NcButton
				variant="secondary"
				@click="warningLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show sensitive content') }}
			</NcButton>
		</div>
		<!-- The row is revealed by the pointer and the card grows to make
		     room for it. The grid row going from 0fr to 1fr is the one way
		     to animate to a height nobody can know in advance, and the
		     dialogs below stay outside it: a box collapsing to nothing is
		     no place to put a modal. -->
		<div
			v-if="$route && $route.params.type !== 'notifications' && !serverData.public"
			class="post-actions-reveal"
			:class="{ 'post-actions-reveal--held': menuOpen }">
			<div class="post-actions">
				<div class="post-action-group">
					<NcButton
						:title="t('social', 'Reply')"
						:aria-label="t('social', 'Reply')"
						variant="tertiary"
						@click="reply">
						<template #icon>
							<Reply :size="20" />
						</template>
					</NcButton>
					<RollingCount :count="item.replies_count || 0" />
				</div>
				<div
					class="post-action-group"
					:class="{ 'post-action-group--refused': refused === 'boost' }">
					<NcButton
						v-if="item.visibility === 'public' || item.visibility === 'unlisted'"
						:title="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
						:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
						:aria-pressed="isBoosted ? 'true' : 'false'"
						variant="tertiary"
						:class="{ 'post-action--spun': celebrate === 'boost' }"
						@click="boost">
						<template #icon>
							<Repeat :size="20" :fillColor="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
						</template>
					</NcButton>
					<RollingCount :count="item.reblogs_count || 0" />
				</div>
				<div
					class="post-action-group post-action-group--like"
					:class="{ 'post-action-group--refused': refused === 'like' }">
					<span v-if="celebrate === 'like'" class="post-action__burst" aria-hidden="true" />
					<!-- one button whose label changes, not two swapped by v-if:
					     unmounting the button someone just pressed drops their focus
					     to the body and loses their place in the timeline -->
					<NcButton
						:title="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
						:aria-label="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
						:aria-pressed="isLiked ? 'true' : 'false'"
						variant="tertiary"
						:class="{ 'post-action--popped': isLiked && celebrate === 'like' }"
						@click="like">
						<template #icon>
							<Heart v-if="isLiked" :size="20" fillColor="var(--color-element-error)" />
							<HeartOutline v-else :size="20" />
						</template>
					</NcButton>
					<RollingCount :count="item.favourites_count || 0" />
				</div>
				<!-- the menu opens in a portal, so the pointer leaving the card
				     while it is open would take the row it belongs to away -->
				<NcActions @update:open="menuOpen = $event">
					<NcActionButton v-if="canQuote" @click="quote">
						<template #icon>
							<FormatQuoteClose :size="20" />
						</template>
						{{ t('social', 'Quote') }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct"
						icon="icon-rename"
						@click="editPost">
						{{ t('social', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct === currentAccount?.acct"
						icon="icon-delete"
						@click="showDeleteDialog = true">
						{{ t('social', 'Delete') }}
					</NcActionButton>
					<!-- NcActionLink sets rel="nofollow noreferrer noopener" itself -->
					<NcActionLink
						v-if="!origin.local && item.url"
						:href="item.url"
						target="_blank">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('social', 'Open on original instance') }}
					</NcActionLink>
					<NcActionButton @click="toggleBookmark">
						<template #icon>
							<Bookmark v-if="item.bookmarked" :size="20" />
							<BookmarkOutline v-else :size="20" />
						</template>
						{{ item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark') }}
					</NcActionButton>
					<NcActionButton
						v-if="canPin"
						@click="togglePin">
						<template #icon>
							<Pin v-if="!item.pinned" :size="20" />
							<PinOff v-else :size="20" />
						</template>
						{{ item.pinned ? t('social', 'Unpin from profile') : t('social', 'Pin to profile') }}
					</NcActionButton>
					<NcActionButton
						v-if="item.account.acct !== currentAccount?.acct"
						@click="showReportDialog = true">
						<template #icon>
							<Flag :size="20" />
						</template>
						{{ t('social', 'Report') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>
		<NcDialog
			v-model:open="showReportDialog"
			:name="t('social', 'Report {account}', { account: item.account.acct })"
			:buttons="reportButtons">
			<p class="report-hint">
				{{ t('social', 'The report goes to the moderators of this instance. It is never sent to the reported account or their server.') }}
			</p>
			<textarea
				v-model="reportComment"
				class="report-comment"
				:placeholder="t('social', 'Why are you reporting this post? (optional)')"
				rows="3" />
		</NcDialog>
		<!-- deleting is irreversible and federates: it is not something to
		     do on the first click of a menu item sitting under "Edit" -->
		<NcDialog
			v-model:open="showDeleteDialog"
			:name="t('social', 'Delete this post?')"
			:buttons="deleteButtons">
			<p class="delete-hint">
				{{ t('social', 'The post is removed from this server and a deletion is sent to every server that received it. This cannot be undone.') }}
			</p>
		</NcDialog>
	</article>
</template>

<script>

// side-effect imports: they register the mention plugin and the string
// interface that the rendered content relies on
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import 'linkify-plugin-mention'
import 'linkify-string'
import PostAttachment from './PostAttachment.vue'
import PostCard from './PostCard.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Flag from 'vue-material-design-icons/Flag.vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { onTick } from '../services/clock.js'
import { originOf } from '../utils/instanceIdentity.js'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import QuotedPost from './QuotedPost.vue'
import RollingCount from './RollingCount.vue'
import DisplayName from './DisplayName.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'

/** what the server accepts in one status, the same limit the composer shows */
const MAX_LENGTH = 500

export default {
	name: 'TimelinePost',
	components: {
		PostAttachment,
		PostCard,
		NcActions,
		NcActionButton,
		NcActionLink,
		NcDialog,
		EyeOff,
		OpenInNew,
		Flag,
		NcButton,
		Bookmark,
		BookmarkOutline,
		Pin,
		PinOff,
		FormatQuoteClose,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
		Poll,
		QuotedPost,
		RollingCount,
		DisplayName,
		VisibilityIcon,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},

		type: {
			type: String,
			required: true,
		},
	},

	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},

	data() {
		return {
			MAX_LENGTH,
			isEditing: false,
			/** which action is playing its confirmation, '' when none */
			celebrate: '',
			/** which action the server refused, so the button can say so */
			refused: '',
			/** whether j/k has this post, so l/b/r act on the right one */
			hasKeyboardFocus: false,
			/** whether the overflow menu is open, which holds the action row open with it */
			menuOpen: false,
			/** a warned post stays closed until the reader opens it */
			warningLifted: false,
			editContent: '',
			editSpoiler: '',
			showReportDialog: false,
			showDeleteDialog: false,
			reportComment: '',
			localPoll: this.item?.poll ?? null,
			/** re-read from the shared clock, so "5 minutes ago" stays true */
			now: Date.now(),
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		/** @return {boolean} the author asked for the post to be covered */
		hasSpoiler() {
			return Boolean(this.item.spoiler_text)
		},

		/** @return {boolean} anything a warning is supposed to cover */
		hasMedia() {
			return this.hasAttachments || this.localPoll !== null || this.showCard || this.hasQuote
		},

		/** @return {boolean} the post embeds another one, whatever came of it */
		hasQuote() {
			return Boolean(this.item.quote)
		},

		/**
		 * @return {boolean} whether the media sits behind a reveal. A warning
		 * covers the whole post; `sensitive` on its own covers only the media,
		 * which is what Mastodon shows for a post flagged without a warning.
		 */
		hasGatedMedia() {
			return (this.hasSpoiler || this.item.sensitive === true) && this.hasMedia
		},

		/** @return {boolean} */
		mediaRevealed() {
			return !this.hasGatedMedia || this.warningLifted
		},

		/**
		 * @return {boolean} whether the post is laid out around its pictures.
		 * Not while it is being edited, where the text is the thing being
		 * worked on, and not under a content warning, which owns the top of
		 * the post until the reader lifts it.
		 */
		mediaLeads() {
			return this.hasAttachments && !this.hasSpoiler && !this.isEditing
		},

		/** @return {number} how many characters the edit has left */
		editCharsLeft() {
			return MAX_LENGTH - this.editContent.length
		},

		/** @return {boolean} */
		editIsTooLong() {
			return this.editCharsLeft < 0
		},

		/** @return {boolean} */
		editCanSave() {
			return this.editContent.trim() !== '' && !this.editIsTooLong
		},

		/** @return {string} */
		editCharactersLeftLabel() {
			return this.editIsTooLong
				? n('social', '%n character too many', '%n characters too many', -this.editCharsLeft)
				: n('social', '%n character left', '%n characters left', this.editCharsLeft)
		},

		/** Who wrote it, so moving between posts by landmark says something. */
		postLabel() {
			return t('social', 'Post by {account}', { account: this.item.account?.acct ?? '' })
		},

		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account?.acct ?? '')
		},

		/** @return {boolean} a link preview replaces nothing, so media wins */
		showCard() {
			return !this.hasAttachments && Boolean(this.item.card?.title)
		},

		/**
		 * @return {boolean} own local posts can be pinned to the profile, and
		 * only the ones anyone may see: a pinned followers-only post was
		 * served in full to the anonymous internet through the featured
		 * collection, so the server now refuses anything that is not public
		 * or unlisted — the same set a boost is allowed for.
		 */
		canPin() {
			return this.item.account.acct === this.currentAccount?.acct
				&& this.item.local !== false
				&& (this.item.visibility === 'public' || this.item.visibility === 'unlisted')
		},

		/**
		 * @return {boolean} whether this post may be quoted at all. A quote
		 * carries the audience of the quoter, so the server grants one only for
		 * a public or unlisted post — the same set a boost is allowed for — and
		 * offering the action on anything narrower would be offering a refusal.
		 */
		canQuote() {
			return this.item.visibility === 'public' || this.item.visibility === 'unlisted'
		},

		reportButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showReportDialog = false
					},
				},
				{
					label: t('social', 'Report'),
					variant: 'error',
					callback: () => this.sendReport(),
				},
			]
		},

		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showDeleteDialog = false
					},
				},
				{
					label: t('social', 'Delete'),
					variant: 'error',
					callback: () => this.remove(),
				},
			]
		},

		/**
		 * @return {string}
		 */
		relativeTimestamp() {
			return fromNow(this.item.created_at, new Date(this.now))
		},

		/**
		 * @return {string}
		 */
		formattedDate() {
			return fullDateTime(this.item.created_at)
		},

		/**
		 * @return {number}
		 */
		timestamp() {
			return Date.parse(this.item.created_at)
		},

		/**
		 * @return {boolean}
		 */
		hasAttachments() {
			// TODO: clean media_attachments
			return (this.item.media_attachments || []).length > 0
		},

		/**
		 * @return {boolean}
		 */
		isBoosted() {
			return this.item.reblogged === true
		},
		/**
		 * @return {boolean}
		 */

		isLiked() {
			return this.item.favourited === true
		},

		/**
		 * @return {object}
		 */
		richParameters() {
			return {}
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.item.account.acct.includes('@')
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {object} */
		visibility() {
			return visibilitiesInfo.find(({ id }) => this.item.visibility === id)
		},
	},

	watch: {
		// a vote cast elsewhere (or reloaded from the server) has to reach the
		// copy this component renders, or navigating back shows the poll unvoted
		'item.poll': function(poll) {
			this.localPoll = poll ?? null
		},
	},

	mounted() {
		eventBus.on('timeline:focused', this.rememberFocus)
		eventBus.on('shortcut:like', this.likeIfFocused)
		eventBus.on('shortcut:boost', this.boostIfFocused)
		eventBus.on('shortcut:reply', this.replyIfFocused)
		eventBus.on('shortcut:open', this.openIfFocused)
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		eventBus.off('timeline:focused', this.rememberFocus)
		eventBus.off('shortcut:like', this.likeIfFocused)
		eventBus.off('shortcut:boost', this.boostIfFocused)
		eventBus.off('shortcut:reply', this.replyIfFocused)
		eventBus.off('shortcut:open', this.openIfFocused)
		this.stopTicking?.()
	},

	methods: {
		/**
		 * @param {import('../types/Mastodon.js').Status} status the post the keyboard moved to
		 */
		rememberFocus(status) {
			this.hasKeyboardFocus = status?.id === this.item.id
		},

		likeIfFocused() {
			if (this.hasKeyboardFocus) {
				this.like()
			}
		},

		boostIfFocused() {
			if (this.hasKeyboardFocus && (this.item.visibility === 'public' || this.item.visibility === 'unlisted')) {
				this.boost()
			}
		},

		replyIfFocused() {
			if (this.hasKeyboardFocus) {
				this.reply()
			}
		},

		openIfFocused() {
			if (this.hasKeyboardFocus) {
				this.getSinglePostTimeline()
			}
		},

		/**
		 * @function getSinglePostTimeline
		 * @description Opens the conversation the post belongs to.
		 *
		 * Remote posts used to return here with a logger.warn, which made the
		 * timestamp — the only affordance for opening a thread — silently dead
		 * on the Global and Federated timelines. The server serves the context
		 * of any status it has (`/api/v1/statuses/{nid}/context`), local or not.
		 */
		getSinglePostTimeline() {
			if (!this.item.account?.acct || this.item.id === undefined) {
				logger.warn('Cannot open a post without an account and an id', { post: this.item })
				return
			}

			this.$router.push({
				name: 'single-post',
				params: {
					// acct, not username: two remote accounts can share a
					// username, and the route has to name one of them
					account: this.item.account.acct,
					id: this.item.id,
					type: 'single-post',
				},
			})
		},

		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},

		reply() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-reply', this.item)
		},

		quote() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-quote', this.item)
		},

		async sendReport() {
			try {
				await axios.post(generateUrl('apps/social/api/v1/reports'), {
					account_id: this.item.account.id,
					status_ids: [this.item.id],
					comment: this.reportComment,
				})
				showSuccess(t('social', 'Post reported to the moderators'))
				this.showReportDialog = false
				this.reportComment = ''
			} catch (error) {
				logger.error('Failed to report the post', { error })
				showError(t('social', 'Failed to report the post'))
			}
		},

		async boost() {
			const undo = this.isBoosted
			await this.act('boost', undo ? 'postUnBoost' : 'postBoost', !undo)
		},

		editPost() {
			// never the author's bio: an image-only post has no content, and
			// seeding the editor from account.note offered to publish it
			this.editContent = htmlToPlainText(this.item.content || '')
			this.editSpoiler = this.item.spoiler_text || ''
			this.isEditing = true
			this.$nextTick(() => {
				if (this.$refs.editInput) {
					this.$refs.editInput.focus()
				}
			})
		},

		async saveEdit() {
			if (!this.editCanSave) {
				return
			}

			const warning = this.editSpoiler.trim()
			const response = await this.timelineStore.postEdit({
				status: this.item,
				content: this.editContent.trim(),
				// fixing a typo used to un-hide sensitive content for every
				// follower, because the warning was always sent back empty
				spoiler_text: warning,
				sensitive: warning !== '' || this.item.sensitive === true,
			})
			if (response === undefined) {
				// the store already said so; keep what was typed
				return
			}

			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		cancelEdit() {
			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		remove() {
			this.showDeleteDialog = false
			this.timelineStore.postDelete(this.item)
		},

		/**
		 * A vote is cast on the component's own copy of the poll; the store
		 * holds the one every other view reads, so it hears about it too.
		 *
		 * @param {object} poll the poll as the server returned it after voting
		 */
		updatePoll(poll) {
			this.localPoll = poll
			this.timelineStore.updateStatusPoll({ statusId: this.item.id, poll })
		},

		toggleBookmark() {
			this.timelineStore.postBookmark({ status: this.item, bookmarked: !this.item.bookmarked })
		},

		togglePin() {
			this.timelineStore.postPin({ status: this.item, pinned: !this.item.pinned })
		},

		async like() {
			const undo = this.isLiked
			await this.act('like', undo ? 'postUnlike' : 'postLike', !undo)
		},

		/**
		 * Both actions are applied optimistically and rolled back by the store
		 * when the server refuses — which used to happen invisibly. Confirming
		 * one animation and refusing the other makes the difference legible.
		 *
		 * @param {string} name 'like' or 'boost', the class hook
		 * @param {string} action the store action to dispatch
		 * @param {boolean} celebrating whether this is the doing, not the undoing
		 */
		async act(name, action, celebrating) {
			if (celebrating) {
				this.celebrate = name
				// a touch device can feel the confirmation as well as see it
				if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
					window.navigator.vibrate?.(8)
				}
				window.setTimeout(() => {
					if (this.celebrate === name) {
						this.celebrate = ''
					}
				}, 600)
			}

			const response = await this.timelineStore[action]({ status: this.item })
			if (response === undefined) {
				this.celebrate = ''
				this.refused = name
				window.setTimeout(() => {
					if (this.refused === name) {
						this.refused = ''
					}
				}, 400)
			}
		},
	},
}

/**
 *
 * @param html
 */
function htmlToPlainText(html) {
	const parser = new DOMParser()
	const dom = parser.parseFromString(`<div id="rootwrapper">${html}</div>`, 'text/html')
	const root = dom.getElementById('rootwrapper')
	if (!root) {
		return ''
	}

	return nodeToPlainText(root).trim()
}

/**
 *
 * @param node
 */
function nodeToPlainText(node) {
	let text = ''
	for (const child of Array.from(node.childNodes)) {
		if (child.nodeType === Node.TEXT_NODE) {
			text += child.textContent || ''
			continue
		}

		if (child.nodeType !== Node.ELEMENT_NODE) {
			continue
		}

		const element = child
		if (element.tagName === 'BR') {
			text += '\n'
			continue
		}

		text += nodeToPlainText(element)
		if (['DIV', 'P', 'LI', 'BLOCKQUOTE', 'PRE'].includes(element.tagName)) {
			text += '\n'
		}
	}

	return text
}
</script>

<style scoped lang="scss">
/* the like confirmation: a short overshoot, not a bounce */
@keyframes post-pop {
	0% { transform: scale(1); }
	40% { transform: scale(1.35); }
	70% { transform: scale(.92); }
	100% { transform: scale(1); }
}

@keyframes post-burst {
	0% { transform: scale(.2); opacity: .55; }
	100% { transform: scale(2.4); opacity: 0; }
}

@keyframes post-spin {
	0% { transform: rotate(0); }
	100% { transform: rotate(360deg); }
}

/* the server refused: the optimistic change is being taken back */
@keyframes post-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

.post-content {
	padding: 18px 20px 14px;
	font-size: 15px;
	line-height: 1.65;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	position: relative;
	z-index: 1;
	box-shadow: var(--social-elevation-resting);
	transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;

	&:hover {
		border-color: var(--color-primary-element);
		box-shadow: var(--social-elevation-raised);
		transform: translateY(-1px);
	}

	&:focus-within {
		border-color: var(--color-primary-element);
		box-shadow: 0 0 0 2px var(--color-primary-element-light);
	}

	/*
	 * `focus-within` is not decoration here: it is the whole of the keyboard
	 * path. Tabbing into a card has to bring up the same row the pointer does,
	 * or the buttons are focusable and invisible.
	 */
	&:hover .post-actions-reveal,
	&:focus-within .post-actions-reveal,
	.post-actions-reveal--held {
		opacity: 1;
		pointer-events: auto;
		transform: translateY(0);

		.post-actions > * {
			transform: translateY(0);
		}

		/* each one a beat behind the last, left to right, so the row arrives as
		   a movement rather than as four things at once */
		.post-actions > :nth-child(1) { transition-delay: .04s; }
		.post-actions > :nth-child(2) { transition-delay: .075s; }
		.post-actions > :nth-child(3) { transition-delay: .11s; }
		.post-actions > :nth-child(4) { transition-delay: .145s; }
	}

	/* the seam: while the panel is out, the card's own bottom edge is not an
	   edge, so its corners square off and its border stops short */
	&:hover,
	&:focus-within,
	&:has(.post-actions-reveal--held) {
		border-end-start-radius: 0;
		border-end-end-radius: 0;
		border-block-end-color: transparent;
		/* over the card below, which has not moved */
		z-index: 4;
	}

	.post-header {
		display: flex;
		gap: 8px;
		align-items: baseline;
		margin-bottom: 10px;

		.post-author-wrapper {
			flex-grow: 1;
			min-width: 0;
			display: flex;
			align-items: baseline;

			.post-author {
				font-weight: 650;
				font-size: 14px;
				color: var(--color-main-text);
				letter-spacing: -.01em;
			}

			.post-author-id {
				font-size: 13px;
				color: var(--color-text-lighter);
				margin-inline-start: 6px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
		}

		// The row is aligned on the text baseline, which is right for the name
		// and the handle and wrong for anything that is an icon: an icon has no
		// text baseline of its own, so flexbox hangs it from its bottom edge and
		// it sits below the line it belongs on. These two carry icons, so they
		// are centred on the line instead.
		.post-visibility {
			color: var(--color-text-lighter);
			flex-shrink: 0;
			align-self: center;
		}

		.post-pinned {
			display: inline-flex;
			align-items: center;
			align-self: center;
			gap: 4px;
			flex-shrink: 0;
			font-size: 12px;
			// the same weight as the rest of the byline: the pin already says
			// this is a state, and a bold word beside grey text reads as the
			// loudest thing in a row that is all supporting detail
			font-weight: normal;
			color: var(--color-text-lighter);
		}

		.post-timestamp {
			// It opens the thread, so it stays a real button — reachable by
			// keyboard and announced as one. What it must not keep is the
			// chrome a bare <button> inherits from the server, which drew a
			// filled box around four characters of grey byline.
			background: none;
			border: none;
			border-radius: 0;
			padding: 0;
			margin: 0;
			min-height: 0;
			font-family: inherit;
			font-weight: normal;
			font-size: 12px;
			text-align: end;
			color: var(--color-text-lighter);
			white-space: nowrap;
			cursor: pointer;
			flex-shrink: 0;

			&:hover {
				color: var(--color-primary-element);
				background: none;
			}

			// the box was also the focus indicator; without one, a keyboard
			// reader loses the only affordance for opening a thread
			&:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 2px;
				border-radius: var(--border-radius-small, 4px);
			}
		}
	}

	.post-message {
		margin-bottom: 10px;
		overflow-wrap: break-word;
		overflow: visible;

		:deep(p) {
			margin: 0 0 8px;
			&:last-child {
				margin-bottom: 0;
			}
		}

		:deep(a) {
			overflow-wrap: anywhere;

			&:hover {
				text-decoration: underline;
			}
		}

		:deep(.mention) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(.hashtag) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(img) {
			max-width: 100%;
			height: auto;
			border-radius: 8px;
			margin: 12px 0;
			display: block;
		}
	}

	.post-edit-inline {
		margin-bottom: 10px;

		.post-edit-textarea {
			width: 100%;
			min-height: 100px;
			padding: 8px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-family: inherit;
			font-size: 15px;
			line-height: 1.65;
			resize: vertical;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-warning {
			width: 100%;
			margin-bottom: 6px;
			padding: 8px 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius, 8px);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 14px;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-actions {
			display: flex;
			gap: 8px;
			margin-top: 8px;
			align-items: center;
			justify-content: flex-end;
		}

		.post-edit-count {
			margin-inline-end: auto;
			font-size: 12px;
			color: var(--color-text-lighter);

			&--over {
				color: var(--color-error);
				font-weight: 600;
			}
		}
	}

	/*
	 * The action row is the loudest thing in a card and the least often used:
	 * somebody scrolling a timeline is reading, not boosting. So the card is
	 * only as tall as what somebody is reading, and grows to make room for the
	 * row when the pointer arrives.
	 *
	 * A grid whose single row goes from `0fr` to `1fr` is what animates that:
	 * a height nobody can know in advance — the row wraps on a narrow window,
	 * and a count going from 9 to 10 is another pixel — cannot be transitioned
	 * any other way. `max-height` needs a number big enough to be wrong, and
	 * `height: auto` does not interpolate anywhere this app can rely on yet.
	 */
	/*
	 * The row opens *out of* the card rather than inside it: a panel positioned
	 * against the card's bottom edge, carrying the card's own background,
	 * borders and corners so it reads as the card growing.
	 *
	 * It is out of flow, which is the point. A row that took layout space made
	 * every card below the pointer jump down by its height and back up again on
	 * the way out, so running the pointer down a timeline set the whole page
	 * twitching — and a reader chasing a moving target cannot aim. Nothing below
	 * the hovered card moves now.
	 *
	 * It begins 8px above the card's bottom edge, inside the card's own bottom
	 * padding, which is empty. That is 8px it does not have to spend on the
	 * 14px gap to the next card, so of a 42px panel only about 20px reaches the
	 * card below — 18px of which is that card's top padding. It covers two
	 * pixels of anything anybody is reading, and only while the pointer is on
	 * the card above it.
	 */
	.post-actions-reveal {
		position: absolute;
		top: calc(100% - 8px);
		inset-inline: -1px;
		z-index: 2;
		padding: 0 20px 8px;
		background: var(--color-main-background);
		border: 1px solid var(--color-primary-element);
		border-block-start: none;
		border-end-start-radius: 8px;
		border-end-end-radius: 8px;
		box-shadow: var(--social-elevation-raised);
		opacity: 0;
		/* it is not there to be clicked until it is there to be seen */
		pointer-events: none;
		transform: translateY(-8px);
		transition:
			opacity .16s ease,
			transform .3s cubic-bezier(.22, 1.2, .48, 1);
	}

	.post-actions {
		display: flex;
		align-items: center;
		gap: 2px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);

		> * {
			transform: translateY(4px);
			transition: transform .34s cubic-bezier(.22, 1.4, .48, 1);
		}

		.post-action-group {
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}

		.post-action-count {
			font-size: 12px;
			color: var(--color-text-lighter);
			min-width: 16px;
			text-align: center;
		}

		:deep(.button-vue) {
			border-radius: 8px;

			&:hover {
				background: var(--color-background-dark);
			}
		}

		:deep(.button-vue--icon-only) {
			min-height: 36px;
			min-width: 36px;
		}

		:deep(.actions) {
			margin-inline-start: auto;
		}
	}
}

.post-action-group {
	position: relative;

	&--refused :deep(button) {
		animation: post-refused .4s ease;
	}
}

.post-action--popped :deep(.material-design-icon) {
	animation: post-pop .45s cubic-bezier(.34, 1.56, .64, 1);
}

.post-action--spun :deep(.material-design-icon) {
	animation: post-spin .5s cubic-bezier(.4, 0, .2, 1);
}

/* the ring that expands out of the heart once */
.post-action__burst {
	position: absolute;
	top: 50%;
	inset-inline-start: 22px;
	width: 20px;
	height: 20px;
	margin: -10px 0 0 -10px;
	border-radius: 50%;
	background: var(--color-element-error);
	pointer-events: none;
	animation: post-burst .5s ease-out forwards;
}

.post-pinned {
	animation: none;
}

@media (prefers-reduced-motion: reduce) {
	.post-content,
	.post-content:hover {
		transition: none;
		transform: none;
	}

	.post-action-group--refused :deep(button),
	.post-action--popped :deep(.material-design-icon),
	.post-action--spun :deep(.material-design-icon) {
		animation: none;
	}

	.post-action__burst {
		display: none;
	}
}

.post-instance {
	flex-shrink: 0;
	margin-inline-start: 6px;
	padding: 1px 7px;
	border-radius: var(--border-radius-pill, 10px);
	font-size: 11px;
	font-weight: 600;
	letter-spacing: .01em;
	color: var(--color-primary-element-text);
	background: var(--instance-colour);
	max-width: 12ch;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
/**
 * A content warning is the author asking for their post not to be shown
 * until the reader chooses to see it. Honouring that is the whole point,
 * so the body is not in the DOM until it is opened.
 */
.post-warning {
	margin: 4px 0 8px;

	&__text {
		margin-bottom: 8px;
		font-weight: 600;
	}
}

/* the text of a picture post is its caption: smaller, and nearer the picture */
.post-message--caption {
	margin-top: 2px;
	font-size: 14.5px;
	color: var(--color-main-text);
}

.post-sensitive--leading {
	min-height: 180px;
}

.post-message--behind-warning {
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}

/**
 * A post flagged sensitive without a warning shows its text but not its
 * pictures until the reader asks for them.
 */
.post-sensitive {
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 10px 0;
	padding: 20px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-background-dark);
}

.delete-hint {
	padding: 0 12px 12px;
	color: var(--color-text-lighter);
	line-height: 1.5;
}

/*
 * A finger cannot hover. On a touch screen there is no state in which the row
 * would ever appear, so it is simply always there.
 */
/*
 * A finger cannot hover, so on a touch screen the row is simply always there —
 * and a panel that is always there cannot be the floating one, which would sit
 * over the top of the next card for ever. It goes back into the card's flow,
 * where it takes its own space and pushes nothing, because nothing is moving.
 */
@media (hover: none) {
	.post-content .post-actions-reveal {
		position: static;
		padding: 0;
		background: none;
		border: none;
		box-shadow: none;
		opacity: 1;
		pointer-events: auto;
		transform: none;
	}

	.post-content .post-actions {
		margin-top: 10px;

		> * {
			transform: none;
		}
	}
}

/*
 * Reduced motion takes the movement away, not the reveal: the row still has to
 * arrive when the pointer does, it just stops travelling to get there.
 */
@media (prefers-reduced-motion: reduce) {
	.post-content .post-actions-reveal {
		transform: none;
		transition: opacity .01ms linear;
	}

	.post-content .post-actions > * {
		transform: none;
		transition: none;
		transition-delay: 0ms !important;
	}
}
</style>
