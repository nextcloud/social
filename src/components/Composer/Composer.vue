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
			@change="handleFileChange($event)"
			multiple
			type="file"
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually">
		<div class="new-post-author">
			<avatar :user="currentUser.uid" :display-name="currentUser.displayName" :disable-tooltip="true"
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
			<p>
				<span>{{ t('social', 'In reply to') }}</span>
				<actor-avatar :actor="replyTo.actor_info" :size="16" />
				<strong>{{ replyTo.actor_info.account }}</strong>
				<a class="icon-close" @click="closeReply()" />
			</p>
			<div class="reply-to-preview">
				{{ replyTo.content }}
			</div>
		</div>
		<form class="new-post-form" @submit.prevent="createPost">
			<vue-tribute :options="tributeOptions">
				<!-- eslint-disable-next-line vue/valid-v-model -->
				<div ref="composerInput" v-contenteditable:post.dangerousHTML="canType && !loading" class="message"
					placeholder="What would you like to share?" :class="{'icon-loading': loading}" @keyup.prevent.enter="keyup"
					@tribute-replaced="updatePostFromTribute" />
			</vue-tribute>

			<PreviewGrid :uploading="false" :uploadProgress="0.4" :miniatures="previewUrls" />

			<div class="options">
				<Button type="tertiary"
					@click.prevent="clickImportInput"
					:aria-label="t('social', 'Add attachment')"
					v-tooltip="t('social', 'Add attachment')">
					<template #icon>
						<FileUpload :size="22" decorative title="" />
					</template>
				</Button>

				<div class="new-post-form__emoji-picker">
					<EmojiPicker ref="emojiPicker" :search="search" :close-on-select="false"
						:container="container"
						@select="insert">
						<Button type="tertiary"
							:aria-haspopup="true"
							:aria-label="t('social', 'Add emoji')"
							v-tooltip="t('social', 'Add emoji')">
							<template #icon>
								<EmoticonOutline :size="22" decorative title="" />
							</template>
						</Button>
					</EmojiPicker>
				</div>

				<div v-click-outside="hidePopoverMenu" class="popovermenu-parent">
					<Button type="tertiary"
					:class="currentVisibilityIconClass"
					@click.prevent="togglePopoverMenu"
					v-tooltip="t('social', 'Visibility')" />
					<div :class="{open: menuOpened}" class="popovermenu">
						<popover-menu :menu="visibilityPopover" />
					</div>
				</div>

				<div class="emptySpace" />
				<Button :value="currentVisibilityPostLabel" :disabled="!canPost" type="primary"
					@click.prevent="createPost">
					<template #icon>
						<Send title="" :size="22" decorative />
					</template>
					<template>{{ postTo }}</template>
				</button>
			</div>
		</form>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline'
import Send from 'vue-material-design-icons/Send'
import FileUpload from 'vue-material-design-icons/FileUpload'
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import Button from '@nextcloud/vue/dist/Components/Button'
import PopoverMenu from '@nextcloud/vue/dist/Components/PopoverMenu'
import EmojiPicker from '@nextcloud/vue/dist/Components/EmojiPicker'
import VueTribute from 'vue-tribute'
import he from 'he'
import CurrentUserMixin from '../../mixins/currentUserMixin'
import FocusOnCreate from '../../directives/focusOnCreate'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import PreviewGrid from './PreviewGrid'

export default {
	name: 'Composer',
	components: {
		PopoverMenu,
		Avatar,
		FileUpload,
		ActorAvatar,
		EmojiPicker,
		VueTribute,
		EmoticonOutline,
		Button,
		Send,
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
			miniatures: [],		// miniatures of images stored in postAttachments
			postAttachments: [],	// The toot's attachments
			previewUrls: [],
			canType: true,
			search: '',
			replyTo: null,
			tributeOptions: {
				spaceSelectsMatch: true,
				collection: [
					{
						trigger: '@',
						lookup: function(item) {
							return item.key + item.value
						},
						menuItemTemplate: function(item) {
							return '<img src="' + item.original.avatar + '" /><div>'
								+ '<span class="displayName">' + item.original.key + '</span>'
								+ '<span class="account">' + item.original.value + '</span>'
								+ '</div>'
						},
						selectTemplate: function(item) {
							return '<span class="mention" contenteditable="false">'
								+ '<a href="' + item.original.url + '" target="_blank"><img src="' + item.original.avatar + '" />@' + item.original.value + '</a></span>'
						},
						values: (text, cb) => {
							let users = []

							if (text.length < 1) {
								cb(users)
							}
							this.remoteSearchAccounts(text).then((result) => {
								for (var i in result.data.result.accounts) {
									let user = result.data.result.accounts[i]
									users.push({
										key: user.preferredUsername,
										value: user.account,
										url: user.url,
										avatar: user.local ? generateUrl(`/avatar/${user.preferredUsername}/32`) : generateUrl(`apps/social/api/v1/global/actor/avatar?id=${user.id}`)
									})
								}
								cb(users)
							})
						}
					},
					{
						trigger: '#',
						menuItemTemplate: function(item) {
							return item.original.value
						},
						selectTemplate: function(item) {
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
							let tags = []

							if (text.length < 1) {
								cb(tags)
							}
							this.remoteSearchHashtags(text).then((result) => {
								if (result.data.result.exact) {
									tags.push({
										key: result.data.result.exact,
										value: result.data.result.exact
									})
								}
								for (var i in result.data.result.tags) {
									let tag = result.data.result.tags[i]
									tags.push({
										key: tag.hashtag,
										value: tag.hashtag
									})
								}
								cb(tags)
							})
						}
					}
				],
				noMatchTemplate() {
					if (this.current.collection.trigger === '#') {
						if (this.current.mentionText === '') {
							return undefined
						} else {
							return '<li data-index="0">#' + this.current.mentionText + '</li>'
						}
					}
				}
			},
			menuOpened: false

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
					longtext: t('social', 'Post to public timelines')
				},
				{
					action: () => {
						this.switchType('unlisted')
					},
					icon: this.visibilityIconClass('unlisted'),
					active: this.activeState('unlisted'),
					text: t('social', 'Unlisted'),
					longtext: t('social', 'Do not post to public timelines')
				},
				{
					action: () => {
						this.switchType('followers')
					},
					icon: this.visibilityIconClass('followers'),
					active: this.activeState('followers'),
					text: t('social', 'Followers'),
					longtext: t('social', 'Post to followers only')
				},
				{
					action: () => {
						this.switchType('direct')
					},
					icon: this.visibilityIconClass('direct'),
					active: this.activeState('direct'),
					text: t('social', 'Direct'),
					longtext: t('social', 'Post to mentioned users only')
				}
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
				return true;
			}
			return this.post.length !== 0 && this.post !== '<br>'
		}
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
			const formData = new FormData()
			formData.append('file', event.target.files[0])
			this.$store.dispatch('uploadAttachement', formData)
		},
		removeAttachment(idx) {
			this.previewUrls.splice(idx, 1)
		},
		insert(emoji) {
			if (typeof emoji === 'object') {
				let category = Object.keys(emoji)[0]
				let emojis = emoji[category]
				let firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}
			this.post += this.$twemoji.parse(emoji) + ' '
			this.$refs.composerInput.innerHTML += this.$twemoji.parse(emoji) + ' '
			this.$refs.emojiPicker.hide()
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
			let element = this.$refs.composerInput.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				var em = document.createTextNode(emoji.getAttribute('alt'))
				emoji.replaceWith(em)
			})

			let contentHtml = element.innerHTML

			// Extract mentions from content and create an array out of them
			let to = []
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
			let hashtags = []
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

			let data = {
				content: content,
				to: to,
				hashtags: hashtags,
				type: this.type,
				attachments: this.previewUrls.map(preview => preview.result), // TODO send the summary and other props too
			}

			if (this.replyTo) {
				data.replyTo = this.replyTo.id
			}

			return data
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
		createPost: async function(event) {

			let postData = this.getPostData()

			// Trick to validate last mention when the user directly clicks on the "post" button without validating it.
			let regex = /@([-\w]+)$/
			let lastMention = postData.content.match(regex)
			if (lastMention) {

				// Ask the server for matching accounts, and wait for the results
				let result = await this.remoteSearchAccounts(lastMention[1])

				// Validate the last mention only when it matches a single account
				if (result.data.result.accounts.length === 1) {
					postData.content = postData.content.replace(regex, '@' + result.data.result.accounts[0].account)
					postData.to.push(result.data.result.accounts[0].account)
				}
			}

			// Abort if the post is a direct message and no valid mentions were found
			// if (this.type === 'direct' && postData.to.length === 0) {
			// 	OC.Notification.showTemporary(t('social', 'Error while trying to post your message: Could not find any valid recipients.'), { type: 'error' })
			// 	return
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
		}
	}
}

</script>

<style scoped lang="scss">
	.new-post {
		padding: 10px;
		background-color: var(--color-main-background);
		position: sticky;
		top: 47px;
		z-index: 100;
		margin-bottom: 10px;

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
		background-position: 5px 5px;
		background-repeat: no-repeat;
		margin-left: 39px;
		margin-bottom: 20px;
		overflow: hidden;
		background-color: #fafafa;
		border-radius: 3px;
		padding: 5px;
		padding-left: 30px;

		.icon-close {
			display: inline-block;
			float: right;
			opacity: .7;
			padding: 3px;
		}
	}

	.message {
		width: 100%;
		padding-right: 44px;
		min-height: 70px;
		min-width: 2px;
		display: block;
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
		margin-bottom: 1rem;
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
</style>
<style>
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
	}

	.tribute-container ul {
		margin: 0;
		margin-top: 2px;
		padding: 0;
		list-style: none;
		background: var(--color-main-background);
		border-radius: 4px;
		background-clip: padding-box;
		overflow: hidden;
	}

	.tribute-container li {
		color: var(--color-text);
		padding: 5px 10px;
		cursor: pointer;
		font-size: 14px;
		display: flex;
	}

	.tribute-container li span {
		display: block;
	}

	.tribute-container li.highlight,
	.tribute-container li:hover {
		background: var(--color-primary);
		color: var(--color-primary-text);
	}

	.tribute-container li img {
		width: 32px;
		height: 32px;
		border-radius: 50%;
		overflow: hidden;
		margin-right: 10px;
		margin-left: -3px;
		margin-top: 3px;
	}

	.tribute-container li span {
		font-weight: bold;
	}

	.tribute-container li.no-match {
		cursor: default;
	}

	.tribute-container .menu-highlighted {
		font-weight: bold;
	}

	.tribute-container .account,
	.tribute-container li.highlight .account,
	.tribute-container li:hover .account {
		font-weight: normal;
		color: var(--color-text-light);
		opacity: 0.5;
	}

	.tribute-container li.highlight .account,
	.tribute-container li:hover .account {
		color: var(--color-primary-text) !important;
		opacity: .6;
	}

	.message .mention {
		color: var(--color-primary-element);
		background-color: var(--color-background-dark);
		border-radius: 5px;
		padding-top: 1px;
		padding-left: 2px;
		padding-bottom: 1px;
		padding-right: 5px;
	}

	.mention img {
		width: 16px;
		border-radius: 50%;
		overflow: hidden;
		margin-right: 3px;
		vertical-align: middle;
		margin-top: -1px;
	}

	.hashtag {
		text-decoration: underline;
	}
</style>
