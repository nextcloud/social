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
		<form class="new-post-form">
			<div class="author currentUser">
				{{ currentUser.displayName }}
				<span class="social-id">{{ socialId }}</span>
			</div>
			<vue-tribute :options="tributeOptions">
				<div v-contenteditable contenteditable="true" ref="composerInput" class="message" placeholder="Share a thought…" @input="updateInput" v-model="post"></div>
			</vue-tribute>
			<input class="submit icon-confirm has-tooltip" type="submit" value=""
				   title="" data-original-title="Post">
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
					<button type="button" v-tooltip="'Insert emoji'">😄</button>
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

	.options button {
		width: 34px;
		height: 34px;
	}

	.emoji-invoker {
		background-size: 16px;
		background-position: center;
		background-repeat: no-repeat;
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
		box-shadow: 0 1px 4px rgba(#000, 0.13);
	}
	.tribute-container ul {
		margin: 0;
		margin-top: 2px;
		padding: 0;
		list-style: none;
		background: #fff;
		border-radius: 4px;
		border: 1px solid rgba(#000, 0.13);
		background-clip: padding-box;
		overflow: hidden;
	}
	.tribute-container li {
		color: #3f5efb;
		padding: 5px 10px;
		cursor: pointer;
		font-size: 14px;
	}
	.tribute-container li.highlight,
	.tribute-container li:hover {
		background: #3f5efb;
		color: #fff;
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

</style>
<script>

import { Avatar, PopoverMenu } from 'nextcloud-vue'
import EmojiPicker from 'vue-emoji-picker'
import VueTribute from 'vue-tribute'
import { VTooltip } from 'v-tooltip'
import contenteditableDirective from 'vue-contenteditable-directive'
import CurrentUserMixin from './../mixins/currentUserMixin'

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
					case 'private':
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
					action: () => { this.switchType('private') },
					icon: this.visibilityIconClass('private'),
					text: 'Private'
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
		}
	},
	data() {
		return {
			type: 'public',
			post: '',
			search: '',
			tributeOptions: {
				values: [
					{key: 'Phil Heartman', value: 'pheartman'},
					{key: 'Gordon Ramsey', value: 'gramsey'}
				]
			},
			menuOpened: false,

		}
	},
}

</script>
