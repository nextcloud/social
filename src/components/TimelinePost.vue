<template>
	<div class="post-content" :data-social-status="item.id">
		<div class="post-header">
			<div class="post-author-wrapper" :title="item.account.acct">
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
			<VisibilityIcon v-if="visibility"
				:title="visibility.text"
				class="post-visibility"
				:visibility="visibility.id" />
		</div>
		<div v-if="item.content" class="post-message">
			<MessageContent :item="item" />
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else class="post-message" v-html="item.account.note" />
		<PostAttachment v-if="hasAttachments" :attachments="item.media_attachments || []" />
		<div v-if="$route && $route.params.type !== 'notifications' && !serverData.public" class="post-actions">
			<NcButton :title="t('social', 'Reply')"
				type="tertiary"
				@click="reply">
				<template #icon>
					<Reply :size="20" />
				</template>
				<template #default>
					<span v-if="item.replies_count !== 0">
						{{ item.replies_count }}
					</span>
				</template>
			</NcButton>
			<NcButton v-if="item.visibility === 'public' || item.visibility === 'unlisted'"
				:title="t('social', 'Boost')"
				type="tertiary"
				@click="boost">
				<template #icon>
					<Repeat :size="20" :fill-color="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
				</template>
				<template #default>
					<span v-if="item.reblogs_count !== 0">
						{{ item.reblogs_count }}
					</span>
				</template>
			</NcButton>
			<NcButton v-if="!isLiked"
				:title="t('social', 'Like')"
				type="tertiary"
				@click="like">
				<template #icon>
					<HeartOutline :size="20" />
				</template>
				<template #default>
					<span v-if="item.favourites_count !== 0">
						{{ item.favourites_count }}
					</span>
				</template>
			</NcButton>
			<NcButton v-if="isLiked"
				:title="t('social', 'Undo Like')"
				type="tertiary"
				@click="like">
				<template #icon>
					<Heart :size="20" :fill-color="'var(--color-error)'" />
				</template>
			</NcButton>
			<NcActions>
				<NcActionButton v-if="item.account.acct === currentAccount?.acct"
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
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'

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
		VisibilityIcon,
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
		/** @return {object} */
		visibility() {
			return visibilitiesInfo.find(({ id }) => this.item.visibility === id)
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
				logger.warn("Don't know what to do with posts of type " + this.type, { post: this.item })
				return
			}

			this.$router.push({
				name: 'single-post',
				params: {
					account: this.item.account.display_name,
					id: this.item.id,
					type: 'single-post',
				},
			})
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
				status: this.item,
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
				status: this.item,
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
		padding: 4px 8px;
		font-size: 15px;
		line-height: 1.6em;
		border-radius: 8px;

		::v-deep a.widget-default {
			text-decoration: none !important;
		}

		&:hover {
			background-color: var(--color-background-hover);
		}

		.post-header {
			display: flex;
			gap: 8px;
			flex-direction: row;
			justify-content: space-between;

			.post-author-wrapper {
				flex-grow: 1;

				&:hover {
					text-decoration: underline;
				}

				.post-author {
					font-weight: bold;

				}

				.post-author-id {
					color: var(--color-text-lighter);
				}
			}

			.post-visibility {
				color: var(--color-text-lighter);
				background-position: right;
			}

			.post-timestamp {
				text-align: right;
				color: var(--color-text-lighter);

				&:hover {
					text-decoration: underline;
				}
			}
		}
	}

	.post-message :deep(a) {
		overflow-wrap: anywhere;

		&:hover {
			text-decoration: underline;
		}
	}

	.post-actions {
		margin-left: -13px;
		height: 44px;
		display: flex;
		margin: 4px;

		.button-vue:hover {
			background: var(--color-background-dark);
		}

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
</style>
