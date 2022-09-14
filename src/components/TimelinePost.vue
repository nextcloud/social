<template>
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
			<RichText :text="source"
				:autolink="true"
				:reference-limit="2"
				:arguments="richParameters" />
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else class="post-message" v-html="item.actor_info.summary" />
		<div v-if="hasAttachments" class="post-attachments">
			<post-attachment :attachments="item.attachment" />
		</div>
		<div v-if="this.$route && this.$route.params.type !== 'notifications' && !serverData.public" class="post-actions">
			<NcButton type="tertiary-no-background"
				v-tooltip="t('social', 'Reply')"
				@click="reply">
				<template #icon>
					<Reply :size="20" />
				</template>
			</NcButton>
			<NcButton type="tertiary-no-background"
				v-tooltip="t('social', 'Boost')"
				@click="boost">
				<template #icon>
					<Repeat :size="20" :fill-color="isBoosted ? 'blue' : 'var(--color-main-text)'" />
				</template>
			</NcButton>
			<NcButton v-if="!isLiked"
				type="tertiary-no-background"
				v-tooltip="t('social', 'Like')"
				@click="like">
				<template #icon>
					<HeartOutline :size="20" />
				</template>
			</NcButton>
			<NcButton v-if="isLiked"
				type="tertiary-no-background"
				v-tooltip="t('social', 'Undo Like')"
				@click="like">
				<template #icon>
					<Heart :size="20" :fill-color="'var(--color-error)'" />
				</template>
			</NcButton>
			<NcActions>
				<NcActionButton v-if="item.actor_info.account === cloudId"
					@click="remove()"
					icon="icon-delete">
					{{ t('social', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import * as linkify from 'linkifyjs'
import pluginMention from 'linkifyjs/plugins/mention'
import 'linkifyjs/string'
import currentUser from './../mixins/currentUserMixin'
import PostAttachment from './PostAttachment.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import Logger from '../logger'
import MessageContent from './MessageContent'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import RichText from '@nextcloud/vue-richtext'

pluginMention(linkify)

export default {
	name: 'TimelinePost',
	components: {
		PostAttachment,
		MessageContent,
		NcActions,
		NcActionButton,
		NcButton,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		RichText,
	},
	mixins: [currentUser],
	props: {
		item: { type: Object, default: () => {} },
		parentAnnounce: { type: Object, default: () => {} }
	},
	computed: {
		relativeTimestamp() {
			return moment(this.item.published).fromNow()
		},
		timestamp() {
			return Date.parse(this.item.published)
		},
		source() {
			if (!this.item.source && this.item.content) {
				// local posts don't have a source json
				console.debug(this.item.content)
				return this.item.content
			}
			console.debug(JSON.parse(this.item.source))
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
		},
		richParameters() {
			return {}
		},
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
		remove() {
			this.$store.dispatch('postDelete', this.item)
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
	@import '@nextcloud/vue-richtext/dist/style.css';
	.post-content {
		padding: 4px 4px 4px 8px;
		font-size: 15px;
		line-height: 1.6em;
		position: relative;
		width: 100%;

		::v-deep a.widget-default {
			text-decoration: none !important;
		}

		&:hover {
			border-radius: 8px;
			background-color: var(--color-background-hover);
		}
	}

	.post-author {
		font-weight: bold;
	}

	.post-author-id {
		opacity: .7;
	}

	.post-timestamp {
		width: 120px;
		text-align: right;
		flex-shrink: 0;
	}

	.post-actions {
		margin-left: -13px;
		height: 44px;
		display: flex;

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
		overflow-wrap: anywhere;
	}
</style>
