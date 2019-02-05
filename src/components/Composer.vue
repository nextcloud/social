<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
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
		<form class="new-post-form" @submit.prevent="createPost">
			<vue-tribute :options="tributeOptions">
				<!-- eslint-disable-next-line vue/valid-v-model -->
				<div ref="composerInput" v-contenteditable:post.dangerousHTML="canType" class="message"
					placeholder="What would you like to share?" @keyup.enter="keyup" />
			</vue-tribute>
			<emoji-picker ref="emojiPicker" :search="search" class="emoji-picker-wrapper"
				@emoji="insert">
				<a slot="emoji-invoker" v-tooltip="'Insert emoji'" slot-scope="{ events }"
					class="emoji-invoker" tabindex="0" v-on="events"
					@keyup.enter="events.click" @keyup.space="events.click" />
				<!-- eslint-disable-next-line vue/no-template-shadow -->
				<div slot="emoji-picker" slot-scope="{ emojis, insert }" class="emoji-picker popovermenu">
					<div>
						<div>
							<input v-model="search" v-focus-on-create type="text"
								@keyup.enter="insert(emojis)">
						</div>
						<div>
							<div v-for="(emojiGroup, category) in emojis" :key="category">
								<h5>{{ category }}</h5>
								<div>
									<!-- eslint-disable vue/no-v-html -->
									<span v-for="(emoji, emojiName) in emojiGroup" :key="emojiName" :title="emojiName"
										tabindex="0"
										class="emoji" @click="insert(emoji)" @keyup.enter="insert(emoji)"
										@keyup.space="insert(emoji)" v-html="$twemoji.parse(emoji)" />
								</div>
							</div>
						</div>
					</div>
				</div>
			</emoji-picker>

			<div class="options">
				<input :value="currentVisibilityPostLabel" :disabled="post.length < 1" class="submit primary"
					type="submit" title="" data-original-title="Post">
				<div v-click-outside="hidePopoverMenu">
					<button :class="currentVisibilityIconClass" @click.prevent="togglePopoverMenu" />
					<div :class="{open: menuOpened}" class="popovermenu menu-center">
						<popover-menu :menu="visibilityPopover" />
					</div>
				</div>
			</div>
		</form>
	</div>
</template>
<style scoped lang="scss">
	.new-post {
		padding: 10px;
		background-color: var(--color-main-background);
		position: sticky;
		top: 47px;
		z-index: 100;
		margin-bottom: 10px;
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

	.new-post-form {
		flex-grow: 1;
		position: relative;
		top: -10px;
		margin-left: 39px;
	}

	.message {
		width: 100%;
		padding-right: 44px;
		min-height: 70px;
		min-width: 2px;
		display: block;
	}

	[contenteditable=true]:empty:before{
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
		flex-direction: row-reverse;
	}

	.options > div {
		position: relative;
	}

	.options button {
		width: 34px;
		height: 34px;
	}

	.emoji-invoker {
		background-image: url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2OCIgaGVpZ2h0PSI2OCI+PHBhdGggZD0iTTM0IDBDMTUuMyAwIDAgMTUuMyAwIDM0czE1LjMgMzQgMzQgMzQgMzQtMTUuMyAzNC0zNFM1Mi43IDAgMzQgMHptMCA2NEMxNy41IDY0IDQgNTAuNSA0IDM0UzE3LjUgNCAzNCA0czMwIDEzLjUgMzAgMzAtMTMuNSAzMC0zMCAzMHoiLz48cGF0aCBkPSJNNDQuNiA0NC42Yy01LjggNS44LTE1LjQgNS44LTIxLjIgMC0uOC0uOC0yLS44LTIuOCAwLS44LjgtLjggMiAwIDIuOEMyNC4zIDUxLjEgMjkuMSA1MyAzNCA1M3M5LjctMS45IDEzLjQtNS42Yy44LS44LjgtMiAwLTIuOC0uOC0uOC0yLS44LTIuOCAweiIvPjxjaXJjbGUgcj0iNSIgY3k9IjI2IiBjeD0iMjQiLz48Y2lyY2xlIHI9IjUiIGN5PSIyNiIgY3g9IjQ0Ii8+PC9zdmc+);
		background-position: center center;
		background-repeat: no-repeat;
		width: 38px;
		opacity: 0.5;
		background-size: 16px 16px;
		height: 38px;
		cursor: pointer;
		display: block;
	}
	.emoji-invoker:focus,
	.emoji-invoker:hover {
		opacity: 1;
	}
	.emoji-picker-wrapper {
		position: absolute;
		right: 0;
		top: 0;
	}
	.emoji-picker.popovermenu {
		display: block;
		padding: 5px;
		width: 200px;
		height: 200px;
		top: 44px;
	}
	.emoji-picker > div {
		overflow: hidden;
		overflow-y: scroll;
		height: 190px;
	}
	.emoji-picker input {
		width: 100%;
	}
	.emoji-picker span.emoji {
		padding: 3px;
	}
	.emoji-picker span.emoji:focus {
		background-color: var(--color-background-dark);
	}
	.emoji-picker .emoji img {
		width: 16px;
	}
	.popovermenu {
		top: 55px;
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
	img.emoji {
		margin: 3px;
		width: 16px;
		vertical-align: text-bottom;
	}
</style>
<script>

import { Avatar, PopoverMenu } from 'nextcloud-vue'
import ClickOutside from 'vue-click-outside'
import EmojiPicker from 'vue-emoji-picker'
import VueTribute from 'vue-tribute'
import { VTooltip } from 'v-tooltip'
import CurrentUserMixin from './../mixins/currentUserMixin'
import FocusOnCreate from '../directives/focusOnCreate'
import axios from 'nextcloud-axios'

export default {
	name: 'Composer',
	components: {
		PopoverMenu,
		Avatar,
		EmojiPicker,
		VueTribute
	},
	directives: {
		tooltip: VTooltip,
		ClickOutside: ClickOutside,
		FocusOnCreate: FocusOnCreate
	},
	mixins: [CurrentUserMixin],
	props: {

	},
	data() {
		return {
			type: localStorage.getItem('social.lastPostType') || 'followers',
			post: '',
			canType: true,
			search: '',
			tributeOptions: {
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
					this.remoteSearch(text).then((result) => {
						if (result.data.result.exact) {
							let user = result.data.result.exact
							users.push({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: user.local ? OC.generateUrl(`/avatar/${user.preferredUsername}/32`) : ''// TODO: use real avatar from server
							})
						}
						for (var i in result.data.result.accounts) {
							let user = result.data.result.accounts[i]
							users.push({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: user.local ? OC.generateUrl(`/avatar/${user.preferredUsername}/32`) : OC.generateUrl(`apps/social/api/v1/global/actor/avatar?id=${user.id}`)
							})
						}
						cb(users)
					})
				}
			},
			menuOpened: false

		}
	},
	computed: {
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
					action: () => { this.switchType('direct') },
					icon: this.visibilityIconClass('direct'),
					active: this.activeState('direct'),
					text: t('social', 'Direct'),
					longtext: t('social', 'Post to mentioned users only')
				},
				{
					action: () => { this.switchType('unlisted') },
					icon: this.visibilityIconClass('unlisted'),
					active: this.activeState('unlisted'),
					text: t('social', 'Unlisted'),
					longtext: t('social', 'Do not post to public timelines')
				},
				{
					action: () => { this.switchType('followers') },
					icon: this.visibilityIconClass('followers'),
					active: this.activeState('followers'),
					text: t('social', 'Followers'),
					longtext: t('social', 'Post to followers only')
				},
				{
					action: () => { this.switchType('public') },
					icon: this.visibilityIconClass('public'),
					active: this.activeState('public'),
					text: t('social', 'Public'),
					longtext: t('social', 'Post to public timelines')
				}
			]
		}
	},
	methods: {
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
			let content = element.innerText.trim()
			let to = []
			let hashtags = []
			const mentionRegex = /@(([\w-_.]+)(@[\w-.]+)?)/g
			let match = null
			do {
				match = mentionRegex.exec(content)
				if (match) {
					to.push(match[1])
				}
			} while (match)

			const hashtagRegex = /#([\w-_.]+)/g
			match = null
			do {
				match = hashtagRegex.exec(content)
				if (match) {
					hashtags.push(match[1])
				}
			} while (match)

			return {
				content: content,
				to: to,
				hashtags: hashtags,
				type: this.type
			}
		},
		keyup(event) {
			if (event.shiftKey) {
				this.createPost(event)
			}
		},
		createPost(event) {
			this.$store.dispatch('post', this.getPostData()).then((response) => {
				this.post = ''
				this.$refs.composerInput.innerText = this.post
				this.$store.dispatch('refreshTimeline')
			})
		},
		remoteSearch(text) {
			return axios.get(OC.generateUrl('apps/social/api/v1/global/accounts/search?search=' + text))
		}
	}
}

</script>
