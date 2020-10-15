<template>
	<article class="entry-content">
		<div v-if="item.actor_info" class="post-avatar">
			<avatar v-if="item.local && item.type!=='SocialAppNotification'" :size="44" :user="item.actor_info.preferredUsername"
				:display-name="item.actor_info.account" :disable-tooltip="true" :showUserStatus="false" />
			<avatar v-else :size="44" :url="avatarUrl"
				:disable-tooltip="true" />
		</div>
		<div class="post-content">
			<div class="post-header">
				<div class="post-author-wrapper">
					<router-link v-if="item.actor_info"
						:to="{ name: 'profile',
							params: { account: (item.local && item.type!=='SocialAppNotification') ? item.actor_info.preferredUsername : item.actor_info.account }
						}">
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
				<a :data-timestamp="timestamp" class="post-timestamp live-relative-timestamp" @click="getSinglePostTimeline">
					{{ relativeTimestamp }}
				</a>
			</div>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div v-if="item.content" class="post-message">
				<MessageContent :source="source" />
			</div>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div v-else class="post-message" v-html="item.actor_info.summary" />
			<div v-if="hasAttachments" class="post-attachments">
				<post-attachment :attachments="item.attachment" />
			</div>
			<div v-if="this.$route.params.type!=='notifications' && !serverData.public" v-click-outside="hidePopoverMenu" class="post-actions">
				<a v-tooltip.bottom="t('social', 'Reply')" class="icon-reply" @click.prevent="reply" tabindex="0" />
				<a v-if="item.actor_info.account !== cloudId" v-tooltip.bottom="t('social', 'Boost')"
					:class="(isBoosted) ? 'icon-boosted' : 'icon-boost'"
					@click.prevent="boost" />
				<a v-tooltip.bottom="t('social', 'Like')" :class="(isLiked) ? 'icon-starred' : 'icon-favorite'" @click.prevent="like" tabindex="0" />
				<div v-if="popoverMenu.length > 0" v-tooltip.bottom="menuOpened ? '' : t('social', 'More actions')" class="post-actions-more">
					<a class="icon-more" @click.prevent="togglePopoverMenu" tabindex="0" />
					<div :class="{open: menuOpened}" class="popovermenu menu-center">
						<popover-menu :menu="popoverMenu" />
					</div>
				</div>
			</div>
		</div>
	</article>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import * as linkify from 'linkifyjs'
import pluginMention from 'linkifyjs/plugins/mention'
import 'linkifyjs/string'
import popoverMenu from './../mixins/popoverMenu'
import currentUser from './../mixins/currentUserMixin'
import PostAttachment from './PostAttachment.vue'
import Logger from '../logger'
import MessageContent from './MessageContent'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'

pluginMention(linkify)

export default {
	name: 'TimelinePost',
	components: {
		Avatar,
		PostAttachment,
		MessageContent
	},
	mixins: [popoverMenu, currentUser],
	props: {
		item: { type: Object, default: () => {} },
		parentAnnounce: { type: Object, default: () => {} }
	},
	data() {
		return {
		}
	},
	computed: {
		popoverMenu() {
			var actions = [
			]
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
			return moment(this.item.published).fromNow()
		},
		timestamp() {
			return Date.parse(this.item.published)
		},
		source() {
			if (!this.item.source && this.item.content) {
				// local posts don't have a source json
				return {
					content: this.item.content,
					tag: []
				}
			}
			return JSON.parse(this.item.source)
		},
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
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
		/**
     * @function getSinglePostTimeline
     * @description Opens the timeline of the post clicked
     */
		getSinglePostTimeline(e) {
			// Display internal or external post
			if (!this.item.local) {
				if (this.item.type === 'Note') {
					window.open(this.item.id)
				} else if (this.item.type === 'Announce') {
					window.open(this.item.object)
				} else {
					Logger.warn("Don't know what to do with posts of type " + this.item.type, { post: this.item })
				}
			} else {
				this.$router.push({ name: 'single-post',
					params: {
						account: this.item.actor_info.preferredUsername,
						id: this.item.id,
						localId: this.item.id.split('/')[this.item.id.split('/').length - 1],
						type: 'single-post'
					}
				})
			}
		},
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},
		reply() {
			this.$store.commit('setComposerDisplayStatus', true)
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
.entry-content {
	display: flex;

	.post-avatar {
		margin: 2px;
		margin-right: 12px;
		width: 44px;
		height: 44px;
		flex-shrink: 0;
	}

	.post-content {
		flex-grow: 1;

		.post-header {
			display: flex;
			flex-direction: row;
			justify-content: space-between;

			.post-author-wrapper a {
				padding: 13px 8px;
				margin-left: -8px;

				.post-author {
					font-weight: bold;
				}

				.post-author-id {
					color: var(--color-text-maxcontrast);
				}
			}

			.post-timestamp {
				color: var(--color-text-maxcontrast);
				max-width: 120px;
				text-align: right;
				flex-shrink: 0;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}
		}

		.post-actions {
			margin-left: -13px;
			height: 44px;

			.post-actions-more {
				position: relative;
				width: 44px;
				height: 44px;
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
				height: 44px;
				border-radius: var(--border-radius-pill);
				opacity: .5;

				&:hover,
				&:focus {
					opacity: 1;
					background-color:var(--color-main-background);
				}

				&:focus {
					// Needs to be different because keyboard doesn’t trigger the whole entry background change
					background-color:var(--color-background-hover);
				}
			}

			.icon-boosted,
			.icon-starred {
				opacity: 1;
			}
		}
	}
}
</style>

<style>
.post-message a {
	text-decoration: underline;
	overflow-wrap: anywhere;
}
</style>
