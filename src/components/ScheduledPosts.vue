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
 * How many to ask for. The route defaults to 20 and pages with ids; an
 * account may not schedule more than 25 a day, so one page of 50 is the
 * whole list for anybody who is not filling it deliberately.
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

		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/scheduled_statuses'),
					{ params: { limit: PAGE_SIZE } },
				)
				this.posts = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Failed to load the scheduled posts', { error })
				showError(translate('social', 'Could not load your scheduled posts'))
			} finally {
				this.loading = false
			}
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
