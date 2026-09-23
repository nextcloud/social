<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li class="profile-status-card">
		<TimelineEntry :item="status" type="account" :postHref="postHref" />
		<footer class="profile-status-card__actions" :aria-label="t('social', 'Post actions')">
			<NcButton variant="tertiary" :aria-expanded="likesOpen" @click="likesOpen = !likesOpen">
				{{ t('social', 'Likes ({count})', { count: status.favourites_count || 0 }) }}
			</NcButton>
			<NcButton variant="tertiary" :aria-expanded="commentsOpen" @click="toggleComments">
				{{ t('social', 'Comments ({count})', { count: commentCount }) }}
			</NcButton>
			<a v-if="postHref" class="profile-status-card__open" :href="postHref">{{ t('social', 'Open post') }}</a>
		</footer>
		<PostReactedBy v-if="likesOpen" :status="status" />
		<section v-if="commentsOpen" class="profile-status-card__comments" :aria-label="t('social', 'Comments')">
			<p v-if="commentsLoading" role="status">
				{{ t('social', 'Loading comments…') }}
			</p>
			<p v-else-if="commentsError" role="alert">
				{{ t('social', 'Could not load comments') }}
			</p>
			<p v-else-if="comments.length === 0">
				{{ t('social', 'No comments yet') }}
			</p>
			<template v-else>
				<article v-for="comment in comments" :key="comment.id" class="profile-status-card__comment">
					<strong>{{ comment.account?.display_name || comment.account?.acct || t('social', 'Unknown account') }}</strong>
					<MessageContent :item="comment" />
				</article>
			</template>
		</section>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import TimelineEntry from './TimelineEntry.vue'
import PostReactedBy from './PostReactedBy.vue'
import MessageContent from './MessageContent.js'
import logger from '../services/logger.js'

export default {
	name: 'ProfileStatusCard',
	components: { MessageContent, NcButton, PostReactedBy, TimelineEntry },
	props: {
		status: { type: Object, required: true },
	},

	data() {
		return { likesOpen: false, commentsOpen: false, comments: [], commentsLoading: false, commentsError: false }
	},

	computed: {
		postHref() {
			return this.status.url || this.status.uri || ''
		},

		commentCount() {
			return Number(this.status.replies_count ?? this.status.reply_count) || 0
		},
	},

	methods: {
		t,
		async toggleComments() {
			this.commentsOpen = !this.commentsOpen
			if (!this.commentsOpen || this.comments.length || this.commentsLoading) {
				return
			}
			this.commentsLoading = true
			this.commentsError = false
			try {
				const id = encodeURIComponent(String(this.status.id))
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/statuses/${id}/context`))
				this.comments = (Array.isArray(data?.descendants) ? data.descendants : [])
					.filter((reply) => String(reply.in_reply_to_id) === String(this.status.id))
			} catch (error) {
				this.commentsError = true
				logger.error('Failed to load profile post comments', { error, statusId: this.status.id })
			} finally {
				this.commentsLoading = false
			}
		},
	},
}
</script>

<style scoped>
.profile-status-card {
	margin-block: 0 1rem;
	list-style: none;
}

.profile-status-card__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.25rem 0.5rem;
	margin: -0.35rem 0 0;
	padding: 0.25rem 0.75rem 0.6rem;
	border: 1px solid var(--color-border);
	border-top: 0;
	border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
	background: var(--color-main-background);
}

.profile-status-card__open {
	margin-inline-start: auto;
}

.profile-status-card__comments {
	padding: 0.5rem 1rem;
	border-inline: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.profile-status-card__comment {
	padding-block: 0.75rem;
	border-bottom: 1px solid var(--color-border);
}
</style>
