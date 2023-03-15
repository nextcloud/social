<template>
	<div class="post-content">
		<div class="post-header">
			<div class="post-author-wrapper">
				<router-link v-if="item.account"
					:to="{ name: 'profile',
						params: { account: item.account.acct }
					}">
					<span class="post-author">
						{{ item.account.display_name }}
					</span>
					<span class="post-author-id">
						@{{ item.account.username }}
					</span>
				</router-link>
			</div>
			<a :data-timestamp="timestamp"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</a>
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-if="item.content" class="post-message">
			<MessageContent :item="item" />
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else class="post-message" v-html="item.account.note" />
		<div v-if="hasAttachments" class="post-attachments">
			<PostAttachment :attachments="item.media_attachments || []" />
		</div>
		<div v-if="$route && $route.params.type !== 'notifications' && !serverData.public" class="post-actions">
			<NcButton v-tooltip="t('social', 'Reply')"
				type="tertiary-no-background"
				@click="reply">
				<template #icon>
					<Reply :size="20" />
				</template>
			</NcButton>
			<NcButton v-tooltip="t('social', 'Boost')"
				type="tertiary-no-background"
				@click="boost">
				<template #icon>
					<Repeat :size="20" :fill-color="isBoosted ? 'blue' : 'var(--color-main-text)'" />
				</template>
			</NcButton>
			<NcButton v-if="!isLiked"
				v-tooltip="t('social', 'Like')"
				type="tertiary-no-background"
				@click="like">
				<template #icon>
					<HeartOutline :size="20" />
				</template>
			</NcButton>
			<NcButton v-if="isLiked"
				v-tooltip="t('social', 'Undo Like')"
				type="tertiary-no-background"
				@click="like">
				<template #icon>
					<Heart :size="20" :fill-color="'var(--color-error)'" />
				</template>
			</NcButton>
			<NcActions>
				<NcActionButton v-if="item.account !== undefined && item.account.acct === currentAccount.acct"
					icon="icon-delete"
					@click="remove()">
					{{ t('social', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line no-unused-vars
import * as linkify from 'linkifyjs'
import 'linkify-plugin-mention'
import 'linkify-string'
import currentUser from './../mixins/currentUserMixin.js'
import PostAttachment from './PostAttachment.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import logger from '../services/logger.js'
import moment from '@nextcloud/moment'
import MessageContent from './MessageContent.js'

export default {
	name: 'TimelinePost',
	components: {
		PostAttachment,
		NcActions,
		NcActionButton,
		NcButton,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
	},
	mixins: [currentUser],
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},
		type: {
			type: String,
			required: true,
		},
	},
	computed: {
		/**
		 * @return {string}
		 */
		relativeTimestamp() {
			return moment(this.item.created_at).fromNow()
		},
		/**
		 * @return {string}
		 */
		formattedDate() {
			return moment(this.item.created_at).format('LLL')
		},
		/**
		 * @return {number}
		 */
		timestamp() {
			return Date.parse(this.item.created_at)
		},
		/**
		 * @return {boolean}
		 */
		hasAttachments() {
			// TODO: clean media_attachments
			return (this.item.media_attachments || []).length > 0
		},
		/**
		 * @return {boolean}
		 */
		isBoosted() {
			return this.item.reblogged === true
		},
		/**
		 * @return {boolean}
		 */

		isLiked() {
			return this.item.favourited === true
		},
		/**
		 * @return {object}
		 */
		richParameters() {
			return {}
		},
		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.item.account.acct.includes('@')
		},
		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.$store.getters.currentAccount
		},
		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},
	},
	methods: {
		/**
		 * @param {MouseEvent} e - The click event
		 * @function getSinglePostTimeline
		 * @description Opens the timeline of the post clicked
		 */
		getSinglePostTimeline(e) {
			// Display internal or external post
			if (!this.isLocal) {
				if (this.type === 'Note') {
					window.open(this.item.id)
				} else if (this.type === 'Announce') {
					// TODO
					window.open(this.item.object)
				} else {
					logger.warn("Don't know what to do with posts of type " + this.type, { post: this.item })
				}
			} else {
				this.$router.push({
					name: 'single-post',
					params: {
						account: this.item.account.display_name,
						id: this.item.id,
						localId: this.item.uri.split('/').pop(),
						type: 'single-post',
					},
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
			const params = {
				post: this.item,
				parentAnnounce: this.reblog,
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
			const params = {
				post: this.item,
				parentAnnounce: this.reblog,
			}
			if (this.isLiked) {
				this.$store.dispatch('postUnlike', params)
			} else {
				this.$store.dispatch('postLike', params)
			}
		},
	},
}
</script>
<style scoped lang="scss">
	@import '@nextcloud/vue-richtext/dist/style.css';
	.post-content {
		padding: 4px 4px 4px 8px;
		font-size: 15px;
		line-height: 1.6em;
		position: relative;

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
		flex-grow: 2;
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
