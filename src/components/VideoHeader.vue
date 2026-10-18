<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="watch">
		<!-- the player, in one place. `ended` does not bubble, so it is
		     caught on its way down to whatever plays the video, and passed
		     on for Up next -->
		<div class="watch__stage" @ended.capture="onEnded">
			<PostAttachment
				class="watch__player"
				mediaFirst
				:to="null"
				:video="video"
				:attachments="status.media_attachments || []" />
		</div>

		<h1 class="watch__title">
			{{ title }}
			<span v-if="video.live" class="watch__live">{{ t('social', 'Live') }}</span>
		</h1>

		<div class="watch__bar">
			<!-- the channel, which is what a reader subscribes to: on PeerTube a
			     video is listed under it rather than under the person -->
			<div class="watch__channel">
				<ActorAvatar :actor="status.account" :size="40" />
				<router-link
					class="watch__channel-link"
					:to="{ name: 'profile', params: { account: status.account.acct } }">
					<span class="watch__channel-name">
						{{ status.account.display_name || status.account.username }}
					</span>
					<span v-if="followers !== ''" class="watch__channel-followers">{{ followers }}</span>
				</router-link>
				<FollowButton :uid="status.account.acct" />
			</div>

			<VideoActions :status="status" :theater="theater" @toggleTheater="$emit('toggleTheater')" />
		</div>

		<VideoDescription :status="status" @seek="seekTo" />
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ActorAvatar from './ActorAvatar.vue'
import FollowButton from './FollowButton.vue'
import PostAttachment from './PostAttachment.vue'
import VideoActions from './VideoActions.vue'
import VideoDescription from './VideoDescription.vue'
import { useServerData } from '../composables/useServerData.js'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { compactCount } from '../utils/videoCard.js'

/**
 * A video's own page, rather than a post with a rectangle in it, drawn the
 * way a video site draws one: the player, the title as the heading, the
 * channel with its follow button beside the row of actions, and the
 * description box under them.
 *
 * It is the same route a post opens on, not a second one somebody has to find:
 * every link to a video already leads here, and what changes is what the page
 * says about it. Underneath, the conversation is the conversation — a video's
 * comments are replies, and this app already has replies.
 *
 * The player is `PostAttachment` and nothing else here touches the element it
 * draws, beyond moving it to a chapter and hearing that it ended.
 */
export default {
	name: 'VideoHeader',

	components: {
		ActorAvatar,
		FollowButton,
		PostAttachment,
		VideoActions,
		VideoDescription,
	},

	props: {
		status: {
			type: /** @type {import('vue').PropType<object>} */ (Object),
			required: true,
		},

		/** Whether the wide player is on; the page lays itself out for it. */
		theater: {
			type: Boolean,
			default: false,
		},
	},

	/** `ended` when the video played to its end; `toggleTheater` from the switch. */
	emits: ['ended', 'toggleTheater'],

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	computed: {
		/** @return {object} what the post said about the video */
		video() {
			return this.status.video ?? {}
		},

		/**
		 * What it is called.
		 *
		 * The object's own `name` where there is one. A video posted from here
		 * has no separate title — the composer does not ask for one — so the
		 * first line of the post stands in, which is where somebody writing
		 * about a video puts its name.
		 *
		 * @return {string}
		 */
		title() {
			if (this.video.title) {
				return this.video.title
			}

			const text = (this.status.content || '').replace(/<[^>]*>/g, '\n')

			return (text.split('\n').find((line) => line.trim() !== '') || '').trim()
		},

		/** @return {string} "1.2K followers", '' when the reader hides counts or there is no count */
		followers() {
			const count = this.status.account?.followers_count
			if (useSettingsStore().hidesCounts || typeof count !== 'number') {
				return ''
			}

			return n('social', '{count} follower', '{count} followers', count, { count: compactCount(count) })
		},
	},

	watch: {
		/**
		 * The follow button draws what the reader's relationship with the
		 * channel is, and nothing on a post's page asked for it: the channel
		 * is put in the store as the post carries it, and the relationship
		 * asked for with whatever else the page asks for at the same moment.
		 */
		'status.account.id': {
			immediate: true,
			handler(id) {
				const account = this.status.account
				if (this.serverData.public || !id || !account?.url) {
					return
				}
				const accounts = useAccountStore()
				if (accounts.getAccount(account.acct) === undefined) {
					accounts.addAccount({ actorId: account.url, data: account })
				}
				accounts.fetchRelationship(id)
			},
		},
	},

	methods: {
		t,

		/**
		 * Passes on that the video ended, and only the video: a picture or a
		 * clip somewhere else on the page ending is not this.
		 *
		 * @param {Event} event the `ended` on its way to its element
		 */
		onEnded(event) {
			if (/** @type {Element} */ (event.target)?.tagName === 'VIDEO') {
				this.$emit('ended')
			}
		},

		/**
		 * Moves the player to a chapter.
		 *
		 * The element is found rather than passed down: the player is several
		 * components below this one and threading a ref through all of them to
		 * seek would tie each of them to this page.
		 *
		 * @param {number} seconds where to go
		 */
		seekTo(seconds) {
			const player = this.$el?.querySelector('.watch__stage video')
			if (!player) {
				return
			}

			player.currentTime = seconds
			// a chapter pressed is a chapter somebody wants to watch
			player.play().catch(() => {})
		},
	},
}
</script>

<style scoped lang="scss">
.watch__stage {
	overflow: hidden;
	border-radius: var(--border-radius-large);
	background: #000;
}

.watch__title {
	margin-block: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 2);
	font-size: 20px;
	font-weight: 700;
	line-height: 1.4;
	overflow-wrap: anywhere;
}

.watch__live {
	display: inline-block;
	margin-inline-start: 8px;
	padding: 0 6px;
	border-radius: var(--border-radius);
	background: var(--color-error);
	color: var(--color-primary-text);
	font-size: 12px;
	vertical-align: middle;
}

.watch__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
}

.watch__channel {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	min-inline-size: 0;
}

.watch__channel-link {
	display: flex;
	flex-direction: column;
	min-inline-size: 0;
	color: inherit;
	text-decoration: none;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.watch__channel-name {
	overflow: hidden;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.watch__channel-followers {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}
</style>
