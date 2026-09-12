<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="new-post"
		:class="{
			'new-post--collapsed': !expanded,
			'new-post--drop-target': draggingFiles,
			'new-post--refused': refusedDrop,
		}"
		data-id=""
		@focusin="expand"
		@dragenter="handleDragEnter"
		@dragover="handleDragOver"
		@dragleave="handleDragLeave"
		@drop="handleDrop">
		<!-- announced only to the eye: a drag is not something a screen reader
		     is in the middle of, and the pill must never take the drop it
		     announces, hence pointer-events: none -->
		<div v-if="draggingFiles" class="new-post__drop-hint" aria-hidden="true">
			<span>{{ t('social', 'Drop to attach') }}</span>
		</div>
		<input id="file-upload"
			ref="fileUploadInput"
			type="file"
			:accept="acceptedTypes"
			multiple="true"
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually"
			@change="handleFileChange($event)">
		<div class="new-post-author">
			<NcAvatar :user="currentUser.uid"
				:display-name="currentUser.displayName"
				:disable-tooltip="true"
				:size="32" />
			<div class="post-author">
				<span class="post-author-name">
					{{ currentUser.displayName }}
				</span>
			</div>
		</div>
		<div v-if="replyTo" class="reply-to">
			<p class="reply-info">
				<span>{{ t('social', 'In reply to') }}</span>
				<ActorAvatar :actor="replyTo.account" :size="16" />
				<strong>{{ replyTo.account.acct }}</strong>
				<NcButton variant="tertiary"
					class="close-button"
					:aria-label="t('social', 'Close reply')"
					@click="closeReply">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</p>
			<MessageContent :item="replyTo" />
		</div>
		<div v-if="quoteOf" class="quote-of">
			<p class="quote-info">
				<span>{{ t('social', 'Quoting') }}</span>
				<ActorAvatar :actor="quoteOf.account" :size="16" />
				<strong>{{ quoteOf.account.acct }}</strong>
				<NcButton variant="tertiary"
					class="close-button"
					:aria-label="t('social', 'Remove quote')"
					@click="removeQuote">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</p>
			<MessageContent :item="quoteOf" />
		</div>
		<form class="new-post-form"
			:class="{ 'new-post-form--media-first': hasAttachments }"
			@submit.prevent>
			<input v-if="showWarning"
				v-model="spoilerText"
				type="text"
				class="content-warning"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')">
			<!-- above the box, not below it: once there is a picture the post
			     is the picture, and what is typed underneath is its caption -->
			<PreviewGrid :uploading="uploading"
				:upload-progress="uploadProgress"
				:progress-label="progressLabel"
				:miniatures="attachments"
				@deleted="deletePreview"
				@describe="describeAttachment"
				@commit-description="commitDescription" />

			<div ref="composerInput"
				:contenteditable="!loading"
				class="message"
				role="textbox"
				aria-multiline="true"
				:aria-label="prompt"
				:aria-describedby="statusIsTooLong ? 'composer-length' : undefined"
				:placeholder="prompt"
				:class="{'icon-loading': loading, 'too-long': statusIsTooLong, 'message--caption': hasAttachments}"
				@keyup.prevent.enter="keyup"
				@input="updateStatusContent"
				@paste="handlePaste"
				@tribute-replaced="updatePostFromTribute" />

			<div v-if="showPoll" class="poll-editor">
				<div v-for="(option, index) in pollOptions" :key="index" class="poll-editor__option">
					<input v-model="pollOptions[index]"
						type="text"
						:placeholder="t('social', 'Poll option {number}', { number: index + 1 })"
						maxlength="100">
					<NcButton v-if="pollOptions.length > 2"
						variant="tertiary"
						:aria-label="t('social', 'Remove option')"
						@click.prevent="pollOptions.splice(index, 1)">
						<template #icon>
							<Close :size="18" />
						</template>
					</NcButton>
				</div>
				<div class="poll-editor__settings">
					<NcButton v-if="pollOptions.length < 4"
						variant="tertiary"
						@click.prevent="pollOptions.push('')">
						{{ t('social', 'Add option') }}
					</NcButton>
					<label>
						<input v-model="pollMultiple" type="checkbox">
						{{ t('social', 'Multiple choice') }}
					</label>
					<select v-model.number="pollExpiresIn" :aria-label="t('social', 'Poll duration')">
						<option :value="1800">
							{{ t('social', '30 minutes') }}
						</option>
						<option :value="3600">
							{{ t('social', '1 hour') }}
						</option>
						<option :value="21600">
							{{ t('social', '6 hours') }}
						</option>
						<option :value="86400">
							{{ t('social', '1 day') }}
						</option>
						<option :value="259200">
							{{ t('social', '3 days') }}
						</option>
						<option :value="604800">
							{{ t('social', '7 days') }}
						</option>
					</select>
				</div>
			</div>

			<div class="options">
				<NcButton :title="t('social', 'Add attachment')"
					variant="tertiary"
					:aria-label="t('social', 'Add attachment')"
					:disabled="attachmentsFull"
					@click.prevent="clickImportInput">
					<template #icon>
						<Paperclip :size="22" decorative title="" />
					</template>
				</NcButton>

				<NcButton :title="t('social', 'Add from Files')"
					variant="tertiary"
					:aria-label="t('social', 'Add from Files')"
					:disabled="attachmentsFull || picking"
					@click.prevent="pickFromFiles">
					<template #icon>
						<FolderImage :size="22" decorative title="" />
					</template>
				</NcButton>

				<NcButton :title="showWarning ? t('social', 'Remove content warning') : t('social', 'Add content warning')"
					variant="tertiary"
					:aria-label="showWarning ? t('social', 'Remove content warning') : t('social', 'Add content warning')"
					:aria-pressed="showWarning"
					@click.prevent="toggleWarning">
					<template #icon>
						<AlertOutline :size="22" decorative title="" />
					</template>
				</NcButton>
				<NcButton :title="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
					variant="tertiary"
					:aria-label="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
					@click.prevent="togglePoll">
					<template #icon>
						<PollIcon :size="22" decorative title="" />
					</template>
				</NcButton>

				<div class="new-post-form__emoji-picker">
					<NcEmojiPicker ref="emojiPicker"
						:search="search"
						:close-on-select="false"
						container="#content-vue"
						@select="insert">
						<NcButton :title="t('social', 'Add emoji')"
							variant="tertiary"
							:aria-haspopup="true"
							:aria-label="t('social', 'Add emoji')">
							<template #icon>
								<EmoticonOutline :size="22" decorative title="" />
							</template>
						</NcButton>
					</NcEmojiPicker>
				</div>

				<span v-if="undescribed > 0" class="composer-alt-warning" role="status">
					{{ undescribedWarning }}
				</span>
				<VisibilitySelect :visibility="visibility" @update:visibility="visibility = $event" />
				<div class="emptySpace" />
				<span v-if="statusText.length > 0"
					id="composer-length"
					class="char-ring"
					:class="{ 'char-ring--warning': charsLeft <= 50, 'char-ring--over': statusIsTooLong }"
					:style="{ '--char-progress': charProgress }"
					:title="charactersLeftLabel">
					<span v-if="charsLeft <= 50" class="char-ring__count" aria-hidden="true">{{ charsLeft }}</span>
					<!-- the ring is a colour and an arc; this is the same news in words,
					     announced only once it is worth interrupting for -->
					<span class="hidden-visually" role="status">
						{{ charsLeft <= 50 ? charactersLeftLabel : '' }}
					</span>
				</span>
				<SubmitStatusButton :visibility="visibility" :disabled="!canPost || loading" @click="createPost" />
			</div>
		</form>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderImage from 'vue-material-design-icons/FolderImage.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import debounce from 'debounce'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmojiPicker from '@nextcloud/vue/components/NcEmojiPicker'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import he from 'he'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import PreviewGrid from './PreviewGrid.vue'
import VisibilitySelect from '../Visibility/VisibilitySelect.vue'
import { isKnownVisibility } from '../Visibility/VisibilitiesInfos.js'
import SubmitStatusButton from './SubmitStatusButton.vue'
import MessageContent from '../MessageContent.js'
import Tribute from 'tributejs'
import eventBus from '../../services/eventBus.js'
import logger from '../../services/logger.js'
import { clearDraft, loadDraft, saveDraft } from '../../services/draft.js'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../../store/timeline.js'
import { useCurrentUser } from '../../composables/useCurrentUser.js'
import { useServerData } from '../../composables/useServerData.js'

/** what the server accepts in one status */
const MAX_LENGTH = 500

/**
 * What the composer takes as an attachment. The file dialog is given these
 * as its `accept`, and a drop or a paste is held to the same list, so that
 * what can be dragged in is exactly what can be picked.
 */
const ACCEPTED_MEDIA_TYPES = ['image/', 'video/', 'audio/']

/**
 * What the file picker offers. Narrower than what the composer takes from a
 * drop or an upload: Files is where the pictures are, and an audio file picked
 * out of a folder tree is not what this button is for.
 */
const PICKABLE_MEDIA_TYPES = ['image/*', 'video/*']

/** what a post may carry, as Stream::MAX_ATTACHMENTS holds it server-side */
const MAX_ATTACHMENTS = 8

/** how long the card says no for, in step with the refusal in TimelinePost */
const REFUSAL_DURATION = 400

export default {
	name: 'Composer',
	components: {
		NcAvatar,
		NcEmojiPicker,
		NcButton,
		ActorAvatar,
		Paperclip,
		EmoticonOutline,
		Close,
		FolderImage,
		AlertOutline,
		PollIcon,
		PreviewGrid,
		VisibilitySelect,
		SubmitStatusButton,
		MessageContent,
	},
	directives: {
		FocusOnCreate,
	},
	props: {
		initialMention: {
			type: Object,
			default: null,
		},
		defaultVisibility: {
			type: String,
			default: undefined,
		},
		/**
		 * Opened already, for the places where writing a post is the whole
		 * reason the composer is on screen — the New post dialog, say, where
		 * asking for another click would be asking twice.
		 */
		startExpanded: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['posted'],
	setup() {
		const { hostname } = useServerData()
		const { currentUser } = useCurrentUser()

		return { hostname, currentUser }
	},
	data() {
		return {
			statusContent: '',
			/** what would actually be sent — the string the counter measures */
			statusText: '',
			// what a click into the box opens up; the composer is also expanded
			// by anything it already holds — see expanded()
			openedByHand: this.startExpanded,
			visibility: this.defaultVisibility || rememberedVisibility() || 'followers',
			loading: false,
			/** whether an attachment is on its way to the server */
			uploading: false,
			/** how far the current upload has got, 0..1 */
			uploadProgress: 0,
			/** what the progress bar is working on, in words */
			progressLabel: '',
			/** whether the Files dialog is open or its picks are being attached */
			picking: false,
			/** keeps two picks of the same file apart, since the path cannot */
			pickCount: 0,
			/** whether files are being dragged over the card right now */
			draggingFiles: false,
			/** briefly true after a drop of something the composer cannot take */
			refusedDrop: false,
			attachments: {},
			showPoll: false,
			showWarning: false,
			spoilerText: '',
			pollOptions: ['', ''],
			pollMultiple: false,
			pollExpiresIn: 86400,
			search: '',
			replyTo: null,
			/** the post this one quotes, as the timeline handed it over */
			quoteOf: null,
			tributeOptions: {
				spaceSelectsMatch: true,
				collection: [
					{
						trigger: '@',
						lookup(item) {
							return item.key + item.value
						},
						menuItemTemplate(item) {
							return '<img src="' + item.original.avatar + '" /><div>'
								+ '<span class="displayName">' + item.original.key + '</span>'
								+ '<span class="account">' + item.original.value + '</span>'
								+ '</div>'
						},
						selectTemplate(item) {
							return '<span class="mention" contenteditable="false">'
									+ `<a href="${item.original.url}" target="_blank">`
										+ `<img src="${item.original.avatar}"/>`
										+ `@${item.original.value}`
									+ '</a>'
								+ '</span>&nbsp;'
						},
						values: debounce(async (text, populate) => {
							if (text.length < 1) {
								populate([])
							}

							const response = await this.remoteSearchAccounts(text)

							const users = response.data.result.accounts.map((user) => ({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: user.local ? generateUrl(`/avatar/${user.preferredUsername}/32`) : generateUrl(`apps/social/api/v1/global/actor/avatar?id=${user.id}`),
							}))

							logger.debug('Found accounts for a mention', { count: users.length })
							populate(users)
						}, 200),
					},
					{
						trigger: '#',
						menuItemTemplate(item) {
							return item.original.value
						},
						selectTemplate(item) {
							let tag
							if (typeof item === 'undefined') {
								tag = this.currentMentionTextSnapshot
							} else {
								tag = item.original.value
							}
							return '<span class="hashtag" contenteditable="false">'
								+ '<a href="' + generateUrl('/timeline/tags/' + tag) + '" target="_blank">#' + tag + '</a></span>'
						},
						values: debounce(async (text, populate) => {
							if (text.length < 1) {
								populate([])
							}

							const response = await this.remoteSearchHashtags(text)
							const tags = [
								...(response.data.result.exact && !Array.isArray(response.data.result.exact) ? [{ key: response.data.result.exact, value: response.data.result.exact }] : []),
								...response.data.result.tags.map(({ hashtag }) => ({ key: hashtag, value: hashtag })),
							]

							logger.debug('Found hashtags for a mention', { count: tags.length })
							populate(tags)
						}, 200),
					},
				],
				noMatchTemplate() {
					if (this.current.collection.trigger === '#') {
						if (this.current.mentionText === '') {
							return undefined
						} else {
							return '<li data-index="0">#' + this.current.mentionText + '</li>'
						}
					}
				},
			},
		}
	},
	computed: {
		...mapStores(useTimelineStore),
		/** @return {string} the `accept` of the file dialog, from one list */
		acceptedTypes() {
			return ACCEPTED_MEDIA_TYPES.map((type) => `${type}*`).join(',')
		},
		/** @return {boolean} whether the composer holds a picture */
		hasAttachments() {
			return Object.keys(this.attachments).length > 0
		},
		/** @return {boolean} whether the post is carrying all the server takes */
		attachmentsFull() {
			return Object.keys(this.attachments).length >= MAX_ATTACHMENTS
		},
		/**
		 * What the box asks for. With a picture above it, the post is the
		 * picture and the words underneath it are its caption.
		 *
		 * @return {string}
		 */
		prompt() {
			return this.hasAttachments
				? translate('social', 'Write a caption…')
				: translate('social', 'What would you like to share?')
		},
		/** Attachments that can carry a description and have not been given one. */
		undescribed() {
			return Object.values(this.attachments).filter(
				(attachment) => attachment.data?.id !== undefined && (attachment.description || '').trim() === '',
			).length
		},
		/** @return {number} uploads the server refused */
		failedUploads() {
			return Object.values(this.attachments).filter((attachment) => attachment.failed === true).length
		},
		/** @return {boolean} whether an upload has not come back yet */
		hasPendingUploads() {
			return Object.values(this.attachments).some(
				(attachment) => attachment.failed !== true && attachment.data === null,
			)
		},
		/** @return {string[]} the ids the post will carry */
		mediaIds() {
			return Object.values(this.attachments)
				.map((attachment) => attachment.data?.id)
				.filter((id) => id !== undefined && id !== null)
		},
		undescribedWarning() {
			return translatePlural(
				'social',
				'%n attachment has no description',
				'%n attachments have no description',
				this.undescribed,
			)
		},
		charactersLeftLabel() {
			return this.statusIsTooLong
				? translatePlural('social', '%n character too many', '%n characters too many', -this.charsLeft)
				: translatePlural('social', '%n character left', '%n characters left', this.charsLeft)
		},
		canPost() {
			// an upload that has not answered yet is worth waiting for; one
			// that failed used to leave `data: undefined`, which passed this
			// check and then threw on `preview.data.id` before the try block
			if (this.hasPendingUploads) {
				return false
			}

			if (this.statusIsTooLong) {
				return false
			}

			if (this.statusIsEmpty) {
				return false
			}

			if (this.visibility === 'direct' && !this.hasMentions) {
				return false
			}

			return true
		},
		statusIsEmpty() {
			return this.statusText.trim().length === 0 && this.mediaIds.length === 0
		},

		/**
		 * A composer with nothing in it is a placeholder and a portrait; the
		 * eight controls underneath it are answers to a question nobody has
		 * asked yet. It opens on a click, and stays open for as long as it holds
		 * anything that would be lost by closing it.
		 *
		 * @return {boolean}
		 */
		expanded() {
			return this.openedByHand
				|| this.loading
				|| this.replyTo !== null
				|| this.quoteOf !== null
				|| this.showPoll
				|| this.showWarning
				|| !this.statusIsEmpty
				|| Object.keys(this.attachments).length > 0
		},

		/**
		 * Measured on what is sent, not on the markup that produces it. A
		 * mention pill from a reply is ~200 characters of HTML and every line
		 * break adds a <div>, so counting innerHTML burned half the allowance
		 * before a word was typed.
		 */
		statusIsTooLong() {
			return this.statusText.length > MAX_LENGTH
		},

		/** @return {number} how much of the allowance is spent, 0..1 */
		charProgress() {
			return Math.min(this.statusText.length / MAX_LENGTH, 1)
		},

		/** @return {number} how many characters remain, negative once over */
		charsLeft() {
			return MAX_LENGTH - this.statusText.length
		},

		hasMentions() {
			return /(?:^|\s)@[a-zA-Z0-9_.-]+/i.test(this.statusText)
		},
	},
	watch: {
		// the warning is part of the draft, and it has its own field
		spoilerText: 'rememberDraft',
		showWarning: 'rememberDraft',
		visibility: 'rememberDraft',
	},
	mounted() {
		// tributejs is a plain DOM library, not a component: it attaches to the
		// contenteditable and appends its menu to the body, which the unscoped
		// .tribute-container rule at the end of this file styles.
		this.tribute = new Tribute(this.tributeOptions)
		// Kept, because $refs is cleared before unmounted() runs and detach() rejects
		// anything that is not a node.
		this.tributeTarget = this.$refs.composerInput
		this.tribute.attach(this.tributeTarget)

		// Keep the handler so unmounted() removes only this one and not the
		// listeners other components registered for the same event.
		this.onComposerReply = (data) => {
			this.replyTo = data
			this.prefillMessageWithMention(data.account)
			this.visibility = data.visibility
		}
		eventBus.on('composer-reply', this.onComposerReply)

		// a quote carries no mention and does not take the quoted post's
		// visibility: it is addressed by whoever writes it, not by whoever
		// is being quoted
		this.onComposerQuote = (data) => {
			this.quoteOf = data
		}
		eventBus.on('composer-quote', this.onComposerQuote)

		// the shortcuts help offers "n" to write a post; this is what answers it
		this.onComposerFocus = () => this.focusInput()
		eventBus.on('shortcut:compose', this.onComposerFocus)

		// before the mention prefill, which declines to overwrite a non-empty
		// composer: whatever the last attempt left is what the reader wants back
		this.restoreDraft()

		if (this.initialMention !== null) {
			this.prefillMessageWithMention(this.initialMention)
		}

		// a click anywhere else closes it again, which focusout cannot do on its
		// own: the emoji picker is rendered outside this element, so following it
		// with the caret looks exactly like leaving
		this.onOutsideInteraction = (event) => this.collapseIfIdle(event)
		document.addEventListener('pointerdown', this.onOutsideInteraction)
		document.addEventListener('focusin', this.onOutsideInteraction)
	},
	unmounted() {
		window.clearTimeout(this.refusalTimer)
		document.removeEventListener('pointerdown', this.onOutsideInteraction)
		document.removeEventListener('focusin', this.onOutsideInteraction)
		if (this.tribute && this.tributeTarget) {
			this.tribute.detach(this.tributeTarget)
		}
		eventBus.off('composer-reply', this.onComposerReply)
		eventBus.off('composer-quote', this.onComposerQuote)
		eventBus.off('shortcut:compose', this.onComposerFocus)
	},
	methods: {
		expand() {
			this.openedByHand = true
		},

		/**
		 * @param {Event} event a click or a focus somewhere in the document
		 */
		collapseIfIdle(event) {
			if (!this.openedByHand) {
				return
			}

			const target = event.target
			if (!(target instanceof Node) || this.$el.contains(target)) {
				return
			}

			// the emoji picker and the visibility menu are teleported out of this
			// element; using one of them is not leaving the composer
			if (target instanceof Element && target.closest('.v-popper__popper, .modal-mask') !== null) {
				return
			}

			this.openedByHand = false
		},

		/** Puts the caret in the composer, scrolling it into view if need be. */
		focusInput() {
			const input = this.$refs.composerInput
			if (input === undefined) {
				return
			}

			input.focus()
			// the composer sits at the top of the timeline, which may be scrolled away
			if (typeof input.scrollIntoView === 'function') {
				input.scrollIntoView({ block: 'nearest' })
			}
		},

		prefillMessageWithMention(account) {
			if (!this.statusIsEmpty || this.$refs.composerInput === undefined) {
				return
			}

			let handle = account.acct

			if (!handle.includes('@')) {
				handle += `@${this.hostname}`
			}

			const mention = document.createElement('span')
			mention.className = 'mention'
			mention.contentEditable = 'false'

			const link = document.createElement('a')
			link.href = account.url
			link.target = '_blank'

			const avatar = document.createElement('img')
			avatar.src = account.avatar
			link.append(avatar, document.createTextNode(`@${handle}`))
			mention.append(link)

			this.$refs.composerInput.replaceChildren(mention, document.createTextNode('\u00a0'))
			this.updateStatusContent()
		},
		updateStatusContent() {
			this.statusContent = this.$refs.composerInput.innerHTML
			this.statusText = this.plainText()
			this.rememberDraft()
		},
		/**
		 * The composer's contents as the string that would be sent: emoji
		 * images replaced by their alt text, entities decoded, markup gone.
		 *
		 * @return {string}
		 */
		plainText() {
			const input = this.$refs.composerInput
			if (input === undefined || input === null) {
				return ''
			}

			const element = input.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				emoji.replaceWith(document.createTextNode(emoji.getAttribute('alt') ?? ''))
			})

			return he.decode(nodeToPlainText(element).trim())
		},
		/** Keeps what is in the box, so a failed post or a reload cannot eat it. */
		rememberDraft() {
			saveDraft({
				text: this.statusText,
				spoilerText: this.showWarning ? this.spoilerText : '',
				visibility: this.visibility,
			})
		},
		/**
		 * Puts back whatever the last attempt or the last session left, unless
		 * something else has already filled the composer (a reply mention).
		 */
		restoreDraft() {
			const draft = loadDraft()
			if (draft === null || this.$refs.composerInput === undefined) {
				return false
			}

			if (draft.text !== '') {
				this.$refs.composerInput.innerText = draft.text
			}
			if (draft.spoilerText !== '') {
				this.showWarning = true
				this.spoilerText = draft.spoilerText
			}
			// a draft written by another version can name a visibility this one
			// does not have, and VisibilitySelect renders `.text` off the entry
			// it looks up: an unknown id took the whole composer down
			if (isKnownVisibility(draft.visibility) && this.defaultVisibility === undefined) {
				this.visibility = draft.visibility
			}
			this.updateStatusContent()

			return true
		},
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},
		async handleFileChange(event) {
			const target = event.target
			const files = Array.from(target.files)
			// the input keeps its selection, so picking the same file twice in
			// a row would otherwise be ignored the second time
			target.value = ''

			await this.attachFiles(files)
		},

		/**
		 * Whether a drag is carrying files, as opposed to a selection being
		 * dragged around inside the composer — text moved from one line to the
		 * next is not an attachment and must not light the card up.
		 *
		 * @param {Event} event a drag event
		 * @return {boolean}
		 */
		carriesFiles(event) {
			return Array.from(event.dataTransfer?.types ?? []).includes('Files')
		},

		/**
		 * @param {File} file a dropped or pasted file
		 * @return {boolean} whether the file dialog would have offered it
		 */
		acceptsFile(file) {
			return ACCEPTED_MEDIA_TYPES.some((type) => (file.type || '').startsWith(type))
		},

		/** @param {DragEvent} event a drag arriving over the card */
		handleDragEnter(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			event.preventDefault()
			this.draggingFiles = true
		},

		/** @param {DragEvent} event a drag moving over the card */
		handleDragOver(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			// without this the browser keeps the drop for itself and opens the
			// file in the tab, which navigates away and takes the draft with it
			event.preventDefault()
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy'
			}
			this.draggingFiles = true
		},

		/**
		 * dragleave fires just as loudly when the pointer crosses from the card
		 * onto one of its own children, which is where the highlight usually
		 * starts flickering. Where the pointer went is the answer: it has only
		 * left when it went somewhere outside this element, or nowhere at all
		 * (relatedTarget is null when the drag leaves the window). A counter of
		 * enters and leaves would answer the same question, but it can only be
		 * repaired by an event that may never come — one missed leave and the
		 * card stays lit for good — while this is decided fresh every time.
		 *
		 * @param {DragEvent} event the drag leaving something
		 */
		handleDragLeave(event) {
			if (!this.draggingFiles) {
				return
			}

			const movedTo = event.relatedTarget
			if (movedTo instanceof Node && this.$el.contains(movedTo)) {
				return
			}

			this.draggingFiles = false
		},

		/** @param {DragEvent} event the drop itself */
		async handleDrop(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			// same reason as dragover: an unhandled drop is a navigation
			event.preventDefault()
			this.draggingFiles = false
			// dropping a picture is a way of starting a post, so a closed
			// composer opens rather than swallowing the file out of sight
			this.expand()

			await this.attachDropped(Array.from(event.dataTransfer?.files ?? []))
		},

		/**
		 * A picture on the clipboard becomes an attachment; everything else is
		 * left to the contenteditable and its autocomplete, exactly as before.
		 *
		 * @param {ClipboardEvent} event the paste
		 */
		handlePaste(event) {
			const files = Array.from(event.clipboardData?.files ?? []).filter((file) => this.acceptsFile(file))
			if (files.length === 0) {
				// text, a link, a mention pasted back in: none of our business
				return
			}

			// otherwise the browser drops the image into the box as markup the
			// post cannot carry
			event.preventDefault()
			this.attachFiles(files)
		},

		/**
		 * Attaches what the composer takes and turns the rest away, which is
		 * all the file dialog does with them — it never offers them at all.
		 *
		 * @param {File[]} files everything that was dropped
		 */
		async attachDropped(files) {
			const accepted = files.filter((file) => this.acceptsFile(file))

			if (accepted.length < files.length) {
				logger.debug('Refused files the composer does not take', { refused: files.length - accepted.length })
				this.refuseDrop()
			}

			if (accepted.length === 0) {
				return
			}

			await this.attachFiles(accepted)
		},

		/** Says no to a drop, briefly and once. */
		refuseDrop() {
			this.refusedDrop = true
			window.clearTimeout(this.refusalTimer)
			this.refusalTimer = window.setTimeout(() => {
				this.refusedDrop = false
			}, REFUSAL_DURATION)
		},

		/**
		 * How many of these there is still room for, with a word about the rest.
		 *
		 * The server refuses the ninth attachment outright, so the refusal
		 * belongs here, where it can still be explained and where the eight
		 * that do fit are not lost with it.
		 *
		 * @param {Array} items files or paths, in the order they were offered
		 * @return {Array} the ones the post can still carry
		 */
		roomFor(items) {
			const room = Math.max(MAX_ATTACHMENTS - Object.keys(this.attachments).length, 0)
			if (items.length > room) {
				this.announceCeiling()
			}

			return items.slice(0, room)
		},

		/** Says that the post is carrying as much as it can. */
		announceCeiling() {
			showError(translatePlural(
				'social',
				'A post can carry %n attachment',
				'A post can carry %n attachments',
				MAX_ATTACHMENTS,
			))
		},

		/**
		 * Attaches pictures the reader already has in Nextcloud, without a trip
		 * through the browser: the path is all that is sent.
		 */
		async pickFromFiles() {
			if (this.attachmentsFull) {
				this.announceCeiling()
				return
			}

			let picked
			this.picking = true
			try {
				picked = await getFilePickerBuilder(translate('social', 'Pick pictures to attach'))
					.setMultiSelect(true)
					.setMimeTypeFilter(PICKABLE_MEDIA_TYPES)
					.allowDirectories(false)
					.build()
					.pick()
			} catch (error) {
				// closing the dialog without picking rejects, and changing one's
				// mind is not a failure to report
				logger.debug('The file picker was closed', { error })
				return
			} finally {
				this.picking = false
			}

			const paths = (Array.isArray(picked) ? picked : [picked])
				.filter((path) => typeof path === 'string' && path !== '' && path !== '/')

			if (paths.length === 0) {
				return
			}

			this.expand()
			await this.attachPaths(paths)
		},

		/**
		 * Asks the server for one attachment per path, in order, keeping the
		 * ones it accepts. A path it refuses is marked and left in the grid:
		 * the others are already attached and must not go down with it.
		 *
		 * @param {string[]} paths files in the reader's own storage
		 */
		async attachPaths(paths) {
			const accepted = this.roomFor(paths)

			this.picking = accepted.length > 0
			this.progressLabel = translate('social', 'Attaching from Files…')
			for (const [index, path] of accepted.entries()) {
				// the same picture may be picked twice, and the path cannot
				// tell those two attachments apart
				const key = `nextcloud:${++this.pickCount}:${path}`
				this.attachments = {
					...this.attachments,
					[key]: { file: null, path, data: null, failed: false },
				}

				this.uploading = true
				this.uploadProgress = index / accepted.length
				const mediaData = await this.timelineStore.createMediaFromFile({ path })
				this.uploadProgress = (index + 1) / accepted.length

				if (this.attachments[key] === undefined) {
					// deleted while the server was fetching it
					continue
				}

				this.attachments = {
					...this.attachments,
					[key]: {
						...this.attachments[key],
						data: mediaData?.id === undefined ? null : mediaData,
						failed: mediaData?.id === undefined,
					},
				}
			}
			this.uploading = false
			this.uploadProgress = 0
			this.progressLabel = ''
			this.picking = false
		},

		/**
		 * Previews each file, uploads it, and remembers what came back. The one
		 * road in: the file dialog, a drop and a paste all arrive here.
		 *
		 * @param {File[]} allFiles the files to attach, in order
		 */
		async attachFiles(allFiles) {
			const files = this.roomFor(allFiles)
			this.progressLabel = translate('social', 'Uploading…')
			for (const [index, file] of files.entries()) {
				const url = URL.createObjectURL(file)
				this.attachments = {
					...this.attachments,
					[url]: {
						file,
						data: null,
						failed: false,
					},
				}

				this.uploading = true
				// real progress, from the request itself: the bar used to be
				// hard-coded to 40% behind a `v-if="false"`
				this.uploadProgress = index / files.length
				const mediaData = await this.timelineStore.createMedia({
					file,
					onProgress: (fraction) => {
						this.uploadProgress = (index + fraction) / files.length
					},
				})
				this.uploading = false
				this.uploadProgress = 0

				if (this.attachments[url] === undefined) {
					// deleted while it was uploading
					continue
				}

				this.attachments = {
					...this.attachments,
					[url]: {
						...this.attachments[url],
						// a failed upload is marked, never left as
						// `data: undefined` for the submit path to trip over
						data: mediaData?.id === undefined ? null : mediaData,
						failed: mediaData?.id === undefined,
					},
				}
			}
			this.progressLabel = ''
		},
		insert(emoji) {
			if (typeof emoji === 'object') {
				const category = Object.keys(emoji)[0]
				const emojis = emoji[category]
				const firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}

			const lastChild = this.$refs.composerInput.lastChild
			const div = document.createElement('div')
			div.textContent = emoji + ' '

			if (lastChild === null) {
				this.$refs.composerInput.innerHTML = div.innerHTML
			} else {
				switch (lastChild.tagName) {
				case 'BR':
					lastChild.before(div.firstChild)
					break
				case 'DIV':
					switch (lastChild.lastChild.tagName) {
					case 'BR':
						lastChild.lastChild.before(div.firstChild)
						break
					default:
						lastChild.append(div.firstChild)
					}
					break
				default:
					lastChild.after(div.firstChild)
				}
			}
			this.updateStatusContent()
		},
		keyup(event) {
			if (event.ctrlKey) {
				this.createPost()
			}
		},
		updatePostFromTribute() {
			this.updateStatusContent()
		},
		n: translatePlural,
		async createPost() {
			if (!this.canPost || this.loading) {
				return
			}

			const status = this.plainText()
			const warning = this.showWarning ? this.spoilerText.trim() : ''

			const statusData = {
				content_type: '',
				// only uploads the server actually took: a failed one used to
				// be read as `preview.data.id` and threw a TypeError here
				media_ids: this.mediaIds,
				// a warning means the body is hidden until asked for, which is
				// what `sensitive` says about the post as a whole
				sensitive: warning !== '',
				spoiler_text: warning,
				status,
				in_reply_to_id: this.replyTo?.id,
				quote_id: this.quoteOf?.id,
				visibility: this.visibility,
			}

			const pollOptions = this.pollOptions.map((option) => option.trim()).filter((option) => option !== '')
			if (this.showPoll && pollOptions.length >= 2) {
				statusData.poll = {
					options: pollOptions,
					expires_in: this.pollExpiresIn,
					multiple: this.pollMultiple,
				}
			}

			logger.debug('Posting status', { visibility: statusData.visibility, attachments: statusData.media_ids.length })

			let created
			try {
				this.loading = true
				await this.saveDescriptions()
				// `post` resolves with the created status and rejects when the
				// server said no; clearing in a `finally` used to throw the
				// text away on every failure, offline included
				created = await this.timelineStore.post(statusData)
			} finally {
				this.loading = false
			}

			if (created === undefined) {
				// the store has already said what went wrong; the draft is
				// still on disk and still in the box
				this.rememberDraft()
				return
			}

			this.replyTo = null
			this.quoteOf = null
			this.$refs.composerInput.innerText = ''
			Object.keys(this.attachments).forEach((key) => this.releasePreview(key))
			this.attachments = {}
			this.showPoll = false
			this.pollOptions = ['', '']
			this.pollMultiple = false
			this.showWarning = false
			this.spoilerText = ''
			clearDraft()
			this.updateStatusContent()
			this.timelineStore.refreshTimeline()
			// the sidebar's modal has no other way of knowing: it cleared the
			// box and stayed open, which reads as if nothing had happened
			this.$emit('posted')
			eventBus.emit('post-published', created)
		},
		toggleWarning() {
			this.showWarning = !this.showWarning
			if (!this.showWarning) {
				this.spoilerText = ''
			}
		},
		togglePoll() {
			this.showPoll = !this.showPoll
			if (!this.showPoll) {
				this.pollOptions = ['', '']
				this.pollMultiple = false
			}
		},
		closeReply() {
			this.replyTo = null
			this.timelineStore.setComposerDisplayStatus(false)
		},
		removeQuote() {
			// only the quote goes, unlike closeReply(): the message is the
			// reader's own and taking the embed back is no reason to lose it
			this.quoteOf = null
		},
		remoteSearchAccounts(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: text } })
		},
		remoteSearchHashtags(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/tags/search'), { params: { search: text } })
		},
		deletePreview(key) {
			const newAttachments = { ...this.attachments }
			delete newAttachments[key]
			this.attachments = newAttachments
			this.releasePreview(key)
		},
		/**
		 * Lets go of the blob URL a preview was drawn from. Without this the
		 * file stays in memory for the life of the document.
		 *
		 * @param {string} key the attachment key, which is that URL
		 */
		releasePreview(key) {
			// an attachment picked out of Files is keyed by its path: there is
			// no object URL behind it to let go of
			if (!key.startsWith('blob:')) {
				return
			}

			try {
				URL.revokeObjectURL(key)
			} catch (error) {
				logger.debug('Could not release a preview URL', { error })
			}
		},
		/**
		 * Remembers what an attachment shows. Kept locally while the post is
		 * being written and sent when it goes, rather than on every keystroke.
		 *
		 * @param {object} update what changed
		 * @param {string} update.key which attachment
		 * @param {string} update.description what it shows
		 */
		/**
		 * Sends whatever descriptions were written, once, as the post goes.
		 *
		 * Saving per keystroke would be a request per letter; saving here means
		 * the description travels with the post that carries the picture.
		 */
		async saveDescriptions() {
			const described = Object.values(this.attachments).filter(
				(attachment) => attachment.data?.id
					&& (attachment.description || '').trim() !== ''
					&& (attachment.description || '').trim() !== attachment.saved,
			)

			await Promise.all(described.map((attachment) => this.timelineStore.describeMedia({
				id: attachment.data.id,
				description: attachment.description.trim(),
			})))
		},
		/**
		 * Saves what an attachment shows as soon as the field is left, so a
		 * description outlives a post that never went out.
		 *
		 * @param {object} update what was written
		 * @param {string} update.key which attachment
		 * @param {string} update.description what it shows
		 */
		async commitDescription({ key, description }) {
			const attachment = this.attachments[key]
			const text = (description || '').trim()
			if (attachment?.data?.id === undefined || text === '' || text === attachment.saved) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...attachment, description, saved: text },
			}

			await this.timelineStore.describeMedia({ id: attachment.data.id, description: text })
		},
		describeAttachment({ key, description }) {
			if (this.attachments[key] === undefined) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...this.attachments[key], description },
			}
		},
	},
}

/**
 * The visibility the last post went out with.
 *
 * Reading localStorage throws outright in a private window and where site
 * data is blocked, and an unguarded read here took the whole composer down
 * with it.
 *
 * @return {string} the remembered visibility, or '' when there is none
 */
function rememberedVisibility() {
	let remembered
	try {
		remembered = window.localStorage.getItem('social.lastPostType') ?? ''
	} catch {
		return ''
	}

	return isKnownVisibility(remembered) ? remembered : ''
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
// one duration and one curve for the whole opening, so the parts of it arrive
// together rather than each on its own schedule
$composer-ease: cubic-bezier(0.25, 0.8, 0.35, 1);
$composer-duration: 220ms;

.new-post {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 18px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	// the full column, where the list only fills it inside its own gutter: the
	// box you write in reaches a little past the posts it will join on both
	// sides, so it reads as the thing that makes them rather than one of them
	max-width: var(--social-column);
	position: sticky;
	top: 0;
	z-index: 100;
	box-shadow: var(--social-elevation-resting);
	transition:
		padding $composer-duration $composer-ease,
		border-color $composer-duration $composer-ease,
		background-color $composer-duration $composer-ease,
		box-shadow $composer-duration $composer-ease;

	// lifted, not outlined: the box the caret is in draws the ring, and two
	// nested rings around the same caret is one too many
	&:focus-within {
		box-shadow: var(--social-elevation-raised);
	}

	&-form {
		margin-top: 12px;
		margin-inline-start: 0;
		transition: margin-top $composer-duration $composer-ease;

		&__emoji-picker {
			z-index: 1;
		}
	}
}

// Closed: a portrait and a line to write on. Everything else is still in the
// document — it is measured, not removed, so the opening can be animated — but
// it is out of the tab order and out of the accessibility tree until it is.
.new-post--collapsed {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;

	.new-post-author {
		padding-bottom: 0;
		margin-bottom: 0;
		border-bottom: none;
	}

	.new-post-form {
		flex: 1 1 auto;
		min-width: 0;
		margin-top: 0;
	}

	.message {
		min-height: 0;
		padding: 9px 16px;
		border-radius: 999px;
	}

	.options {
		max-height: 0;
		margin-top: 0;
		opacity: 0;
		visibility: hidden;
		pointer-events: none;
		transform: translateY(-4px);
	}
}

// A file is being dragged over the card: the whole thing is the target, said
// with a tint and the primary border it already uses for focus, plus one pill
// naming what will happen. No dashes, no bounce — it is an invitation, not an
// alarm, and it borrows the composer's own duration and curve.
.new-post--drop-target {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light, var(--color-background-hover));

	.message {
		border-color: var(--color-primary-element);
	}
}

.new-post__drop-hint {
	position: absolute;
	inset: 0;
	z-index: 2;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: inherit;
	// it must never take the drop it is announcing, and it must not turn the
	// card into a storm of dragenter/dragleave by sitting under the pointer
	pointer-events: none;

	span {
		padding: 6px 14px;
		border-radius: var(--border-radius-pill, 999px);
		background: var(--color-primary-element);
		color: var(--color-primary-element-text, var(--color-primary-text));
		font-size: 13px;
		font-weight: 600;
		box-shadow: var(--social-elevation-raised);
		animation: composer-drop-hint $composer-duration $composer-ease;
	}
}

@keyframes composer-drop-hint {
	0% { opacity: 0; transform: scale(.94); }
	100% { opacity: 1; transform: scale(1); }
}

/* what was dropped is not something the composer can take */
@keyframes composer-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

.new-post--refused {
	// 400ms, the same span REFUSAL_DURATION keeps the class on for
	animation: composer-refused 400ms $composer-ease;
}

@media (prefers-reduced-motion: reduce) {
	.new-post,
	.new-post .new-post-form,
	.new-post .message,
	.new-post .options {
		transition: none;
	}

	.new-post--refused,
	.new-post__drop-hint span {
		animation: none;
	}
}

.new-post-author {
	display: flex;
	align-items: center;
	gap: 10px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 10px;

	.post-author {
		display: flex;
		align-items: center;

		.post-author-name {
			font-weight: 700;
			font-size: 14px;
			line-height: 1.3;
		}
	}
}

.reply-to {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 12px 12px 12px 36px;
	margin-bottom: 12px;
	position: relative;

	&::before {
		content: '';
		position: absolute;
		inset-inline-start: 12px;
		top: 12px;
		width: 16px;
		height: 16px;
		background-image: url(../../../img/reply.svg);
		background-size: contain;
		background-repeat: no-repeat;
	}

	.avatardiv {
		margin: 0 4px;
		vertical-align: middle;
	}

	.reply-info {
		display: flex;
		align-items: center;
		gap: 4px;
		font-size: 13px;
		color: var(--color-text-lighter);
		margin-bottom: 4px;
	}

	.close-button {
		margin-inline-start: auto;
		min-width: 28px;
		min-height: 28px;
		height: 28px;
		width: 28px !important;
	}
}

/* set in and ruled off, the same way a quote reads in the timeline */
.quote-of {
	background: var(--color-background-hover);
	border-inline-start: 3px solid var(--color-border-dark);
	border-radius: 8px;
	padding: 12px;
	margin-bottom: 12px;
	font-size: 14px;

	.avatardiv {
		margin: 0 4px;
		vertical-align: middle;
	}

	.quote-info {
		display: flex;
		align-items: center;
		gap: 4px;
		font-size: 13px;
		color: var(--color-text-lighter);
		margin-bottom: 4px;
	}

	.close-button {
		margin-inline-start: auto;
		min-width: 28px;
		min-height: 28px;
		height: 28px;
		width: 28px !important;
	}
}

.message {
	width: 100%;
	min-height: 80px;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	transition:
		min-height $composer-duration $composer-ease,
		padding $composer-duration $composer-ease,
		border-radius $composer-duration $composer-ease,
		border-color $composer-duration $composer-ease;
	background: var(--color-main-background);
	font-size: 14px;
	line-height: 1.6;
	color: var(--color-main-text);
	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}

	&.too-long {
		color: var(--color-error);
		border-color: var(--color-error);
	}

	:deep(.mention) {
		color: var(--color-primary-element);
		background-color: var(--color-background-dark);
		border-radius: 4px;
		padding: 1px 6px 1px 2px;
		display: inline-flex;
		align-items: center;

		img {
			width: 16px;
			height: 16px;
			border-radius: 50%;
			margin-inline-end: 3px;
		}
	}
}

// Photo-first: the picture is the post and the box under it is its caption,
// so the box gives up the height it was holding for a post that has no picture.
// Nothing is moved or hidden — the same controls in the same order, weighted
// the other way round.
.new-post-form--media-first {
	:deep(.preview-grid) {
		margin-bottom: 10px;
	}

	.message--caption {
		min-height: 44px;
	}
}

[contenteditable=true]:empty:before {
	content: attr(placeholder);
	display: block;
	color: var(--color-text-lighter);
}

.options {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
	max-height: 60px;
	opacity: 1;
	overflow: hidden;
	transform: translateY(0);
	transition:
		max-height $composer-duration $composer-ease,
		margin-top $composer-duration $composer-ease,
		opacity $composer-duration $composer-ease,
		transform $composer-duration $composer-ease,
		visibility $composer-duration step-start;
}

.emptySpace {
	flex-grow: 1;
}

.hashtag {
	color: var(--color-primary-element);
	text-decoration: none;
}
</style>
<style lang="scss">
.tribute-container {
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	height: auto;
	max-height: 300px;
	max-width: 500px;
	min-width: 200px;
	overflow: auto;
	display: block;
	z-index: 999999;
	border-radius: 8px;
	border: 1px solid var(--color-border);

	ul {
		margin: 0;
		margin-top: 2px;
		padding: 4px;
		list-style: none;
		background: var(--color-main-background);
		border-radius: 8px;
		background-clip: padding-box;
		overflow: hidden;

		li {
			color: var(--color-text);
			padding: 6px 10px;
			cursor: pointer;
			font-size: 14px;
			display: flex;
			border-radius: 6px;
			margin: 2px 0;

			span {
				display: block;
				font-weight: bold;
			}

			&.highlight,
			&:hover {
				background: var(--color-primary);
				color: var(--color-primary-text);
			}

			img {
				width: 32px;
				height: 32px;
				border-radius: 50%;
				overflow: hidden;
				margin-inline: -3px 10px;
				margin-top: 3px;
			}

			&.no-match {
				cursor: default;
			}
		}
	}

	.menu-highlighted {
		font-weight: bold;
	}

	.account,
	li.highlight .account,
	li:hover .account {
		font-weight: normal;
		color: var(--color-text-light);
	}

	li.highlight .account,
	li:hover .account {
		color: var(--color-primary-text) !important;
	}
}

.poll-editor {
	margin: 8px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);

	&__option {
		display: flex;
		gap: 4px;
		margin-bottom: 4px;

		input[type='text'] {
			flex-grow: 1;
		}
	}

	&__settings {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}
}
/* the allowance as a ring that fills, rather than a limit you discover */
.composer-alt-warning {
	align-self: center;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	font-weight: 600;
}

.char-ring {
	position: relative;
	width: 24px;
	height: 24px;
	flex-shrink: 0;
	align-self: center;
	border-radius: 50%;
	background: conic-gradient(
		var(--color-primary-element) calc(var(--char-progress) * 360deg),
		var(--color-background-dark) 0
	);
	transition: background .2s ease;

	&::after {
		content: '';
		position: absolute;
		inset: 3px;
		border-radius: 50%;
		background: var(--color-main-background);
	}

	&--warning {
		background: conic-gradient(
			var(--color-warning) calc(var(--char-progress) * 360deg),
			var(--color-background-dark) 0
		);
	}

	&--over {
		background: var(--color-error);
	}

	&__count {
		position: absolute;
		inset: 0;
		z-index: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 10px;
		font-weight: bold;
		color: var(--color-main-text);
	}
}

@media (prefers-reduced-motion: reduce) {
	.char-ring {
		transition: none;
	}
}

.content-warning {
	width: 100%;
	margin-bottom: 6px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;

	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}
</style>
