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
						{{ item.account.display_name }}
					</span>
					<span class="post-author-id">
						@{{ item.account.username }}
					</span>
				</router-link>
			</div>
			<a :data-timestamp="timestamp"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</a>
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
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else class="post-message" v-html="item.account.note" />
		<PostAttachment v-if="hasAttachments" :attachments="item.media_attachments || []" />
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
			<div class="post-action-group">
				<NcButton v-if="item.visibility === 'public' || item.visibility === 'unlisted'"
					:title="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
					:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
					type="tertiary"
					@click="boost">
					<template #icon>
						<Repeat :size="20" :fill-color="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
					</template>
				</NcButton>
				<span v-if="item.reblogs_count > 0" class="post-action-count">{{ item.reblogs_count }}</span>
			</div>
			<div class="post-action-group">
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
			</NcActions>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line no-unused-vars
import * as linkify from 'linkifyjs'
import 'linkify-plugin-mention'
import 'linkify-string'
import currentUser from './../mixins/currentUserMixin.js'
import PostAttachment from './PostAttachment.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import moment from '@nextcloud/moment'
import MessageContent from './MessageContent.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'

export default {
	name: 'TimelinePost',
	components: {
		PostAttachment,
		NcActions,
		NcActionButton,
		NcButton,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
		VisibilityIcon,
	},
	mixins: [currentUser],
	data() {
		return {
			isEditing: false,
			editContent: '',
		}
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
	computed: {
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
	methods: {
		/**
		 * @param {MouseEvent} e - The click event
		 * @function getSinglePostTimeline
		 * @description Opens the timeline of the post clicked
		 */
		getSinglePostTimeline(e) {
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
		boost() {
			const params = {
				status: this.item,
				parentAnnounce: this.reblog,
			}
			if (this.isBoosted) {
				this.$store.dispatch('postUnBoost', params)
			} else {
				this.$store.dispatch('postBoost', params)
			}
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
		like() {
			const params = {
				status: this.item,
				parentAnnounce: this.reblog,
			}
			if (this.isLiked) {
				this.$store.dispatch('postUnlike', params)
			} else {
				this.$store.dispatch('postLike', params)
			}
		},
	},
}

function htmlToPlainText(html) {
	const parser = new DOMParser()
	const dom = parser.parseFromString(`<div id="rootwrapper">${html}</div>`, 'text/html')
	const root = dom.getElementById('rootwrapper')
	if (!root) {
		return ''
	}

	return nodeToPlainText(root).trim()
}

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
.post-content {
	padding: 18px 20px 14px;
	font-size: 15px;
	line-height: 1.65;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	position: relative;
	z-index: 1;

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
</style>
