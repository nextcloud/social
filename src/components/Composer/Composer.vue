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
				<ActorAvatar :actor="replyTo.actor_info" :size="16" />
				<strong>{{ replyTo.actor_info.account }}</strong>
				<NcButton type="tertiary"
					class="close-button"
					:aria-label="t('social', 'Close reply')"
					@click="closeReply">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</p>
			<div class="reply-to-preview">
				{{ replyTo.content }}
			</div>
		</div>
		<form class="new-post-form" @submit.prevent="createPost">
			<VueTribute :options="tributeOptions">
				<!-- eslint-disable-next-line vue/valid-v-model -->
				<div ref="composerInput"
					v-contenteditable:post.dangerousHTML="canType && !loading"
					class="message"
					placeholder="What would you like to share?"
					:class="{'icon-loading': loading}"
					@keyup.prevent.enter="keyup"
					@tribute-replaced="updatePostFromTribute" />
			</VueTribute>

			<PreviewGrid :uploading="false"
				:upload-progress="0.4"
				:miniatures="previewUrls"
				@deleted="deletePreview" />

			<div class="options">
				<NcButton v-tooltip="t('social', 'Add attachment')"
					type="tertiary"
					:disabled="previewUrls.length >= 1"
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
						:container="container"
						@select="insert">
						<NcButton v-tooltip="t('social', 'Add emoji')"
							type="tertiary"
							:aria-haspopup="true"
							:aria-label="t('social', 'Add emoji')">
							<template #icon>
								<EmoticonOutline :size="22" decorative title="" />
							</template>
						</NcButton>
					</NcEmojiPicker>
				</div>

				<div v-click-outside="hidePopoverMenu" class="popovermenu-parent">
					<NcButton v-tooltip="t('social', 'Visibility')"
						type="tertiary"
						:class="currentVisibilityIconClass"
						@click.prevent="togglePopoverMenu" />
					<div :class="{open: menuOpened}" class="popovermenu">
						<NcPopoverMenu :menu="visibilityPopover" />
					</div>
				</div>

				<div class="emptySpace" />
				<NcButton :value="currentVisibilityPostLabel"
					:disabled="!canPost"
					native-type="submit"
					type="primary"
					@click.prevent="createPost">
					<template #icon>
						<Send title="" :size="22" decorative />
					</template>
					{{ postTo }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcPopoverMenu from '@nextcloud/vue/dist/Components/NcPopoverMenu.js'
import NcEmojiPicker from '@nextcloud/vue/dist/Components/NcEmojiPicker.js'
import VueTribute from 'vue-tribute'
import he from 'he'
import CurrentUserMixin from '../../mixins/currentUserMixin.js'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import PreviewGrid from './PreviewGrid.vue'

export default {
	name: 'Composer',
	components: {
		NcPopoverMenu,
		NcAvatar,
		NcEmojiPicker,
		NcButton,
		ActorAvatar,
		FileUpload,
		VueTribute,
		EmoticonOutline,
		Send,
		Close,
		PreviewGrid,
	},
	directives: {
		FocusOnCreate,
	},
	mixins: [CurrentUserMixin],
	props: {},
	data() {
		return {
			type: localStorage.getItem('social.lastPostType') || 'followers',
			loading: false,
			post: '',
			miniatures: [], // miniatures of images stored in postAttachments
			postAttachments: [], // The toot's attachments
			previewUrls: [],
			canType: true,
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
								+ '<a href="' + item.original.url + '" target="_blank"><img src="' + item.original.avatar + '" />@' + item.original.value + '</a></span>'
						},
						values: (text, cb) => {
							const users = []

							if (text.length < 1) {
								cb(users)
							}
							this.remoteSearchAccounts(text).then((result) => {
								for (const i in result.data.result.accounts) {
									const user = result.data.result.accounts[i]
									users.push({
										key: user.preferredUsername,
										value: user.account,
										url: user.url,
										avatar: user.local ? generateUrl(`/avatar/${user.preferredUsername}/32`) : generateUrl(`apps/social/api/v1/global/actor/avatar?id=${user.id}`),
									})
								}
								cb(users)
							})
						},
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
						values: (text, cb) => {
							const tags = []

							if (text.length < 1) {
								cb(tags)
							}
							this.remoteSearchHashtags(text).then((result) => {
								if (result.data.result.exact) {
									tags.push({
										key: result.data.result.exact,
										value: result.data.result.exact,
									})
								}
								for (const i in result.data.result.tags) {
									const tag = result.data.result.tags[i]
									tags.push({
										key: tag.hashtag,
										value: tag.hashtag,
									})
								}
								cb(tags)
							})
						},
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
			menuOpened: false,

		}
	},
	computed: {
		postTo() {
			switch (this.type) {
			case 'public':
			case 'unlisted':
				return t('social', 'Post')
			case 'followers':
				return t('social', 'Post to followers')
			case 'direct':
				return t('social', 'Post to mentioned users')
			}
			return ''
		},
		currentVisibilityIconClass() {
			return this.visibilityIconClass(this.type)
		},
		visibilityIconClass() {
			return (type) => {
				if (typeof type === 'undefined') {
					type = this.type
				}
				switch (type) {
				case 'public':
					return 'icon-link'
				case 'followers':
					return 'icon-contacts-dark'
				case 'direct':
					return 'icon-external'
				case 'unlisted':
					return 'icon-password'
				}
			}
		},
		currentVisibilityPostLabel() {
			return this.visibilityPostLabel(this.type)
		},
		visibilityPostLabel() {
			return (type) => {
				if (typeof type === 'undefined') {
					type = this.type
				}
				switch (type) {
				case 'public':
					return t('social', 'Post publicly')
				case 'followers':
					return t('social', 'Post to followers')
				case 'direct':
					return t('social', 'Post to recipients')
				case 'unlisted':
					return t('social', 'Post unlisted')
				}
			}
		},
		activeState() {
			return (type) => {
				if (type === this.type) {
					return true
				} else {
					return false
				}
			}
		},
		visibilityPopover() {
			return [
				{
					action: () => {
						this.switchType('public')
					},
					icon: this.visibilityIconClass('public'),
					active: this.activeState('public'),
					text: t('social', 'Public'),
					longtext: t('social', 'Post to public timelines'),
				},
				{
					action: () => {
						this.switchType('unlisted')
					},
					icon: this.visibilityIconClass('unlisted'),
					active: this.activeState('unlisted'),
					text: t('social', 'Unlisted'),
					longtext: t('social', 'Do not post to public timelines'),
				},
				{
					action: () => {
						this.switchType('followers')
					},
					icon: this.visibilityIconClass('followers'),
					active: this.activeState('followers'),
					text: t('social', 'Followers'),
					longtext: t('social', 'Post to followers only'),
				},
				{
					action: () => {
						this.switchType('direct')
					},
					icon: this.visibilityIconClass('direct'),
					active: this.activeState('direct'),
					text: t('social', 'Direct'),
					longtext: t('social', 'Post to mentioned users only'),
				},
			]
		},
		container() {
			return '#content-vue'
		},
		containerElement() {
			return document.querySelector(this.container)
		},
		canPost() {
			if (this.previewUrls.length > 0) {
				return true
			}
			return this.post.length !== 0 && this.post !== '<br>'
		},
	},
	mounted() {
		this.$root.$on('composer-reply', (data) => {
			this.replyTo = data
			this.type = 'direct'
		})
	},
	methods: {
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},
		handleFileChange(event) {
			event.target.files.forEach((file) => {
				this.previewUrls.push({
					description: '',
					url: URL.createObjectURL(file),
					result: file,
				})
			})
		},
		removeAttachment(idx) {
			this.previewUrls.splice(idx, 1)
		},
		insert(emoji) {
			if (typeof emoji === 'object') {
				const category = Object.keys(emoji)[0]
				const emojis = emoji[category]
				const firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}
			this.post += this.$twemoji.parse(emoji) + ' '
			this.$refs.composerInput.innerHTML += this.$twemoji.parse(emoji) + ' '
		},
		togglePopoverMenu() {
			this.menuOpened = !this.menuOpened
		},
		hidePopoverMenu() {
			this.menuOpened = false
		},
		switchType(type) {
			this.type = type
			this.menuOpened = false
			localStorage.setItem('social.lastPostType', type)
		},
		getPostData() {
			const element = this.$refs.composerInput.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				const em = document.createTextNode(emoji.getAttribute('alt'))
				emoji.replaceWith(em)
			})

			const contentHtml = element.innerHTML

			// Extract mentions from content and create an array out of them
			const to = []
			const mentionRegex = /<span class="mention"[^>]+><a[^>]+><img[^>]+>@([\w-_.]+@[\w-.]+)/g
			let match = null
			do {
				match = mentionRegex.exec(contentHtml)
				if (match) {
					to.push(match[1])
				}
			} while (match)

			// Add author of original post in case of reply
			if (this.replyTo !== null) {
				to.push(this.replyTo.actor_info.account)
			}

			// Extract hashtags from content and create an array ot of them
			const hashtagRegex = />#([^<]+)</g
			const hashtags = []
			match = null
			do {
				match = hashtagRegex.exec(contentHtml)
				if (match) {
					hashtags.push(match[1])
				}
			} while (match)

			// Remove all html tags but </div> (wich we turn in newlines) and decode the remaining html entities
			let content = contentHtml.replace(/<(?!\/div)[^>]+>/gi, '').replace(/<\/div>/gi, '\n').trim()
			content = he.decode(content)

			const formData = new FormData()
			formData.append('content', content)
			to.forEach(to => formData.append('to[]', to))
			hashtags.forEach(hashtag => formData.append('hashtags[]', hashtag))
			formData.append('type', this.type)
			this.previewUrls.forEach(preview => formData.append('attachments[]', preview.result))
			this.previewUrls.forEach(preview => formData.append('attachmentDescriptions[]', preview.description))

			if (this.replyTo) {
				formData.append('replyTo', this.replyTo.id)
			}

			return formData
		},
		keyup(event) {
			if (event.shiftKey || event.ctrlKey) {
				this.createPost(event)
			}
		},
		updatePostFromTribute(event) {
			// Trick to let vue-contenteditable know that tribute replaced a mention or hashtag
			this.$refs.composerInput.oninput(event)
		},
		async createPost(event) {

			const postData = this.getPostData()

			// Trick to validate last mention when the user directly clicks on the "post" button without validating it.
			const regex = /@([-\w]+)$/
			const lastMention = postData.get('content').match(regex)
			if (lastMention) {

				// Ask the server for matching accounts, and wait for the results
				const result = await this.remoteSearchAccounts(lastMention[1])

				// Validate the last mention only when it matches a single account
				if (result.data.result.accounts.length === 1) {
					postData.set('content', postData.get('content').replace(regex, '@' + result.data.result.accounts[0].account))
					postData.set('to', postData.get('to').push(result.data.result.accounts[0].account))
				}
			}

			// Abort if the post is a direct message and no valid mentions were found
			// if (this.type === 'direct' && postData.get('to').length === 0) {
			// OC.Notification.showTemporary(t('social', 'Error while trying to post your message: Could not find any valid recipients.'), { type: 'error' })
			// return
			// }

			// Post message
			this.loading = true
			this.$store.dispatch('post', postData).then((response) => {
				this.loading = false
				this.replyTo = null
				this.post = ''
				this.$refs.composerInput.innerText = this.post
				this.previewUrls = []
				this.$store.dispatch('refreshTimeline')
			})

		},
		closeReply() {
			this.replyTo = null
			// View may want to hide the composer
			this.$store.commit('setComposerDisplayStatus', false)
		},
		remoteSearchAccounts(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/accounts/search?search=' + text))
		},
		remoteSearchHashtags(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/tags/search?search=' + text))
		},
		deletePreview(index) {
			this.previewUrls.splice(index, 1)
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

.popovermenu-parent {
	position: relative;
}
.popovermenu {
	top: 55px;
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
