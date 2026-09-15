<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="held-posts">
		<p v-if="loading && posts.length === 0" class="held-posts__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<ul v-else-if="posts.length > 0" class="held-posts__list">
			<li v-for="post in posts" :key="post.id" class="held-posts__item">
				<div class="held-posts__when">
					<ClockOutline :size="16" decorative title="" />
					<time :datetime="post.created_at">{{ fullDateTime(post.created_at) }}</time>
					<span class="held-posts__reason">{{ reasonText(post.reason) }}</span>
				</div>
				<p v-if="post.spoiler_text" class="held-posts__warning">
					{{ post.spoiler_text }}
				</p>
				<p class="held-posts__text">
					{{ post.text }}
				</p>
				<p v-if="post.media_count > 0" class="held-posts__meta">
					{{ n('social', '%n attachment', '%n attachments', post.media_count) }}
				</p>
				<NcButton
					variant="tertiary"
					class="held-posts__withdraw"
					:aria-label="t('social', 'Take this post back')"
					:title="t('social', 'Take this post back')"
					:disabled="withdrawing.includes(post.id)"
					@click="withdraw(post)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="held-posts__hint">
			{{ t('social', 'Nothing of yours is waiting.') }}
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
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { fullDateTime } from '../utils/relativeTime.js'

/**
 * The reader's own posts that a moderator has not looked at yet.
 *
 * The composer says so when it happens, but a person who closed the tab has to
 * be able to find their writing again — this app must not lose what somebody
 * wrote without telling them where it went. Taking one back deletes it and
 * nothing else: a post nobody has seen, withdrawn by its own author, is not a
 * moderation decision and leaves no record.
 */
export default {
	name: 'HeldPosts',

	components: {
		ClockOutline,
		Close,
		NcButton,
	},

	data() {
		return {
			loading: false,
			/** @type {object[]} the held posts, newest first */
			posts: [],
			/** @type {string[]} the ids whose withdrawal is in flight */
			withdrawing: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t: translate,
		n: translatePlural,
		fullDateTime,

		/**
		 * @param {string} reason the rule that held it
		 * @return {string} what the reader is told
		 */
		reasonText(reason) {
			switch (reason) {
				case 'first_post':
					return translate('social', 'Your first post here')
				case 'links':
					return translate('social', 'More links than a post usually carries')
				case 'mentions':
					return translate('social', 'A lot of mentions')
				case 'repeat':
					return translate('social', 'The same as a post already waiting')
				default:
					return reason
			}
		},

		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/review'))
				this.posts = Array.isArray(data.held) ? data.held : []
			} catch (error) {
				logger.error('Failed to load the held posts', { error })
				showError(translate('social', 'Could not load the posts waiting to be looked at'))
			} finally {
				this.loading = false
			}
		},

		/** @param {object} post the held post to take back */
		async withdraw(post) {
			this.withdrawing = [...this.withdrawing, post.id]
			try {
				await axios.delete(generateUrl('apps/social/api/v1/review/' + post.id))
				this.posts = this.posts.filter((one) => one.id !== post.id)
			} catch (error) {
				logger.error('Failed to withdraw a held post', { error })
				showError(translate('social', 'Could not take the post back'))
			} finally {
				this.withdrawing = this.withdrawing.filter((id) => id !== post.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.held-posts__hint {
	color: var(--color-text-maxcontrast);
}

.held-posts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.held-posts__item {
	position: relative;
	padding: 8px 44px 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.held-posts__when {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
}

.held-posts__reason {
	font-style: italic;
}

.held-posts__warning {
	font-weight: bold;
}

.held-posts__text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.held-posts__meta {
	color: var(--color-text-maxcontrast);
}

.held-posts__withdraw {
	position: absolute;
	inset-block-start: 4px;
	inset-inline-end: 4px;
}
</style>
