<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section
		class="direct-messages"
		:class="{
			'direct-messages--selected': selectedConversationId !== '' || newMessageOpen,
			'direct-messages--composing': newMessageOpen,
		}">
		<aside class="direct-messages__list-panel" :aria-label="t('social', 'Direct message conversations')">
			<header class="direct-messages__list-heading">
				<div>
					<p class="direct-messages__eyebrow">
						{{ t('social', 'YOUR INBOX') }}
					</p>
					<h2>{{ t('social', 'Messages') }}</h2>
				</div>
				<NcButton variant="primary" class="direct-messages__new-button" @click="newMessageOpen = !newMessageOpen">
					{{ t('social', 'New message') }}
				</NcButton>
			</header>
			<label class="direct-messages__search">
				<span class="hidden-visually">{{ t('social', 'Search conversations') }}</span>
				<input v-model="searchQuery" type="search" :placeholder="t('social', 'Search conversations')">
			</label>
			<nav class="direct-messages__filters" :aria-label="t('social', 'Filter conversations')">
				<button type="button" :class="{ 'direct-messages__filter--active': filterMode === 'all' }" @click="filterMode = 'all'">
					{{ t('social', 'All') }}
				</button>
				<button type="button" :class="{ 'direct-messages__filter--active': filterMode === 'unread' }" @click="filterMode = 'unread'">
					{{ t('social', 'Unread ({count})', { count: unreadCount }) }}
				</button>
			</nav>

			<p v-if="loadingList" class="direct-messages__state" role="status">
				{{ t('social', 'Loading conversations…') }}
			</p>
			<p v-else-if="listError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not load conversations') }}
			</p>
			<p v-else-if="conversations.length === 0" class="direct-messages__state">
				{{ t('social', 'No direct conversations yet') }}
			</p>
			<p v-else-if="filteredConversations.length === 0" class="direct-messages__state">
				{{ t('social', 'No conversations match your search') }}
			</p>

			<ul v-else class="direct-messages__list">
				<li v-for="conversation in filteredConversations" :key="conversation.id">
					<button
						class="direct-messages__conversation"
						:class="{
							'direct-messages__conversation--selected': String(conversation.id) === selectedConversationId,
							'direct-messages__conversation--unread': conversation.unread,
						}"
						type="button"
						:aria-current="String(conversation.id) === selectedConversationId ? 'true' : undefined"
						:aria-label="t('social', 'Conversation with {name}', { name: conversationName(conversation) })"
						@click="selectConversation(String(conversation.id))">
						<ActorAvatar
							v-if="conversation.accounts?.[0]"
							:actor="conversation.accounts[0]"
							:size="40"
							:link="false" />
						<span class="direct-messages__conversation-copy">
							<span class="direct-messages__conversation-title-row">
								<span class="direct-messages__conversation-title">{{ conversationName(conversation) }}</span>
								<time v-if="conversation.last_status?.created_at" class="direct-messages__conversation-time" :datetime="conversation.last_status.created_at">{{ formatTime(conversation.last_status.created_at) }}</time>
							</span>
							<span class="direct-messages__preview">{{ preview(conversation.last_status) || t('social', 'No messages yet') }}</span>
						</span>
						<span v-if="conversation.unread" class="direct-messages__unread-dot" :aria-label="t('social', 'Unread')" />
					</button>
				</li>
			</ul>
		</aside>

		<section v-if="newMessageOpen" class="direct-messages__thread-panel direct-messages__new-message-panel" :aria-label="t('social', 'New direct message')">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="newMessageOpen = false">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<div>
					<p class="direct-messages__eyebrow">
						{{ t('social', 'PRIVATE MESSAGE') }}
					</p>
					<h2>{{ t('social', 'New message') }}</h2>
				</div>
			</header>
			<Composer class="direct-messages__new-message-composer" defaultVisibility="direct" @posted="onNewMessagePosted" />
		</section>

		<section v-else-if="activeConversation" class="direct-messages__thread-panel" :aria-label="threadLabel">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="$emit('select', '')">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<ActorAvatar
					v-if="activeConversation.accounts?.[0]"
					:actor="activeConversation.accounts[0]"
					:size="40"
					:link="false" />
				<div class="direct-messages__thread-person">
					<h2>{{ conversationName(activeConversation) }}</h2>
					<p>{{ t('social', 'Private conversation') }}</p>
				</div>
			</header>

			<p v-if="loadingThread" class="direct-messages__state" role="status">
				{{ t('social', 'Loading messages…') }}
			</p>
			<p v-else-if="threadError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not load this conversation') }}
			</p>
			<div
				v-else
				ref="threadContainer"
				class="direct-messages__thread"
				aria-live="polite">
				<div
					v-for="message in messages"
					:key="message.id"
					class="direct-messages__message"
					:class="{ 'direct-messages__message--outgoing': isOutgoing(message) }">
					<TimelineEntry :item="message" type="direct" />
				</div>
				<p v-if="messages.length === 0" class="direct-messages__state">
					{{ t('social', 'No messages in this conversation') }}
				</p>
			</div>

			<Composer
				v-if="activeConversation.last_status"
				class="direct-messages__composer"
				defaultVisibility="direct"
				:inReplyTo="activeConversation.last_status"
				@posted="refreshSelectedConversation" />
		</section>

		<section v-else class="direct-messages__thread-panel direct-messages__thread-panel--empty">
			<div class="direct-messages__welcome">
				<div class="direct-messages__welcome-mark" aria-hidden="true">
					✉
				</div>
				<h2>{{ t('social', 'Your messages, together') }}</h2>
				<p>{{ t('social', 'Choose a conversation to pick up where you left off, or start a new one.') }}</p>
				<NcButton variant="primary" @click="newMessageOpen = true">
					{{ t('social', 'New message') }}
				</NcButton>
			</div>
		</section>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import ActorAvatar from './ActorAvatar.vue'
import Composer from './Composer/Composer.vue'
import TimelineEntry from './TimelineEntry.vue'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'

export default {
	name: 'DirectMessages',
	components: {
		ActorAvatar,
		Composer,
		NcButton,
		TimelineEntry,
	},

	props: {
		selectedConversationId: {
			type: String,
			default: '',
		},
	},

	emits: ['select'],
	data() {
		return {
			conversations: [],
			searchQuery: '',
			filterMode: 'all',
			currentUserId: window.OC?.getCurrentUser?.()?.uid ?? '',
			loadingList: true,
			listError: false,
			loadingThread: false,
			threadError: false,
			thread: { ancestors: [], descendants: [] },
			newMessageOpen: false,
			threadRequest: 0,
		}
	},

	computed: {
		filteredConversations() {
			const query = this.searchQuery.trim().toLocaleLowerCase()
			return this.conversations.filter((conversation) => (this.filterMode !== 'unread' || conversation.unread)
				&& (!query || this.conversationName(conversation).toLocaleLowerCase().includes(query)
					|| this.preview(conversation.last_status).toLocaleLowerCase().includes(query)))
		},

		unreadCount() {
			return this.conversations.filter((conversation) => conversation.unread).length
		},

		activeConversation() {
			return this.conversations.find((conversation) => String(conversation.id) === this.selectedConversationId) ?? null
		},

		messages() {
			if (!this.activeConversation?.last_status) {
				return []
			}

			const ordered = [
				...(this.thread.ancestors ?? []),
				this.activeConversation.last_status,
				...(this.thread.descendants ?? []),
			]
			const unique = new Map()
			for (const message of ordered) {
				unique.set(String(message.id), message)
			}
			return [...unique.values()]
		},

		threadLabel() {
			return t('social', 'Conversation with {name}', { name: this.activeConversation ? this.conversationName(this.activeConversation) : '' })
		},
	},

	watch: {
		selectedConversationId(id) {
			if (id !== '') {
				this.loadThread(id)
			} else {
				this.threadRequest++
				this.thread = { ancestors: [], descendants: [] }
				this.loadingThread = false
				this.threadError = false
			}
		},

		messages() {
			this.$nextTick(() => {
				const thread = this.$refs.threadContainer
				if (thread) {
					thread.scrollTop = thread.scrollHeight
				}
			})
		},
	},

	async mounted() {
		await this.loadConversations()
		if (this.selectedConversationId !== '') {
			await this.loadThread(this.selectedConversationId)
		}
	},

	methods: {
		async loadConversations() {
			this.loadingList = true
			this.listError = false
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/conversations'), { params: { limit: 40 } })
				this.conversations = Array.isArray(data) ? data : []
			} catch (error) {
				this.listError = true
				logger.error('Failed to load direct message conversations', { error })
			} finally {
				this.loadingList = false
			}
		},

		async loadThread(id) {
			const conversation = this.conversations.find((item) => String(item.id) === id)
			if (!conversation?.last_status?.id) {
				return
			}

			const request = ++this.threadRequest
			this.loadingThread = true
			this.threadError = false
			try {
				const statusId = encodeURIComponent(String(conversation.last_status.id))
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/statuses/${statusId}/context`))
				if (request !== this.threadRequest || id !== this.selectedConversationId) {
					return
				}
				this.thread = {
					ancestors: Array.isArray(data?.ancestors) ? data.ancestors : [],
					descendants: Array.isArray(data?.descendants) ? data.descendants : [],
				}
				if (conversation.unread) {
					try {
						await axios.post(generateUrl(`apps/social/api/v1/conversations/${encodeURIComponent(id)}/read`))
						conversation.unread = false
					} catch (error) {
						logger.error('Failed to mark direct message conversation as read', { error, conversationId: id })
					}
				}
			} catch (error) {
				if (request === this.threadRequest) {
					this.threadError = true
					logger.error('Failed to load direct message conversation', { error, conversationId: id })
				}
			} finally {
				if (request === this.threadRequest) {
					this.loadingThread = false
				}
			}
		},

		conversationName(conversation) {
			const names = (conversation.accounts ?? [])
				.map((account) => account.display_name || account.acct || account.username)
				.filter(Boolean)
			return names.join(', ') || t('social', 'Unknown account')
		},

		selectConversation(id) {
			this.newMessageOpen = false
			this.$emit('select', id)
		},

		preview(status) {
			return htmlToPlainText(status?.content ?? '').replace(/\s+/g, ' ').trim()
		},

		formatTime(value) {
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			const now = new Date()
			return date.toDateString() === now.toDateString()
				? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
				: date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
		},

		isOutgoing(message) {
			const account = message?.account
			if (!this.currentUserId || !account) {
				return false
			}
			return account.acct === this.currentUserId
				|| (account.username === this.currentUserId && !String(account.acct ?? '').includes('@'))
		},

		async refreshSelectedConversation() {
			await this.loadConversations()
			if (this.selectedConversationId !== '') {
				await this.loadThread(this.selectedConversationId)
			}
		},

		async onNewMessagePosted() {
			this.newMessageOpen = false
			await this.loadConversations()
		},
	},
}
</script>

<style scoped>
.direct-messages {
	display: grid;
	grid-template-columns: minmax(19rem, 25rem) minmax(0, 1fr);
	min-height: clamp(34rem, calc(100dvh - 6rem), 68rem);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.direct-messages__list-panel,
.direct-messages__thread-panel {
	min-width: 0;
	display: flex;
	flex-direction: column;
}

.direct-messages__list-panel {
	border-inline-end: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.direct-messages__list-heading,
.direct-messages__thread-heading {
	display: flex;
	align-items: center;
	gap: 0.8rem;
	min-height: 5rem;
	padding: 0.9rem 1.15rem;
	border-bottom: 1px solid var(--color-border);
}

.direct-messages__list-heading {
	justify-content: space-between;
	background: var(--color-main-background);
}

.direct-messages__list-heading h2,
.direct-messages__thread-heading h2 {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 650;
}

.direct-messages__eyebrow {
	margin: 0 0 0.15rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.68rem;
	font-weight: 700;
	letter-spacing: 0.09em;
}

.direct-messages__search {
	display: block;
	padding: 0.75rem 0.9rem;
	background: var(--color-main-background);
}

.direct-messages__search input {
	box-sizing: border-box;
	width: 100%;
	min-height: 2.6rem;
	padding: 0 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.direct-messages__search input:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.direct-messages__filters {
	display: flex;
	gap: 0.4rem;
	padding: 0 0.9rem 0.75rem;
	background: var(--color-main-background);
}

.direct-messages__filters button {
	min-height: 2rem;
	padding: 0.2rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: transparent;
	color: var(--color-main-text);
	font: inherit;
	cursor: pointer;
}

.direct-messages__filters button:hover,
.direct-messages__filters .direct-messages__filter--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light, var(--color-background-hover));
}

.direct-messages__list {
	flex: 1;
	min-height: 0;
	list-style: none;
	margin: 0;
	padding: 0;
	overflow-y: auto;
	background: var(--color-main-background);
}

.direct-messages__conversation {
	display: flex;
	width: 100%;
	align-items: center;
	gap: 0.75rem;
	min-height: 5.2rem;
	padding: 0.75rem 0.9rem;
	border: 0;
	border-bottom: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.direct-messages__conversation:hover,
.direct-messages__conversation--selected {
	background: var(--color-background-hover);
}

.direct-messages__conversation--selected {
	box-shadow: inset 3px 0 var(--color-primary-element);
}

.direct-messages__conversation--unread .direct-messages__conversation-title {
	font-weight: 700;
}

.direct-messages__conversation-copy {
	display: grid;
	flex: 1;
	min-width: 0;
	gap: 0.35rem;
}

.direct-messages__conversation-title-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	min-width: 0;
}

.direct-messages__conversation-time {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
}

.direct-messages__conversation-title,
.direct-messages__preview {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.direct-messages__preview {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.direct-messages__unread-dot {
	flex: 0 0 auto;
	width: 0.55rem;
	height: 0.55rem;
	border-radius: 50%;
	background: var(--color-primary-element);
}

.direct-messages__state {
	margin: auto 0;
	padding: 1.5rem 1rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__thread-panel--empty {
	color: var(--color-text-maxcontrast);
	background: radial-gradient(ellipse at center, var(--color-background-hover), var(--color-main-background) 68%);
}

.direct-messages__thread-heading {
	background: var(--color-main-background);
}

.direct-messages__thread-person {
	min-width: 0;
}

.direct-messages__thread-person p {
	margin: 0.15rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.direct-messages__thread {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 0.7rem;
	min-height: 0;
	padding: 1.2rem clamp(1rem, 4vw, 3rem);
	overflow-y: auto;
	background: var(--color-background-dark);
}

.direct-messages__message {
	width: min(100%, 54rem);
	align-self: flex-start;
}

.direct-messages__message--outgoing {
	align-self: flex-end;
}

.direct-messages__thread :deep(.timeline-entry) {
	max-width: 100%;
	border-radius: var(--border-radius-large);
}

.direct-messages__message--outgoing :deep(.timeline-entry) {
	border-inline-start: 3px solid var(--color-primary-element);
}

.direct-messages__composer {
	border-top: 1px solid var(--color-border);
	min-height: 12rem;
	max-height: 45vh;
	overflow-y: auto;
}

.direct-messages__new-message-composer {
	flex: 1;
	min-height: 0;
	padding: 1rem;
	overflow-y: auto;
}

.direct-messages__back {
	display: none;
}

.direct-messages__welcome {
	max-width: 27rem;
	margin: auto;
	padding: 2rem;
	text-align: center;
}

.direct-messages__welcome-mark {
	display: grid;
	width: 4rem;
	height: 4rem;
	margin: 0 auto 1.25rem;
	place-items: center;
	border: 1px solid var(--color-border);
	border-radius: 50%;
	background: var(--color-main-background);
	color: var(--color-primary-element);
	font-size: 1.8rem;
}

.direct-messages__welcome h2 {
	margin: 0 0 0.5rem;
	color: var(--color-main-text);
	font-size: 1.35rem;
}

.direct-messages__welcome p {
	margin: 0 0 1.25rem;
	line-height: 1.55;
}

@media (max-width: 700px) {
	.direct-messages {
		grid-template-columns: minmax(0, 1fr);
		min-height: clamp(32rem, calc(100dvh - 5rem), 58rem);
	}

	.direct-messages__list-panel {
		border-inline-end: 0;
	}

	.direct-messages--selected .direct-messages__list-panel,
	.direct-messages:not(.direct-messages--selected) .direct-messages__thread-panel {
		display: none;
	}

	.direct-messages__back {
		display: inline-flex;
	}

	.direct-messages__thread-heading {
		padding-inline: 0.75rem;
	}

	.direct-messages__new-button {
		max-width: 8rem;
	}
}
</style>
