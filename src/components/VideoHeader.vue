<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="watch">
		<PostAttachment
			class="watch__player"
			mediaFirst
			:to="null"
			:video="video"
			:attachments="status.media_attachments || []" />

		<h1 class="watch__title">
			{{ title }}
			<span v-if="video.live" class="watch__live">{{ t('social', 'Live') }}</span>
		</h1>

		<p class="watch__facts">
			<span v-if="video.views !== undefined">
				{{ n('social', '%n view', '%n views', video.views) }}
			</span>
			<span v-if="video.likes !== undefined">
				{{ n('social', '%n like', '%n likes', video.likes) }}
			</span>
			<span v-if="video.dislikes">
				{{ n('social', '%n dislike', '%n dislikes', video.dislikes) }}
			</span>
			<span v-if="video.category">{{ video.category }}</span>
			<span v-if="video.language">{{ video.language }}</span>
			<span v-if="video.licence">{{ video.licence }}</span>
		</p>

		<!-- the channel, which is what a reader subscribes to: on PeerTube a
		     video is listed under it rather than under the person -->
		<div class="watch__channel">
			<ActorAvatar :actor="status.account" />
			<router-link
				class="watch__channel-link"
				:to="{ name: 'profile', params: { account: status.account.acct } }">
				<span class="watch__channel-name">
					{{ status.account.display_name || status.account.username }}
				</span>
				<span class="watch__channel-acct">{{ status.account.acct }}</span>
			</router-link>
			<FollowButton :uid="status.account.acct" />
		</div>

		<div v-if="chapters.length" class="watch__chapters">
			<h2 class="watch__heading">
				{{ t('social', 'Chapters') }}
			</h2>
			<ol class="watch__chapter-list">
				<li v-for="chapter in chapters" :key="chapter.start">
					<button class="watch__chapter" type="button" @click="seekTo(chapter.start)">
						<span class="watch__chapter-time">{{ clock(chapter.start) }}</span>
						<span class="watch__chapter-title">{{ chapter.title }}</span>
					</button>
				</li>
			</ol>
		</div>

		<p v-if="video.support" class="watch__support">
			{{ video.support }}
		</p>

		<p v-if="video.download === false" class="watch__note">
			{{ t('social', 'The author has asked that this video not be downloaded.') }}
		</p>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import ActorAvatar from './ActorAvatar.vue'
import FollowButton from './FollowButton.vue'
import PostAttachment from './PostAttachment.vue'

/**
 * A video's own page, rather than a post with a rectangle in it.
 *
 * It is the same route a post opens on, not a second one somebody has to find:
 * every link to a video already leads here, and what changes is what the page
 * says about it. Underneath, the conversation is the conversation — a video's
 * comments are replies, and this app already has replies.
 *
 * What is on it is what a `Video` object actually carries and a timeline card
 * has no room for: the title as a heading (a `Note` has no title, so the reader
 * used to get it as the first line of a paragraph), the counters, the category
 * and licence, the chapters as something to press, and the line the author
 * wrote asking for support.
 */
export default {
	name: 'VideoHeader',

	components: {
		ActorAvatar,
		FollowButton,
		PostAttachment,
	},

	props: {
		/** @type {import('vue').PropType<object>} */
		status: {
			type: Object,
			required: true,
		},
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

		/** @return {Array<{title: string, start: number}>} */
		chapters() {
			return Array.isArray(this.video.chapters) ? this.video.chapters : []
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {number} seconds a moment in the video
		 * @return {string} it as a clock, `1:02:03` or `2:03`
		 */
		clock(seconds) {
			const whole = Math.max(0, Math.floor(seconds))
			const parts = [Math.floor(whole / 60) % 60, whole % 60]
			if (whole >= 3600) {
				parts.unshift(Math.floor(whole / 3600))
			}

			return parts
				.map((part, index) => (index === 0 ? String(part) : String(part).padStart(2, '0')))
				.join(':')
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
			const player = this.$el?.querySelector('video')
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
.watch__title {
	margin-block: 12px 4px;
	font-size: 20px;
	line-height: 1.3;
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

.watch__facts {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

.watch__channel {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding-block: 12px;
	border-block: 1px solid var(--color-border);
}

.watch__channel-link {
	display: flex;
	flex-direction: column;
	text-decoration: none;
	color: inherit;
	margin-inline-end: auto;
}

.watch__channel-name {
	font-weight: bold;
}

.watch__channel-acct {
	color: var(--color-text-maxcontrast);
}

.watch__heading {
	font-size: 15px;
	margin-block: 16px 4px;
}

.watch__chapter-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.watch__chapter {
	display: flex;
	gap: 8px;
	width: 100%;
	padding: 4px 8px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: inherit;
	text-align: start;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.watch__chapter-time {
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	min-width: 4em;
}

.watch__support,
.watch__note {
	color: var(--color-text-maxcontrast);
	margin-block: 12px 0;
	overflow-wrap: anywhere;
}
</style>
