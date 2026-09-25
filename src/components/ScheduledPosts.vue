<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="scheduled-posts">
		<p v-if="loading && posts.length === 0" class="scheduled-posts__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<ul v-else-if="posts.length > 0" class="scheduled-posts__list">
			<li v-for="post in posts" :key="post.id" class="scheduled-posts__item">
				<div class="scheduled-posts__when">
					<ClockOutline :size="16" decorative title="" />
					<time :datetime="post.scheduled_at">{{ fullDateTime(post.scheduled_at) }}</time>
					<VisibilityIcon :visibility="visibilityOf(post)" :size="16" />
				</div>
				<p class="scheduled-posts__text">
					{{ textOf(post) }}
				</p>
				<p v-if="attachmentsOf(post) > 0" class="scheduled-posts__meta">
					{{ n('social', '%n attachment', '%n attachments', attachmentsOf(post)) }}
				</p>
				<NcButton
					variant="tertiary"
					class="scheduled-posts__cancel"
					:aria-label="t('social', 'Cancel this scheduled post')"
					:title="t('social', 'Cancel this scheduled post')"
					:disabled="cancelling.includes(post.id)"
					@click="cancel(post)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="scheduled-posts__hint">
			{{ t('social', 'Nothing is waiting to be posted. The clock in the composer schedules a post for later.') }}
		</p>

		<p v-if="hasMore" class="scheduled-posts__more">
			<NcButton :disabled="loading" @click="load(true)">
				{{ loading ? t('social', 'Loading …') : t('social', 'Show more') }}
			</NcButton>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * How many to ask for at a time. An account may hold up to 300 waiting posts
 * — 25 a day, spread over as many days as it likes — so a page is a page and
 * not the whole list, and there is a button under it.
 */
const PAGE_SIZE = 50

/**
 * The posts waiting to go out, and a way to take one back.
 *
 * A section of Settings rather than a page of its own: the list is short —
 * an account may not hold more than 300 — and what is done with an entry is
 * one thing, cancelling it. Moving one to another time is left to the API
 * (`PUT /api/v1/scheduled_statuses/{id}`); the composer proposes a time
 * before the post exists, and a post written for the wrong time is written
 * again in the box, which still holds its draft.
 */
export default {
	name: 'ScheduledPosts',
	components: {
		ClockOutline,
		Close,
		NcButton,
		VisibilityIcon,
	},

	data() {
		return {
			loading: false,
			/** @type {object[]} ScheduledStatus entities, soonest first */
			posts: [],
			/** @type {string[]} the ids whose cancellation is in flight */
			cancelling: [],
			/** whether the last page came back full, so there may be more behind it */
			hasMore: false,
			/** the post-scheduled handler, kept so only this one is removed */
			onScheduled: null,
		}
	},

	mounted() {
		this.load()
		// the New post dialog opens over this page too, and a post scheduled
		// from it belongs in the list without a reload
		this.onScheduled = () => this.load()
		eventBus.on('post-scheduled', this.onScheduled)
	},

	unmounted() {
		eventBus.off('post-scheduled', this.onScheduled)
	},

	methods: {
		t: translate,
		n: translatePlural,
		fullDateTime,

		/** @param {object} post a ScheduledStatus */
		textOf(post) {
			return post.params?.text || ''
		},

		/**
		 * The audience, in the words the icon knows. Mastodon's `private` is
		 * this app's `followers`; anything else the icon marks as unknown.
		 *
		 * @param {object} post a ScheduledStatus
		 * @return {string}
		 */
		visibilityOf(post) {
			const visibility = post.params?.visibility || 'public'

			return visibility === 'private' ? 'followers' : visibility
		},

		/** @param {object} post a ScheduledStatus */
		attachmentsOf(post) {
			return Array.isArray(post.media_attachments) ? post.media_attachments.length : 0
		},

		/**
		 * A page of the waiting posts, soonest first.
		 *
		 * The cursor is `min_id` rather than `max_id`: this list is drawn in
		 * the order the posts will go out, so the page after the one on screen
		 * is the one scheduled *later*, and `max_id` asks for the other
		 * direction. Ids are compared against the row they name — see
		 * `ScheduledStatusesRequest::beyond()` — because an id is creation
		 * order and `scheduled_at` is publication order, and a post can be
		 * moved from one to the other at any time.
		 *
		 * @param {boolean} more whether this is the reader asking for the page
		 *                       after the one they have
		 */
		async load(more = false) {
			if (this.loading) {
				return
			}

			const params = { limit: PAGE_SIZE }
			if (more) {
				const last = this.posts.at(-1)
				if (!last) {
					return
				}

				params.min_id = String(last.id)
			}

			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/scheduled_statuses'),
					{ params },
				)
				const page = Array.isArray(data) ? data : []
				// a full page may have more behind it; a short one is the end
				this.hasMore = page.length >= PAGE_SIZE
				this.posts = more ? this.merge(this.posts, page) : page
			} catch (error) {
				logger.error('Failed to load the scheduled posts', { error, more })
				showError(translate('social', 'Could not load your scheduled posts'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * One entry per id, in the order they arrived.
		 *
		 * Defensive: a post moved to another time between two requests can sit
		 * on both sides of the cursor, and the same entry twice in this list
		 * is two Cancel buttons for one post.
		 *
		 * @param {object[]} held what is already on screen
		 * @param {object[]} page what just arrived
		 * @return {object[]} the two, without repeats
		 */
		merge(held, page) {
			const seen = new Set(held.map((post) => String(post.id)))

			return [...held, ...page.filter((post) => !seen.has(String(post.id)))]
		},

		/** @param {object} post the ScheduledStatus to take back */
		async cancel(post) {
			this.cancelling = [...this.cancelling, post.id]
			try {
				await axios.delete(generateUrl('apps/social/api/v1/scheduled_statuses/' + post.id))
				this.posts = this.posts.filter((one) => one.id !== post.id)
			} catch (error) {
				logger.error('Failed to cancel a scheduled post', { error })
				showError(translate('social', 'Could not cancel the scheduled post'))
			} finally {
				this.cancelling = this.cancelling.filter((id) => id !== post.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.scheduled-posts__hint {
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__more {
	display: flex;
	justify-content: center;
	margin-block-start: 0.75rem;
}

.scheduled-posts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.scheduled-posts__item {
	position: relative;
	padding: 10px 52px 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.scheduled-posts__when {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__text {
	margin: 4px 0 0;
	// three lines is enough to know which post it is
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
	overflow-wrap: anywhere;
}

.scheduled-posts__meta {
	margin: 4px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.scheduled-posts__cancel {
	position: absolute;
	inset-inline-end: 8px;
	inset-block-start: 8px;
}
</style>
