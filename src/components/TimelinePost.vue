<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-content" :data-social-status="item.id">
		<div class="post-header">
			<div class="post-author-wrapper" :title="item.account.acct">
				<router-link v-if="item.account"
					:to="{ name: 'profile',
						params: { account: item.account.acct }
					}">
					<span class="post-author">
						<DisplayName :text="item.account.display_name" :emojis="item.account.emojis" />
					</span>
					<span class="post-author-id">
						@{{ item.account.username }}
					</span>
					<span v-if="!origin.local"
						class="post-instance"
						:style="{ '--instance-colour': origin.colour }"
						:title="t('social', 'Posted from {instance}', { instance: origin.instance })">
						{{ origin.instance }}
					</span>
				</router-link>
			</div>
			<a :data-timestamp="timestamp"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</a>
			<span v-if="item.pinned" class="post-pinned" :title="t('social', 'Pinned post')">
				<Pin :size="14" />
				{{ t('social', 'Pinned') }}
			</span>
			<VisibilityIcon v-if="visibility"
				:title="visibility.text"
				class="post-visibility"
				:visibility="visibility.id" />
		</div>
		<div v-if="isEditing" class="post-edit-inline">
			<textarea ref="editInput"
				v-model="editContent"
				class="post-edit-textarea"
				:placeholder="t('social', 'Edit your post')"
				@keydown.ctrl.enter="saveEdit" />
			<div class="post-edit-actions">
				<NcButton type="primary"
					:aria-label="t('social', 'Save')"
					@click="saveEdit">
					{{ t('social', 'Save') }}
				</NcButton>
				<NcButton :aria-label="t('social', 'Cancel')"
					@click="cancelEdit">
					{{ t('social', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		<div v-else-if="item.content" class="post-message">
			<MessageContent :item="item" />
		</div>
		<!-- Sanitized: the bio is remote HTML, see sanitizeHtml.js -->
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else class="post-message" v-html="sanitizedAccountNote" />
		<Poll v-if="localPoll" :poll="localPoll" @update:poll="localPoll = $event" />
		<PostAttachment v-if="hasAttachments" :attachments="item.media_attachments || []" />
		<PostCard v-if="showCard" :card="item.card" />
		<div v-if="$route && $route.params.type !== 'notifications' && !serverData.public" class="post-actions">
			<div class="post-action-group">
				<NcButton :title="t('social', 'Reply')"
					:aria-label="t('social', 'Reply')"
					type="tertiary"
					@click="reply">
					<template #icon>
						<Reply :size="20" />
					</template>
				</NcButton>
				<span v-if="item.replies_count > 0" class="post-action-count">{{ item.replies_count }}</span>
			</div>
			<div class="post-action-group"
				:class="{ 'post-action-group--refused': refused === 'boost' }">
				<NcButton v-if="item.visibility === 'public' || item.visibility === 'unlisted'"
					:title="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
					:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
					type="tertiary"
					:class="{ 'post-action--spun': celebrate === 'boost' }"
					@click="boost">
					<template #icon>
						<Repeat :size="20" :fill-color="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
					</template>
				</NcButton>
				<span v-if="item.reblogs_count > 0" class="post-action-count">{{ item.reblogs_count }}</span>
			</div>
			<div class="post-action-group post-action-group--like"
				:class="{ 'post-action-group--refused': refused === 'like' }">
				<span v-if="celebrate === 'like'" class="post-action__burst" aria-hidden="true" />
				<NcButton v-if="!isLiked"
					:title="t('social', 'Like')"
					:aria-label="t('social', 'Like')"
					type="tertiary"
					@click="like">
					<template #icon>
						<HeartOutline :size="20" />
					</template>
				</NcButton>
				<NcButton v-if="isLiked"
					:title="t('social', 'Undo Like')"
					:aria-label="t('social', 'Undo Like')"
					type="tertiary"
					:class="{ 'post-action--popped': celebrate === 'like' }"
					@click="like">
					<template #icon>
						<Heart :size="20" :fill-color="'var(--color-element-error)'" />
					</template>
				</NcButton>
				<span v-if="item.favourites_count > 0" class="post-action-count">{{ item.favourites_count }}</span>
			</div>
			<NcActions>
				<NcActionButton v-if="item.account.acct === currentAccount?.acct"
					icon="icon-rename"
					@click="editPost">
					{{ t('social', 'Edit') }}
				</NcActionButton>
				<NcActionButton v-if="item.account.acct === currentAccount?.acct"
					icon="icon-delete"
					@click="remove()">
					{{ t('social', 'Delete') }}
				</NcActionButton>
				<NcActionButton v-if="canPin"
					@click="togglePin">
					<template #icon>
						<Pin v-if="!item.pinned" :size="20" />
						<PinOff v-else :size="20" />
					</template>
					{{ item.pinned ? t('social', 'Unpin from profile') : t('social', 'Pin to profile') }}
				</NcActionButton>
				<NcActionButton v-if="item.account.acct !== currentAccount?.acct"
					@click="showReportDialog = true">
					<template #icon>
						<Flag :size="20" />
					</template>
					{{ t('social', 'Report') }}
				</NcActionButton>
			</NcActions>
			<NcDialog v-model:open="showReportDialog"
				:name="t('social', 'Report {account}', { account: item.account.acct })"
				:buttons="reportButtons">
				<p class="report-hint">
					{{ t('social', 'The report goes to the moderators of this instance. It is never sent to the reported account or their server.') }}
				</p>
				<textarea v-model="reportComment"
					class="report-comment"
					:placeholder="t('social', 'Why are you reporting this post? (optional)')"
					rows="3" />
			</NcDialog>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line no-unused-vars
// side-effect imports: they register the mention plugin and the string
// interface that the rendered content relies on
import 'linkify-plugin-mention'
import 'linkify-string'
import currentUser from './../mixins/currentUserMixin.js'
import PostAttachment from './PostAttachment.vue'
import PostCard from './PostCard.vue'
import { sanitizeHtml } from '../utils/sanitizeHtml.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Flag from 'vue-material-design-icons/Flag.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { originOf } from '../utils/instanceIdentity.js'
import moment from '@nextcloud/moment'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import DisplayName from './DisplayName.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'

export default {
	name: 'TimelinePost',
	components: {
		PostAttachment,
		PostCard,
		NcActions,
		NcActionButton,
		NcDialog,
		Flag,
		NcButton,
		Pin,
		PinOff,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
		Poll,
		DisplayName,
		VisibilityIcon,
	},
	mixins: [currentUser],
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
	data() {
		return {
			isEditing: false,
			/** which action is playing its confirmation, '' when none */
			celebrate: '',
			/** which action the server refused, so the button can say so */
			refused: '',
			/** whether j/k has this post, so l/b/r act on the right one */
			hasKeyboardFocus: false,
			editContent: '',
			showReportDialog: false,
			reportComment: '',
			localPoll: this.item?.poll ?? null,
		}
	},
	computed: {
		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account?.acct ?? '')
		},
		/** @return {boolean} a link preview replaces nothing, so media wins */
		showCard() {
			return !this.hasAttachments && Boolean(this.item.card?.title)
		},
		/** @return {boolean} own local posts can be pinned to the profile */
		canPin() {
			return this.item.account.acct === this.currentAccount?.acct && this.item.local !== false
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
					type: 'error',
					callback: () => this.sendReport(),
				},
			]
		},
		/**
		 * The author's bio, reduced to markup that is safe to inject.
		 *
		 * @return {string}
		 */
		sanitizedAccountNote() {
			return sanitizeHtml(this.item.account?.note ?? '')
		},
		/**
		 * @return {string}
		 */
		relativeTimestamp() {
			return moment(this.item.created_at).fromNow()
		},
		/**
		 * @return {string}
		 */
		formattedDate() {
			return moment(this.item.created_at).format('LLL')
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
			return this.$store.getters.currentAccount
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
	mounted() {
		eventBus.on('timeline:focused', this.rememberFocus)
		eventBus.on('shortcut:like', this.likeIfFocused)
		eventBus.on('shortcut:boost', this.boostIfFocused)
		eventBus.on('shortcut:reply', this.replyIfFocused)
		eventBus.on('shortcut:open', this.openIfFocused)
	},
	unmounted() {
		eventBus.off('timeline:focused', this.rememberFocus)
		eventBus.off('shortcut:like', this.likeIfFocused)
		eventBus.off('shortcut:boost', this.boostIfFocused)
		eventBus.off('shortcut:reply', this.replyIfFocused)
		eventBus.off('shortcut:open', this.openIfFocused)
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
		 * @description Opens the timeline of the post clicked
		 */
		getSinglePostTimeline() {
			// Display internal or external post
			if (!this.isLocal) {
				logger.warn("Don't know what to do with posts of type " + this.type, { post: this.item })
				return
			}

			this.$router.push({
				name: 'single-post',
				params: {
					account: this.item.account.username,
					id: this.item.id,
					type: 'single-post',
				},
			})
		},
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},
		reply() {
			this.$store.commit('setComposerDisplayStatus', true)
			eventBus.emit('composer-reply', this.item)
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
			const rawContent = this.item.content || this.item.account?.note || ''
			this.editContent = htmlToPlainText(rawContent)
			this.isEditing = true
			this.$nextTick(() => {
				if (this.$refs.editInput) {
					this.$refs.editInput.focus()
				}
			})
		},
		async saveEdit() {
			if (this.editContent.trim() === '') {
				return
			}
			await this.$store.dispatch('postEdit', {
				status: this.item,
				content: this.editContent.trim(),
				spoiler_text: '',
				sensitive: false,
			})
			this.isEditing = false
			this.editContent = ''
		},
		cancelEdit() {
			this.isEditing = false
			this.editContent = ''
		},
		remove() {
			this.$store.dispatch('postDelete', this.item)
		},
		togglePin() {
			this.$store.dispatch('postPin', { status: this.item, pinned: !this.item.pinned })
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

			const response = await this.$store.dispatch(action, { status: this.item })
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
	transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;

	&:hover {
		border-color: var(--color-primary-element);
		box-shadow: 0 2px 10px rgb(0 0 0 / 7%);
		transform: translateY(-1px);
	}

	&:focus-within {
		border-color: var(--color-primary-element);
		box-shadow: 0 0 0 2px var(--color-primary-element-light);
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
				margin-left: 6px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
		}

		.post-visibility {
			color: var(--color-text-lighter);
			flex-shrink: 0;
		}

		.post-pinned {
			display: inline-flex;
			align-items: center;
			gap: 2px;
			flex-shrink: 0;
			font-size: 12px;
			font-weight: 600;
			color: var(--color-text-lighter);
		}

		.post-timestamp {
			font-size: 12px;
			text-align: right;
			color: var(--color-text-lighter);
			white-space: nowrap;
			cursor: pointer;
			flex-shrink: 0;

			&:hover {
				color: var(--color-primary-element);
			}
		}
	}

	.post-message {
		margin-bottom: 10px;
		word-wrap: break-word;
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

			&:focus {
				outline: none;
				border-color: var(--color-primary-element);
			}
		}

		.post-edit-actions {
			display: flex;
			gap: 8px;
			margin-top: 8px;
			justify-content: flex-end;
		}
	}

	.post-actions {
		display: flex;
		align-items: center;
		gap: 2px;
		margin-top: 10px;
		padding-top: 10px;
		border-top: 1px solid var(--color-border);

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
			margin-left: auto;
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
	left: 22px;
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
	margin-left: 6px;
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
</style>
