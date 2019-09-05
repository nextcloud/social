<template>
	<div class="entry-content">
		<div v-if="item.actor_info" class="post-avatar">
			<avatar v-if="item.local" :size="32" :user="item.actor_info.preferredUsername"
				:display-name="item.actor_info.account" :disable-tooltip="true" />
			<avatar v-else :size="32" :url="avatarUrl"
				:disable-tooltip="true" />
		</div>
		<div class="post-content">
			<div class="post-header">
				<div class="post-author-wrapper">
					<router-link v-if="item.actor_info" :to="{ name: 'profile', params: { account: item.local ? item.actor_info.preferredUsername : item.actor_info.account }}">
						<span class="post-author">
							{{ userDisplayName(item.actor_info) }}
						</span>
						<span class="post-author-id">
							@{{ item.actor_info.account }}
						</span>
					</router-link>
					<a v-else :href="item.attributedTo">
						<span class="post-author-id">
							{{ item.attributedTo }}
						</span>
					</a>
				</div>
				<div :data-timestamp="timestamp" class="post-timestamp live-relative-timestamp">
					{{ relativeTimestamp }}
				</div>
			</div>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div class="post-message" v-html="formatedMessage" />
			<div v-if="hasAttachments" class="post-attachments">
				<post-attachment :attachments="item.attachment" />
			</div>
			<div v-click-outside="hidePopoverMenu" class="post-actions">
				<a v-tooltip.bottom="t('social', 'Reply')" class="icon-reply" @click.prevent="reply" />
				<a v-if="item.actor_info.account !== cloudId" v-tooltip.bottom="t('social', 'Boost')"
					:class="(isBoosted) ? 'icon-boosted' : 'icon-boost'"
					@click.prevent="boost" />
				<a v-tooltip.bottom="t('social', 'Like')" :class="(isLiked) ? 'icon-starred' : 'icon-favorite'" @click.prevent="like" />
				<div v-if="popoverMenu.length > 0" v-tooltip.bottom="menuOpened ? '' : t('social', 'More actions')" class="post-actions-more">
					<a class="icon-more" @click.prevent="togglePopoverMenu" />
					<div :class="{open: menuOpened}" class="popovermenu menu-center">
						<popover-menu :menu="popoverMenu" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import Avatar from 'nextcloud-vue/dist/Components/Avatar'
import popoverMenu from './../mixins/popoverMenu'
import currentUser from './../mixins/currentUserMixin'
import PostAttachment from './PostAttachment'
import linkifyHtml from 'linkifyjs/html'

export default {
	name: 'TimelinePost',
	components: {
		Avatar,
		PostAttachment
	},
	mixins: [popoverMenu, currentUser],
	props: {
		item: {
			type: Object,
			default: () => {
			}
		},
		parentAnnounce: {
			type: Object,
			default: () => {
			}
		}
	},
	data() {
		return {}
	},
	computed: {
		popoverMenu() {
			var actions = []
			if (this.item.actor_info.account === this.cloudId) {
				actions.push(
					{
						action: () => {
							this.$store.dispatch('postDelete', this.item)
							this.hidePopoverMenu()
						},
						icon: 'icon-delete',
						text: t('social', 'Delete post')
					}
				)
			}
			return actions
		},
		relativeTimestamp() {
			return OC.Util.relativeModifiedDate(this.item.published)
		},
		timestamp() {
			return Date.parse(this.item.published)
		},
		formatedMessage() {
			let message = this.item.content

			if (typeof message === 'undefined') {
				return ''
			}

			// Render newlines
			message = message.replace(/(?:\r\n|\r|\n)/g, '<br />')

			// Create links for mentions
			if (typeof this.item.source !== 'undefined') {
				let source = JSON.parse(this.item.source)
				if (typeof source.tag !== 'undefined') {
					source.tag.forEach(tag => {
						if (tag.type === 'Mention') {
							let shortname = tag.name.match(/^@[^@]+/)
							let patt = new RegExp(shortname[0], 'g')
							message = message.replace(patt, function(matched) {
								var a = '<a href="' + OC.generateUrl('/apps/social/' + tag.name) + '">' + matched + '</a>'
								return a
							})
						}
					})
				}
			} else if (this.item.local === true) {
				// For local posts, we don't have a source attribute, so we use this slightly less robust method to create links for mentions
				// It is less robust because mentions not followed by a space will be identified with the text following them.
				// Example: In message "Hello @cyrille@nextcloud.bollu.be.How are u?", the identified mention will be "@cyrille@nextcloud.bollu.be.How"
				//          rather than "@cyrille@nextcloud.bollu.be"
				message = message.replace(/@[^@]+@\S+/g, function(matched) {
					var a = '<a href="' + OC.generateUrl('/apps/social/' + matched) + '">' + matched + '</a>'
					return a
				})
			}

			// Create links for hashtags
			// we cannot use the same method as for mentions as there may be locale issues
			if (this.item.hashtags !== undefined) {
				message = this.mangleHashtags(message)
			}

			// Render emoji's
			message = this.$twemoji.parse(message)

			// Create links for URL's
			// ignore <a> and <img> tags as they have been created (and properly formated) earlier in this function
			message = linkifyHtml(message, { ignoreTags: ['a', 'img'] })

			return message
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		},
		hasAttachments() {
			return (typeof this.item.attachment !== 'undefined')
		},
		isBoosted() {
			if (typeof this.item.action === 'undefined') {
				return false
			}
			return !!this.item.action.values.boosted
		},
		isLiked() {
			if (typeof this.item.action === 'undefined') {
				return false
			}
			return !!this.item.action.values.liked
		}
	},
	methods: {
		mangleHashtags(msg) {
			// Replace hashtag's href parameter with local ones
			this.item.hashtags.forEach(tag => {
				let patt = new RegExp('#' + tag, 'gi')
				msg = msg.replace(patt, function(matched) {
					var a = '<a href="' + OC.generateUrl('/apps/social/timeline/tags/' + matched.substring(1)) + '">' + matched + '</a>'
					return a
				})
			})
			return msg
		},
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},
		reply() {
			this.$root.$emit('composer-reply', this.item)
		},
		boost() {
			let params = {
				post: this.item,
				parentAnnounce: this.parentAnnounce
			}
			if (this.isBoosted) {
				this.$store.dispatch('postUnBoost', params)
			} else {
				this.$store.dispatch('postBoost', params)
			}
		},
		like() {
			let params = {
				post: this.item,
				parentAnnounce: this.parentAnnounce
			}
			if (this.isLiked) {
				this.$store.dispatch('postUnlike', params)
			} else {
				this.$store.dispatch('postLike', params)
			}
		}
	}
}
</script>
<style scoped lang="scss">
	.post-author {
		font-weight: bold;
	}

	.post-author-id {
		opacity: .7;
	}

	.post-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		overflow: hidden;
		width: 32px;
		height: 32px;
		min-width: 32px;
		flex-shrink: 0;
	}

	.post-timestamp {
		width: 120px;
		text-align: right;
		flex-shrink: 0;
	}

	.post-actions {
		margin-left: -13px;
		height: 44px;

		.post-actions-more {
			position: relative;
			width: 44px;
			height: 34px;
			display: inline-block;
		}

		.icon-reply,
		.icon-boost,
		.icon-boosted,
		.icon-starred,
		.icon-favorite,
		.icon-more {
			display: inline-block;
			width: 44px;
			height: 34px;
			opacity: .5;

			&:hover, &:focus {
				opacity: 1;
			}
		}

		.icon-boosted {
			opacity: 1;
		}
	}

	span {
		/* opacity: 0.5; */
	}

	.entry-content {
		display: flex;
	}

	.post-content {
		flex-grow: 1;
	}

	.post-header {
		display: flex;
		flex-direction: row;
		justify-content: space-between;
	}

	.post-timestamp {
		opacity: .7;
	}
</style>
<style>
	.post-message a {
		text-decoration: underline;
	}
</style>
