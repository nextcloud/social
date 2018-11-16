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
			<input class="submit icon-confirm has-tooltip" type="submit" value=""
				   title="" data-original-title="Post" :disabled="post.length < 1">
			<div class="submitLoading icon-loading-small hidden" />
		</form>

		<div class="options">
			<div>
				<button :class="currentVisibilityIconClass" @click="togglePopoverMenu" />
				<div class="popovermenu" :class="{open: menuOpened}">
					<PopoverMenu :menu="visibilityPopover" />
				</div>
			</div>
			<emoji-picker @emoji="insert" :search="search">
				<div slot="emoji-invoker" slot-scope="{ events }" v-on="events">
					<button class="emoji-invoker" type="button" v-tooltip="'Insert emoji'">😄</button>
				</div>
				<div slot="emoji-picker" slot-scope="{ emojis, insert, display }" class="emoji-picker popovermenu">
					<div>
						<div>
							<input type="text" v-model="search">
						</div>
						<div>
							<div v-for="(emojiGroup, category) in emojis" :key="category">
								<h5>{{ category }}</h5>
								<div>
							<span class="emoji" v-for="(emoji, emojiName) in emojiGroup" :key="emojiName" @click="insert(emoji)" :title="emojiName">{{ emoji }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</emoji-picker>
		</div>
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
	}

	.author .social-id {
		opacity: .5;
	}

	[contenteditable=true]:empty:before{
		content: attr(placeholder);
		display: block; /* For Firefox */
		opacity: .5;
	}
	input[type=submit] {
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
		text-indent: -3px;
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
	.emoji-picker .emoji {
		padding: 3px;
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
	}
	.tribute-container li.highlight,
	.tribute-container li:hover {
		background: var(--color-primary);
		color: var(--color-primary-text);
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

	.tribute-container .account {
		font-weight: normal;
		color: var(--color-text-light);
	}
	.tribute-container li.highlight .account,
	.tribute-container li:hover .account {
		color: var(--color-primary-text) !important;
		opacity: .9;
	}
	.message .mention {
		color: var(--color-primary-element);
		background-color: var(--color-background-dark);
		padding: 3px;
		border-radius: 3px;
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
					text: 'Public'
				},
				{
					action: () => { this.switchType('direct') },
					icon: this.visibilityIconClass('direct'),
					text: 'Direct'
				},
				{
					action: () => { this.switchType('followers') },
					icon: this.visibilityIconClass('followers'),
					text: 'Followers'
				},
				{
					action: () => { this.switchType('unlisted') },
					icon: this.visibilityIconClass('unlisted'),
					text: 'Unlisted'
				}
			]
		}
	},
	methods: {
		insert(emoji) {
			this.post += emoji;
			this.$refs.composerInput.innerText = this.post;
		},
		updateInput(event) {
			this.post = this.$refs.composerInput.innerText;
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
				content: this.post,
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
					return item.original.key + '' + '<div class="account">' + item.original.value + '</div>'
				},
				selectTemplate: function (item) {
					return '<span class="mention" contenteditable="false">' +
						'<a href="' + item.original.url + '" target="_blank">@' + item.original.value + '</a></span>';
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
							})
						}
						for (var i in result.data.result.accounts) {
							var user = result.data.result.accounts[i];
							users.push({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
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
