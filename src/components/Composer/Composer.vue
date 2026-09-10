<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="new-post" data-id="">
		<input id="file-upload"
			ref="fileUploadInput"
			type="file"
			accept="image/*,video/*,audio/*"
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
				<span class="post-author-id">
					{{ socialId }}
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
		<form class="new-post-form" @submit.prevent>
			<input v-if="showWarning"
				v-model="spoilerText"
				type="text"
				class="content-warning"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')">
			<div ref="composerInput"
				:contenteditable="!loading"
				class="message"
				role="textbox"
				aria-multiline="true"
				:aria-label="t('social', 'What would you like to share?')"
				:aria-describedby="statusIsTooLong ? 'composer-length' : undefined"
				:placeholder="t('social', 'What would you like to share?')"
				:class="{'icon-loading': loading, 'too-long': statusIsTooLong}"
				@keyup.prevent.enter="keyup"
				@input="updateStatusContent"
				@tribute-replaced="updatePostFromTribute" />

			<PreviewGrid :uploading="uploading"
				:upload-progress="uploadProgress"
				:miniatures="attachments"
				@deleted="deletePreview"
				@describe="describeAttachment" />

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
					@click.prevent="clickImportInput">
					<template #icon>
						<Paperclip :size="22" decorative title="" />
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
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import debounce from 'debounce'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmojiPicker from '@nextcloud/vue/components/NcEmojiPicker'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import { translatePlural } from '@nextcloud/l10n'
import he from 'he'
import CurrentUserMixin from '../../mixins/currentUserMixin.js'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import PreviewGrid from './PreviewGrid.vue'
import VisibilitySelect from '../Visibility/VisibilitySelect.vue'
import SubmitStatusButton from './SubmitStatusButton.vue'
import MessageContent from '../MessageContent.js'
import Tribute from 'tributejs'
import eventBus from '../../services/eventBus.js'
import logger from '../../services/logger.js'
import { clearDraft, loadDraft, saveDraft } from '../../services/draft.js'

/** what the server accepts in one status */
const MAX_LENGTH = 500

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
	mixins: [CurrentUserMixin],
	props: {
		initialMention: {
			type: Object,
			default: null,
		},
		defaultVisibility: {
			type: String,
			default: undefined,
		},
	},
	data() {
		return {
			statusContent: '',
			/** what would actually be sent — the string the counter measures */
			statusText: '',
			visibility: this.defaultVisibility || rememberedVisibility() || 'followers',
			loading: false,
			/** whether an attachment is on its way to the server */
			uploading: false,
			/** how far the current upload has got, 0..1 */
			uploadProgress: 0,
			attachments: {},
			showPoll: false,
			showWarning: false,
			spoilerText: '',
			pollOptions: ['', ''],
			pollMultiple: false,
			pollExpiresIn: 86400,
			search: '',
			replyTo: null,
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
							let tag = ''
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

		// the shortcuts help offers "n" to write a post; this is what answers it
		this.onComposerFocus = () => this.focusInput()
		eventBus.on('shortcut:compose', this.onComposerFocus)

		// before the mention prefill, which declines to overwrite a non-empty
		// composer: whatever the last attempt left is what the reader wants back
		this.restoreDraft()

		if (this.initialMention !== null) {
			this.prefillMessageWithMention(this.initialMention)
		}
	},
	unmounted() {
		if (this.tribute && this.tributeTarget) {
			this.tribute.detach(this.tributeTarget)
		}
		eventBus.off('composer-reply', this.onComposerReply)
		eventBus.off('shortcut:compose', this.onComposerFocus)
	},
	methods: {
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
			if (draft.visibility !== '' && this.defaultVisibility === undefined) {
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
				const mediaData = await this.$store.dispatch('createMedia', {
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

			let sent = false
			try {
				this.loading = true
				await this.saveDescriptions()
				// `post` resolves with the created status and rejects when the
				// server said no; clearing in a `finally` used to throw the
				// text away on every failure, offline included
				sent = await this.$store.dispatch('post', statusData) !== undefined
			} finally {
				this.loading = false
			}

			if (!sent) {
				// the store has already said what went wrong; the draft is
				// still on disk and still in the box
				this.rememberDraft()
				return
			}

			this.replyTo = null
			this.$refs.composerInput.innerText = ''
			this.attachments = {}
			this.showPoll = false
			this.pollOptions = ['', '']
			this.pollMultiple = false
			this.showWarning = false
			this.spoilerText = ''
			clearDraft()
			this.updateStatusContent()
			this.$store.dispatch('refreshTimeline')
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
			this.$store.commit('setComposerDisplayStatus', false)
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
			// the key is the blob URL the preview was drawn from; without this
			// the file stays in memory for the life of the document
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
				(attachment) => attachment.data?.id && (attachment.description || '').trim() !== '',
			)

			await Promise.all(described.map((attachment) => this.$store.dispatch('describeMedia', {
				id: attachment.data.id,
				description: attachment.description.trim(),
			})))
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
	try {
		return window.localStorage.getItem('social.lastPostType') ?? ''
	} catch (error) {
		return ''
	}
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
.new-post {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 18px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	max-width: 600px;
	position: sticky;
	top: 0;
	z-index: 100;

	&-form {
		margin-top: 12px;
		margin-left: 0;

		&__emoji-picker {
			z-index: 1;
		}
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
		flex-direction: column;

		.post-author-name {
			font-weight: 700;
			font-size: 14px;
			line-height: 1.3;
		}

		.post-author-id {
			font-size: 12px;
			color: var(--color-text-lighter);
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
		left: 12px;
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
		margin-left: auto;
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
			margin-right: 3px;
		}
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
	left: 0;
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
				margin-right: 10px;
				margin-left: -3px;
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
