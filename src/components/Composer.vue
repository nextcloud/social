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
			<avatar :user="currentUser.uid" :display-name="currentUser.displayName" :size="32" />
		</div>
		<form class="new-post-form" v-on:submit.prevent="createPost">
			<div class="author currentUser">
				{{ currentUser.displayName }}
				<span class="social-id">{{ socialId }}</span>
			</div>
			<vue-tribute :options="tributeOptions">
				<div v-contenteditable contenteditable="true" ref="composerInput" class="message" placeholder="Share a thought…" @input="updateInput" v-model="post"></div>
			</vue-tribute>
			<emoji-picker @emoji="insert" :search="search" class="emoji-picker-wrapper">
				<div slot="emoji-invoker" slot-scope="{ events }" v-on="events" v-tooltip="'Insert emoji'" class="emoji-invoker"></div>
				<div slot="emoji-picker" slot-scope="{ emojis, insert, display }" class="emoji-picker popovermenu">
					<div>
						<div>
							<input type="text" v-model="search">
						</div>
						<div>
							<div v-for="(emojiGroup, category) in emojis" :key="category">
								<h5>{{ category }}</h5>
								<div>
									<span class="emoji" v-for="(emoji, emojiName) in emojiGroup" :key="emojiName" @click="insert(emoji)" :title="emojiName" v-html="$twemoji.parse(emoji)"></span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</emoji-picker>

			<div class="options">
				<input class="submit primary" type="submit" :value="t('social', 'Post')" title="" data-original-title="Post" :disabled="post.length < 1" />
				<div>
					<button :class="currentVisibilityIconClass" @click="togglePopoverMenu" />
					<div class="popovermenu" :class="{open: menuOpened}">
						<PopoverMenu :menu="visibilityPopover" />
					</div>
				</div>
			</div>
		</form>
	</div>
</template>
<style scoped>
	.new-post {
		display: flex;
		flex-wrap: wrap;
		padding: 10px;
		background-color: var(--color-main-background);
		position: sticky;
		top: 47px;
		z-index: 100;
		margin-bottom: 10px;
	}
	.new-post-author {
		padding: 5px;
	}

	.new-post-form {
		flex-grow: 1;
		position: relative;
	}

	.message {
		width: 100%;
		padding-right: 44px;
		min-height: 70px;
	}

	.author .social-id {
		opacity: .5;
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
	}
	.emoji-picker-wrapper {
		position: absolute;
		right: 0;
		top: 22px;
	}
	.emoji-picker.popovermenu {
		display: block;
		padding: 5px;
		width: 200px;
		height: 200px;
	}
	.emoji-picker > div {
		overflow: hidden;
		overflow-y: scroll;
		height: 190px;
	}
	.emoji-picker input {
		width: 100%;
	}
	.emoji-picker .emoji img {
		margin: 3px;
		width: 16px;
	}
</style>
<style>
	/* Tribute-specific styles
	 * TODO: properly scope component css
	 */
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
import EmojiPicker from 'vue-emoji-picker'
import VueTribute from 'vue-tribute'
import { VTooltip } from 'v-tooltip'
import contenteditableDirective from 'vue-contenteditable-directive'
import CurrentUserMixin from './../mixins/currentUserMixin'
import axios from 'nextcloud-axios'

export default {
	name: 'Composer',
	components: {
		PopoverMenu,
		Avatar, EmojiPicker, VueTribute
	},
	directives: {
		tooltip: VTooltip,
		contenteditable: contenteditableDirective
	},
	mixins: [CurrentUserMixin],
	props: {

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
						return 'icon-link';
					case 'followers':
						return 'icon-contacts-dark';
					case 'direct':
						return 'icon-external';
					case 'unlisted':
						return 'icon-password';
				}
			}
		},
		visibilityPopover() {
			return [
				{
					action: () => { this.switchType('public') },
					icon: this.visibilityIconClass('public'),
					text: t('social', 'Public'),
					longtext: t('social', 'Post to public timelines')
				},
				{
					action: () => { this.switchType('direct') },
					icon: this.visibilityIconClass('direct'),
					text: t('social', 'Direct'),
					longtext: t('social', 'Post to mentioned users only')
				},
				{
					action: () => { this.switchType('followers') },
					icon: this.visibilityIconClass('followers'),
					text: t('social', 'Followers'),
					longtext: t('social', 'Post to followers only')
				},
				{
					action: () => { this.switchType('unlisted') },
					icon: this.visibilityIconClass('unlisted'),
					text: t('social', 'Unlisted'),
					longtext: t('social', 'Do not post to public timelines')
				}
			]
		},
		getCleanPost() {
			let element = this.$refs.composerInput.cloneNode(true);
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				var em = document.createTextNode(emoji.getAttribute('alt'));
				emoji.replaceWith(em);
			});
			console.log('Create new post: ' + element.innerText);
			return element.innerText
		}
	},
	methods: {
		insert(emoji) {
			this.post += this.$twemoji.parse(emoji);
			this.$refs.composerInput.innerHTML = this.post;
		},
		updateInput(event) {
			this.post = this.$refs.composerInput.innerHTML;
		},
		togglePopoverMenu() {
			this.menuOpened = !this.menuOpened
		},
		switchType(type) {
			this.type = type;
			this.menuOpened = false;
		},
		createPost(event) {
			this.$store.dispatch('post', {
				content: this.getCleanPost,
				type: this.type,
			}).then((response) => {
				this.post = ''
				this.$refs.composerInput.innerText = this.post
			});
		},
		remoteSearch(text) {
			return axios.get(OC.generateUrl('apps/social/api/v1/accounts/search?search=' + text))
		}
	},
	data() {
		return {
			type: 'public',
			post: '',
			search: '',
			tributeOptions: {
				lookup: function (item) {
					return item.key + item.value
				},
				menuItemTemplate: function (item) {
					return '<img src="' + item.original.avatar + '" /><div>'
						+ '<span class="displayName">' +item.original.key + '</span>'
						+ '<span class="account">' + item.original.value + '</span>'
						+ '</div>';
				},
				selectTemplate: function (item) {
					return '<span class="mention" contenteditable="false">' +
						'<a href="' + item.original.url + '" target="_blank"><img src="' + item.original.avatar + '" />@' + item.original.value + '</a></span>';
				},
				values: (text, cb) => {
					this.remoteSearch(text).then((result) => {
						var users = [];
						if (result.data.result.exact) {
							var user = result.data.result.exact;
							users.push({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: 'http://localhost:8000/index.php/avatar/admin/32?v=0', // TODO: use real avatar from server
							})
						}
						for (var i in result.data.result.accounts) {
							var user = result.data.result.accounts[i];
							users.push({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: 'http://localhost:8000/index.php/avatar/admin/32?v=0', // TODO: use real avatar from server
							})
						}
						cb(users);
					})
				}
			},
			menuOpened: false,

		}
	},
}

</script>
