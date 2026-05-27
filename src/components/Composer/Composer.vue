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
		<form class="new-post-form" @submit.prevent>
			<Tribute :options="tributeOptions">
				<div ref="composerInput"
					:contenteditable="!loading"
					class="message"
					:placeholder="t('social', 'What would you like to share?')"
					:class="{'icon-loading': loading, 'too-long': statusIsTooLong}"
					@keyup.prevent.enter="keyup"
					@input="updateStatusContent"
					@tribute-replaced="updatePostFromTribute" />
			</Tribute>

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
						<Paperclip :size="22" decorative title="" />
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

				<VisibilitySelect :visibility="visibility" @update:visibility="visibility = $event" />
				<div class="emptySpace" />
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
import Tribute from 'tributejs'
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
import eventBus from '../../services/eventBus.js'

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
			visibility: this.defaultVisibility || localStorage.getItem('social.lastPostType') || 'followers',
			loading: false,
			attachments: {},
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
		canPost() {
			if (Object.values(this.attachments).some(({ data }) => data === null)) {
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

			if (Object.keys(this.attachments).length > 0) {
				return true
			}

			return true
		},
		statusIsEmpty() {
			return this.statusContent.length === 0 || this.statusContent === '<br>'
		},

		statusIsTooLong() {
			return this.statusContent.length > 500
		},

		hasMentions() {
			const text = he.decode(this.statusContent.replace(/<[^>]+>/g, ' '))
			return /(?:^|\s)@[a-zA-Z0-9_.-]+/i.test(text)
		},
	},
	mounted() {
		eventBus.on('composer-reply', (data) => {
			this.replyTo = data
			this.prefillMessageWithMention(data.account)
			this.visibility = data.visibility
		})

		if (this.initialMention !== null) {
			this.prefillMessageWithMention(this.initialMention)
		}
	},
	unmounted() {
		eventBus.off('composer-reply')
	},
	methods: {
		prefillMessageWithMention(account) {
			if (!this.statusIsEmpty || this.$refs.composerInput === undefined) {
				return
			}

			let handle = account.acct

			if (!handle.includes('@')) {
				handle += `@${this.hostname}`
			}

			this.$refs.composerInput.innerHTML
				= '<span class="mention" contenteditable="false">'
					+ `<a href="${account.url}" target="_blank">`
						+ `<img src="${account.avatar}"/>`
						+ `@${handle}`
					+ '</a>'
				+ '</span>&nbsp;'
			this.updateStatusContent()
		},
		updateStatusContent() {
			this.statusContent = this.$refs.composerInput.innerHTML
		},
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},
		async handleFileChange(event) {
			const target = event.target
			for (const file of Array.from(target.files)) {
				const url = URL.createObjectURL(file)
				this.attachments = {
					...this.attachments,
					[url]: {
						file,
						data: null,
					},
				}
				const mediaData = await this.$store.dispatch('createMedia', file)
				this.attachments = {
					...this.attachments,
					[url]: {
						...this.attachments[url],
						data: mediaData,
					},
				}
			}
		},
		insert(emoji) {
			console.debug('[Composer] insert emoji', emoji)
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
				this.createPost(event)
			}
		},
		updatePostFromTribute(event) {
			console.debug('[Composer] update from tribute', event)
			this.updateStatusContent()
		},
		async createPost(event) {
			const element = this.$refs.composerInput.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				const em = document.createTextNode(emoji.getAttribute('alt'))
				emoji.replaceWith(em)
			})

			const tempDiv = document.createElement('div')
			tempDiv.innerHTML = element.innerHTML
			tempDiv.querySelectorAll('div').forEach(d => d.after(document.createTextNode('\n')))
			let status = tempDiv.textContent.trim()
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

			try {
				this.loading = true
				await this.$store.dispatch('post', statusData)
			} finally {
				this.loading = false
				this.replyTo = null
				this.$refs.composerInput.innerText = ''
				this.updateStatusContent()
				this.attachments = {}
				this.$store.dispatch('refreshTimeline')
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
		},
	},
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
	outline: none;

	&:focus {
		border-color: var(--color-primary-element);
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
	}

	li.highlight .account,
	li:hover .account {
		color: var(--color-primary-text) !important;
	}
}
</style>
