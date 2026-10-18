<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- out of the timeline, so no transform on a row above it can pin the
	     panel to that row instead of to the window -->
	<Teleport to="body">
		<div class="photo-comments">
			<!-- a phone's sheet covers the feed; tapping what is left of the
			     feed puts the sheet away -->
			<div class="photo-comments__scrim" aria-hidden="true" @click="$emit('close')" />
			<section
				ref="panel"
				class="photo-comments__panel"
				role="dialog"
				:aria-labelledby="headingId"
				tabindex="-1"
				@keydown.esc.stop="$emit('close')">
				<header class="photo-comments__header">
					<h2 :id="headingId" class="photo-comments__heading">
						{{ t('social', 'Comments') }}
					</h2>
					<NcButton
						variant="tertiary"
						:aria-label="t('social', 'Close')"
						@click="$emit('close')">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</header>

				<div class="photo-comments__list">
					<!-- the caption opens the thread, as the first thing said
					     about the picture -->
					<article v-if="item.content" class="photo-comments__comment photo-comments__comment--caption">
						<ActorAvatar :actor="item.account" :size="28" />
						<div class="photo-comments__body">
							<span class="photo-comments__author">{{ nameOf(item.account) }}</span>
							<MessageContent class="photo-comments__text" :item="item" />
						</div>
					</article>

					<p v-if="loading" class="photo-comments__state" role="status">
						{{ t('social', 'Loading comments…') }}
					</p>
					<p v-else-if="failed" class="photo-comments__state" role="alert">
						{{ t('social', 'Could not load comments') }}
					</p>
					<p v-else-if="comments.length === 0" class="photo-comments__state">
						{{ t('social', 'No comments yet') }}
					</p>
					<ul v-else class="photo-comments__thread">
						<li
							v-for="comment in comments"
							:key="comment.id"
							class="photo-comments__comment"
							:class="{ 'photo-comments__comment--answer': comment.answer }">
							<ActorAvatar :actor="comment.status.account" :size="28" />
							<div class="photo-comments__body">
								<span class="photo-comments__author">{{ nameOf(comment.status.account) }}</span>
								<MessageContent class="photo-comments__text" :item="comment.status" />
								<time class="photo-comments__age" :datetime="comment.status.created_at" :title="fullDateTime(comment.status.created_at)">
									{{ shortAgo(comment.status.created_at) }}
								</time>
							</div>
						</li>
					</ul>
				</div>

				<div v-if="canReply" class="photo-comments__reply">
					<Composer :inReplyTo="item" emojiPickerContainer=".photo-comments__panel" @posted="load" />
				</div>
			</section>
		</div>
	</Teleport>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { defineAsyncComponent } from 'vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import ActorAvatar from './ActorAvatar.vue'
import MessageContent from './MessageContent.js'
import logger from '../services/logger.js'
import { allowedByAuthor } from '../utils/interactionPolicy.js'
import { fullDateTime, shortAgo } from '../utils/relativeTime.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))

/** ids have to be unique per document */
let sequence = 0

/** on the page while the panel is: the feed gives up the panel's width */
const MAKING_ROOM = 'photo-comments-open'

/**
 * A photo's comments, beside the feed on a wide screen and as a sheet from
 * the bottom on a phone.
 *
 * The thread is read from the post's context, as a profile card reads it,
 * and not through the store: the store's timeline is the feed under the
 * panel, and reading the thread into it would take the reader's place in
 * the feed away. The reply field is the composer every reply is written in.
 */
export default {
	name: 'PhotoComments',
	components: {
		ActorAvatar,
		Composer,
		IconClose,
		MessageContent,
		NcButton,
	},

	props: {
		/** the post whose comments these are */
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			/** @type {import('../types/Mastodon.js').Status[]} */
			replies: [],
			loading: false,
			failed: false,
			headingId: `photo-comments-${sequence++}`,
		}
	},

	computed: {
		/** @return {boolean} whether the author takes replies to this post */
		canReply() {
			return allowedByAuthor(this.item, 'reply')
		},

		/**
		 * The replies in thread order, each marked when it answers another
		 * comment rather than the picture: those are set in once, which is as
		 * deep as a column this narrow can show.
		 *
		 * @return {Array<{id: string, status: object, answer: boolean}>}
		 */
		comments() {
			const root = String(this.item.id)

			return this.replies.map((status) => ({
				id: String(status.id),
				status,
				answer: String(status.in_reply_to_id) !== root,
			}))
		},
	},

	watch: {
		'item.id': function() {
			this.load()
		},
	},

	mounted() {
		document.documentElement.classList.add(MAKING_ROOM)
		const panel = /** @type {HTMLElement|undefined} */ (this.$refs.panel)
		panel?.focus()
		this.load()
	},

	beforeUnmount() {
		document.documentElement.classList.remove(MAKING_ROOM)
	},

	methods: {
		t,
		shortAgo,
		fullDateTime,

		/**
		 * @param {object} account who wrote something
		 * @return {string} what to call them
		 */
		nameOf(account) {
			return account?.display_name || account?.username || account?.acct || t('social', 'Unknown account')
		},

		/** Reads the thread, again after a reply went out. */
		async load() {
			const id = String(this.item.id)
			this.loading = this.replies.length === 0
			this.failed = false
			try {
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/statuses/${encodeURIComponent(id)}/context`))
				// the panel may have been pointed at another post meanwhile
				if (String(this.item.id) === id) {
					this.replies = Array.isArray(data?.descendants) ? data.descendants : []
				}
			} catch (error) {
				this.failed = true
				logger.error('Could not load the comments of a photo', { error, statusId: id })
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.photo-comments {
	&__scrim {
		display: none;
	}

	/* beside the feed: the column stays where it is, the reader's place in
	   it with it, and the panel takes the side of the window */
	&__panel {
		position: fixed;
		z-index: 1500;
		inset-block: var(--header-height, 50px) 0;
		inset-inline-end: 0;
		display: flex;
		flex-direction: column;
		width: min(400px, 100vw);
		background: var(--color-main-background);
		color: var(--color-main-text);
		border-inline-start: 1px solid var(--color-border);
		box-shadow: var(--social-elevation-raised);

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 6px 8px 6px 16px;
		border-block-end: 1px solid var(--color-border);
	}

	&__heading {
		margin: 0;
		font-size: 16px;
		font-weight: 650;
	}

	&__list {
		flex: 1 1 auto;
		overflow-y: auto;
		overscroll-behavior: contain;
		padding: 8px 16px;
	}

	&__thread {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__comment {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding-block: 8px;

		&--caption {
			padding-block-end: 12px;
			border-block-end: 1px solid var(--color-border);
		}

		&--answer {
			padding-inline-start: 38px;
		}
	}

	&__body {
		flex: 1 1 auto;
		min-width: 0;
		overflow-wrap: break-word;
		font-size: 14px;
		line-height: 1.45;
	}

	&__author {
		font-weight: 650;
	}

	&__text :deep(p) {
		margin: 0 0 4px;
	}

	&__age {
		display: block;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__state {
		margin: 12px 0;
		color: var(--color-text-maxcontrast);
	}

	&__reply {
		padding: 8px;
		border-block-start: 1px solid var(--color-border);
	}
}

/* a phone has no side to put it on: a sheet from the bottom, over a dimmed
   feed, which a tap on the feed puts away */
@include layout.below(layout.$phone) {
	.photo-comments__scrim {
		display: block;
		position: fixed;
		z-index: 1499;
		inset: 0;
		background: rgb(0 0 0 / 40%);
	}

	.photo-comments__panel {
		inset-block: auto 0;
		inset-inline: 0;
		width: 100%;
		max-height: 80vh;
		border-inline-start: none;
		border-radius: var(--border-radius-large, 12px) var(--border-radius-large, 12px) 0 0;
		animation: photo-comments-rise .25s ease-out both;
	}
}

@keyframes photo-comments-rise {
	from { transform: translateY(100%); }
	to { transform: none; }
}

@media (prefers-reduced-motion: reduce) {
	.photo-comments__panel {
		animation: none;
	}
}
</style>

<style lang="scss">
@use '../styles/layout.scss' as layout;

/* beside the feed means beside it: while the panel is open, the app's content
   gives up the panel's width, so no picture is under it. Not on a phone, where
   the panel is a sheet over the feed */
@include layout.from(layout.$phone + 1) {
	html.photo-comments-open #app-content-vue {
		padding-inline-end: min(400px, 100vw);
	}
}
</style>
