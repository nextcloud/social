<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
  - @copyright Copyright (c) 2022 Carl Schwan <carl@carlschwan.eu>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<div class="new-post" data-id="">
		<input id="file-upload"
			ref="fileUploadInput"
			type="file"
			accept="image/*"
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
				<NcButton type="tertiary"
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
		<form class="new-post-form" @submit.prevent="createPost">
			<VueTribute :options="tributeOptions">
				<div ref="composerInput"
					:contenteditable="!loading"
					class="message"
					placeholder="What would you like to share?"
					:class="{'icon-loading': loading}"
					@keyup.prevent.enter="keyup"
					@input="updateStatusContent"
					@tribute-replaced="updatePostFromTribute" />
			</VueTribute>

			<PreviewGrid :uploading="false"
				:upload-progress="0.4"
				:miniatures="attachments"
				@deleted="deletePreview" />

			<div class="options">
				<NcButton :title="t('social', 'Add attachment')"
					type="tertiary"
					:aria-label="t('social', 'Add attachment')"
					@click.prevent="clickImportInput">
					<template #icon>
						<FileUpload :size="22" decorative title="" />
					</template>
				</NcButton>

				<div class="new-post-form__emoji-picker">
					<NcEmojiPicker ref="emojiPicker"
						:search="search"
						:close-on-select="false"
						container="#content-vue"
						@select="insert">
						<NcButton :title="t('social', 'Add emoji')"
							type="tertiary"
							:aria-haspopup="true"
							:aria-label="t('social', 'Add emoji')">
							<template #icon>
								<EmoticonOutline :size="22" decorative title="" />
							</template>
						</NcButton>
					</NcEmojiPicker>
				</div>

				<VisibilitySelect :visibility.sync="visibility" />
				<div class="emptySpace" />
				<SubmitStatusButton :visibility="visibility" :disabled="!canPost || loading" @click="createPost" />
			</div>
		</form>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import debounce from 'debounce'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmojiPicker from '@nextcloud/vue/dist/Components/NcEmojiPicker.js'
import VueTribute from 'vue-tribute'
import he from 'he'
import CurrentUserMixin from '../../mixins/currentUserMixin.js'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import PreviewGrid from './PreviewGrid.vue'
import VisibilitySelect from './VisibilitySelect.vue'
import SubmitStatusButton from './SubmitStatusButton.vue'
import MessageContent from '../MessageContent.js'

/**
 * @typedef LocalAttachment
 * @property {File} file - The file object from the input element.
 * @property {import('../../types/Mastodon.js').MediaAttachment} data - The attachment information from the server.
 */

export default {
	name: 'Composer',
	components: {
		NcAvatar,
		NcEmojiPicker,
		NcButton,
		ActorAvatar,
		FileUpload,
		VueTribute,
		EmoticonOutline,
		Close,
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
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status|null>} */
		initialMention: {
			type: Object,
			default: null,
		},
		defaultVisibility: {
			type: String,
			default: localStorage.getItem('social.lastPostType') || 'followers',
		},
	},
	data() {
		return {
			statusContent: '',
			visibility: this.defaultVisibility,
			loading: false,
			/** @type {Object<string, LocalAttachment>} */
			attachments: {},
			search: '',
			/** @type {import('../../types/Mastodon.js').Status} */
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
							return `
								<span class="mention" contenteditable="false">
									<a href="${item.original.url}" target="_blank">
										<img src="${item.original.avatar}"/>
										@${item.original.value}
									</a>
								</span>`
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

							console.debug('[Composer] Found users for', text, response.data.result, users)
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
							// item is undefined if selectTemplate is called from a noMatchTemplate menu
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

							console.debug('[Composer] Found tags for', text, response.data.result, tags)
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
		/** @return {boolean} */
		canPost() {
			if (Object.values(this.attachments).some(({ data }) => data === null)) {
				return false
			}

			if (Object.keys(this.attachments).length > 0) {
				return true
			}

			return this.statusContent.length !== 0 && this.statusContent !== '<br>'
		},
	},
	mounted() {
		this.$root.$on('composer-reply', (data) => {
			this.replyTo = data
			this.visibility = data.visibility
		})

		if (this.initialMention !== null) {
			this.$refs.composerInput.innerHTML = `
				<span class="mention" contenteditable="false">
					<a href="${this.initialMention.url}" target="_blank">
						<img src="${!this.initialMention.acct.includes('@') ? generateUrl(`/avatar/${this.initialMention.username}/32`) : generateUrl(`apps/social/api/v1/global/actor/avatar?id=${this.initialMention.acct}`)}"/>
						@${this.initialMention.acct}
					</a>
				</span>&nbsp;`
			this.updateStatusContent()
		}
	},
	methods: {
		updateStatusContent() {
			this.statusContent = this.$refs.composerInput.innerHTML
		},
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},
		/** @param {InputEvent} event */
		handleFileChange(event) {
			/** @type {HTMLInputElement} */
			const target = event.target
			Array.from(target.files).forEach(async (file) => {
				const url = URL.createObjectURL(file)
				this.$set(this.attachments, url, {
					file,
					data: null,
				})
				this.$set(this.attachments[url], 'data', await this.$store.dispatch('createMedia', file))
			})
		},
		insert(emoji) {
			console.debug('[Composer] insert emoji', emoji)
			if (typeof emoji === 'object') {
				const category = Object.keys(emoji)[0]
				const emojis = emoji[category]
				const firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}

			/** @type {Element} */
			const lastChild = this.$refs.composerInput.lastChild
			const div = document.createElement('div')
			div.innerHTML = this.$twemoji.parse(emoji) + ' '

			if (lastChild === null) {
				this.$refs.composerInput.innerHTML = div.innerHTML
			} else {

				// Content usually ends with </br> or </>
				// This makes sure that we put the emoji before those tags.
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
			if (event.shiftKey || event.ctrlKey) {
				this.createPost(event)
			}
		},
		updatePostFromTribute(event) {
			console.debug('[Composer] update from tribute', event)
			this.updateStatusContent()
		},
		async createPost(event) {
			// Replace emoji <img> tag with actual emojis.
			// They will be replaced again with twemoji during rendering
			const element = this.$refs.composerInput.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				const em = document.createTextNode(emoji.getAttribute('alt'))
				emoji.replaceWith(em)
			})

			let status = element.innerHTML.replace(/<(?!\/div)[^>]+>/gi, '').replace(/<\/div>/gi, '\n').trim()
			status = he.decode(status)

			const statusData = {
				content_type: '',
				media_ids: Object.values(this.attachments).map(preview => preview.data.id),
				sensitive: false,
				spoiler_text: '',
				status,
				in_reply_to_id: this.replyTo?.id,
				visibility: this.visibility,
			}

			console.debug('[Composer] Posting status', statusData)

			// Post message
			try {
				this.loading = true
				await this.$store.dispatch('post', statusData)
			} finally {
				this.loading = false
				this.replyTo = null
				this.$refs.composerInput.innerText = ''
				this.attachments = {}
				this.$store.dispatch('refreshTimeline')
			}
		},
		closeReply() {
			this.replyTo = null
			// View may want to hide the composer
			this.$store.commit('setComposerDisplayStatus', false)
		},
		remoteSearchAccounts(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: text } })
		},
		remoteSearchHashtags(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/tags/search'), { params: { search: text } })
		},
		deletePreview(key) {
			this.$delete(this.attachments, key)
		},
	},
}
</script>

<style scoped lang="scss">
.new-post {
	padding: 10px;
	background-color: var(--color-main-background);
	position: sticky;
	z-index: 100;
	margin-bottom: 10px;
	top: 0;

	&-form {
		flex-grow: 1;
		position: relative;
		top: -10px;
		margin-left: 39px;
		&__emoji-picker {
			z-index: 1;
		}
	}
}

.new-post-author {
	padding: 5px;
	display: flex;
	flex-wrap: wrap;

	.post-author {
		padding: 6px;

		.post-author-name {
			font-weight: bold;
		}

		.post-author-id {
			opacity: .7;
		}
	}
}

.reply-to {
	background-image: url(../../../img/reply.svg);
	background-position: 8px 12px;
	background-repeat: no-repeat;
	margin-left: 39px;
	margin-bottom: 20px;
	overflow: hidden;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 5px;
	padding-left: 30px;

	.reply-info {
		display: flex;
		align-items: center;
	}
	.close-button {
		margin-left: auto;
		opacity: .7;
		min-width: 30px;
		min-height: 30px;
		height: 30px;
		width: 30px !important;
	}
}

.message {
	width: 100%;
	padding-right: 44px;
	min-height: 70px;
	min-width: 2px;
	display: block;

	:deep(.mention) {
		color: var(--color-primary-element);
		background-color: var(--color-background-dark);
		border-radius: 5px;
		padding-top: 1px;
		padding-left: 2px;
		padding-bottom: 1px;
		padding-right: 5px;

		img {
			width: 16px;
			border-radius: 50%;
			overflow: hidden;
			margin-right: 3px;
			vertical-align: middle;
			margin-top: -1px;
		}
	}
}

[contenteditable=true]:empty:before {
	content: attr(placeholder);
	display: block; /* For Firefox */
	opacity: .5;
}

input[type=submit].inline {
	width: 44px;
	height: 44px;
	margin: 0;
	padding: 13px;
	background-color: transparent;
	border: none;
	opacity: 0.3;
	position: absolute;
	bottom: 0;
	right: 0;
}

.options {
	display: flex;
	align-items: flex-end;
	width: 100%;
	margin-top: 0.5rem;
}

.emptySpace {
	flex-grow:1;
}

.attachment-picker-wrapper {
	position: absolute;
	right: 0;
	top: 2;
}

.hashtag {
	text-decoration: underline;
}
</style>
<style lang="scss">
/* Tribute-specific styles TODO: properly scope component css */
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
		border-radius: 4px;
		box-shadow: 0 1px 3px var(--color-box-shadow);

		ul {
			margin: 0;
			margin-top: 2px;
			padding: 0;
			list-style: none;
			background: var(--color-main-background);
			border-radius: 4px;
			background-clip: padding-box;
			overflow: hidden;

			li {
				color: var(--color-text);
				padding: 5px 10px;
				cursor: pointer;
				font-size: 14px;
				display: flex;

				span {
					display: block;
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

				span {
					font-weight: bold;
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
			opacity: 0.5;
		}

		li.highlight .account,
		li:hover .account {
			color: var(--color-primary-text) !important;
			opacity: .6;
		}
}
</style>
