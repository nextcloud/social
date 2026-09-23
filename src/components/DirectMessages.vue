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
			<div v-else-if="conversations.length === 0" class="direct-messages__inbox-empty">
				<MessageOutline :size="26" aria-hidden="true" />
				<strong>{{ t('social', 'No direct conversations yet') }}</strong>
				<p>{{ t('social', 'Your private conversations will appear here.') }}</p>
				<NcButton variant="tertiary" @click="newMessageOpen = true">
					{{ t('social', 'Start a conversation') }}
				</NcButton>
			</div>
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
			<div v-if="!newRecipient" class="direct-messages__recipient-picker">
				<label class="direct-messages__recipient-search">
					<span>{{ t('social', 'To') }}</span>
					<input
						v-model="recipientQuery"
						type="search"
						autocomplete="off"
						:placeholder="t('social', 'Search for a person by name or @username')">
				</label>
				<p v-if="searchingAccounts" class="direct-messages__state" role="status">
					{{ t('social', 'Searching…') }}
				</p>
				<ul v-else-if="accountResults.length" class="direct-messages__recipient-results">
					<li v-for="account in accountResults" :key="account.id || account.acct">
						<button type="button" class="direct-messages__recipient-option" @click="startConversation(account)">
							<ActorAvatar :actor="account" :size="40" :link="false" />
							<span><strong>{{ account.display_name || account.username }}</strong><small>@{{ account.acct }}</small></span>
						</button>
					</li>
				</ul>
				<p v-else-if="recipientQuery.trim().length >= 2" class="direct-messages__state">
					{{ t('social', 'No people found') }}
				</p>
			</div>
			<div v-else class="direct-messages__new-chat">
				<div class="direct-messages__chosen-recipient">
					<ActorAvatar :actor="newRecipient" :size="40" :link="false" />
					<span><strong>{{ newRecipient.display_name || newRecipient.username }}</strong><small>@{{ newRecipient.acct }}</small></span>
					<NcButton variant="tertiary" @click="newRecipient = null">
						{{ t('social', 'Change person') }}
					</NcButton>
				</div>
				<div class="direct-messages__new-chat-spacer" />
				<form class="direct-messages__message-form" @submit.prevent="sendMessage">
					<textarea
						v-model="messageText"
						rows="2"
						:disabled="sendingMessage"
						:placeholder="t('social', 'Write a message…')"
						:aria-label="t('social', 'Write a message…')" />
					<NcButton variant="primary" type="submit" :disabled="sendingMessage || !messageText.trim()">
						{{ t('social', 'Send') }}
					</NcButton>
				</form>
				<p v-if="sendError" class="direct-messages__send-error" role="alert">
					{{ t('social', 'Could not send the message. Please try again.') }}
				</p>
			</div>
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
					v-for="(message, index) in messages"
					:key="message.id"
					class="direct-messages__message"
					:class="{
						'direct-messages__message--outgoing': isOutgoing(message),
						'direct-messages__message--grouped': !showMessageAuthor(message, index),
					}">
					<TimelineEntry
						:item="message"
						type="direct"
						element="article"
						:hideAvatar="!showMessageAuthor(message, index)"
						:hideAuthor="!showMessageAuthor(message, index)" />
				</div>
				<p v-if="messages.length === 0" class="direct-messages__state">
					{{ t('social', 'No messages in this conversation') }}
				</p>
			</div>

			<form class="direct-messages__message-form" @submit.prevent="sendMessage">
				<textarea
					v-model="messageText"
					rows="2"
					:disabled="sendingMessage"
					:placeholder="t('social', 'Write a message…')"
					:aria-label="t('social', 'Write a message…')" />
				<NcButton variant="primary" type="submit" :disabled="sendingMessage || !messageText.trim()">
					{{ t('social', 'Send') }}
				</NcButton>
			</form>
			<p v-if="sendError" class="direct-messages__send-error" role="alert">
				{{ t('social', 'Could not send the message. Please try again.') }}
			</p>
		</section>

		<section v-else class="direct-messages__thread-panel direct-messages__thread-panel--empty">
			<div class="direct-messages__welcome">
				<div class="direct-messages__welcome-mark" aria-hidden="true">
					<MessageOutline :size="30" />
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
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import TimelineEntry from './TimelineEntry.vue'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'

export default {
	name: 'DirectMessages',
	components: {
		ActorAvatar,
		MessageOutline,
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
			recipientQuery: '',
			accountResults: [],
			searchingAccounts: false,
			newRecipient: null,
			messageText: '',
			sendingMessage: false,
			sendError: false,
			accountSearchTimer: null,
			accountSearchRequest: 0,
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
		recipientQuery(query) {
			clearTimeout(this.accountSearchTimer)
			this.accountSearchRequest++
			this.accountResults = []
			if (query.trim().length < 2) {
				this.searchingAccounts = false
				return
			}
			this.searchingAccounts = true
			this.accountSearchTimer = setTimeout(() => this.searchAccounts(query.trim()), 250)
		},

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
				this.conversations = this.uniqueConversations(Array.isArray(data) ? data : [])
			} catch (error) {
				this.listError = true
				logger.error('Failed to load direct message conversations', { error })
			} finally {
				this.loadingList = false
			}
		},

		uniqueConversations(conversations) {
			const unique = new Map()
			for (const conversation of conversations) {
				const peer = (conversation.accounts ?? []).find((account) => account.acct !== this.currentUserId && account.username !== this.currentUserId)
				const key = peer?.id || peer?.acct || `conversation:${conversation.id}`
				if (!unique.has(String(key))) {
					unique.set(String(key), conversation)
				}
			}
			return [...unique.values()]
		},

		async searchAccounts(query) {
			const request = ++this.accountSearchRequest
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: query } })
				if (request !== this.accountSearchRequest) {
					return
				}
				const accounts = [...(data?.accounts ?? []), ...(data?.exact ? [data.exact] : [])]
				const seen = new Set()
				this.accountResults = accounts.filter((account) => {
					const key = String(account.id || account.acct).toLocaleLowerCase()
					if (!account.acct || seen.has(key) || account.acct === this.currentUserId || account.username === this.currentUserId) {
						return false
					}
					seen.add(key)
					return true
				}).slice(0, 8)
			} catch (error) {
				if (request === this.accountSearchRequest) {
					logger.error('Failed to search for direct message recipients', { error })
				}
			} finally {
				if (request === this.accountSearchRequest) {
					this.searchingAccounts = false
				}
			}
		},

		startConversation(account) {
			const existing = this.conversations.find((conversation) => (conversation.accounts ?? []).some((candidate) => (candidate.id && account.id && String(candidate.id) === String(account.id)) || candidate.acct === account.acct))
			if (existing) {
				this.selectConversation(String(existing.id))
				return
			}
			this.newRecipient = account
			this.messageText = ''
			this.sendError = false
			this.accountResults = []
			this.recipientQuery = ''
		},

		async sendMessage() {
			const recipient = this.newRecipient || this.activeConversation?.accounts?.find((account) => account.acct !== this.currentUserId && account.username !== this.currentUserId)
			const text = this.messageText.trim()
			if (!recipient?.acct || !text || this.sendingMessage) {
				return
			}
			this.sendingMessage = true
			this.sendError = false
			try {
				const replyTo = this.activeConversation?.last_status?.id
				await axios.post(generateUrl('apps/social/api/v1/statuses'), {
					status: `@${recipient.acct} ${text}`,
					visibility: 'direct',
					...(replyTo ? { in_reply_to_id: replyTo } : {}),
				})
				this.messageText = ''
				await this.loadConversations()
				if (this.newRecipient) {
					const conversation = this.conversations.find((item) => (item.accounts ?? []).some((candidate) => candidate.acct === recipient.acct || (candidate.id && recipient.id && String(candidate.id) === String(recipient.id))))
					this.newRecipient = null
					this.newMessageOpen = false
					if (conversation) {
						this.$emit('select', String(conversation.id))
					}
				} else if (this.selectedConversationId) {
					await this.loadThread(this.selectedConversationId)
				}
			} catch (error) {
				this.sendError = true
				logger.error('Failed to send a direct message', { error })
			} finally {
				this.sendingMessage = false
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
			this.messageText = ''
			this.sendError = false
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

		showMessageAuthor(message, index) {
			if (index === 0) {
				return true
			}
			const current = message?.account?.id || message?.account?.acct
			const previous = this.messages[index - 1]?.account?.id || this.messages[index - 1]?.account?.acct
			return current !== previous
		},

	},
}
</script>

<style scoped>
.direct-messages {
	display: grid;
	width: 100%;
	grid-template-columns: minmax(20rem, 24rem) minmax(0, 1fr);
	min-height: clamp(36rem, calc(100dvh - 6rem), 72rem);
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
	background: var(--color-main-background);
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

.direct-messages__inbox-empty {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.6rem;
	margin: 1rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.direct-messages__inbox-empty strong {
	color: var(--color-main-text);
}

.direct-messages__inbox-empty p {
	margin: 0;
	line-height: 1.45;
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
	display: flex;
	width: fit-content;
	max-width: min(82%, 46rem);
	align-self: flex-start;
}

.direct-messages__message--outgoing {
	align-self: flex-end;
	margin-inline-start: auto;
}

.direct-messages__thread :deep(.timeline-entry) {
	width: 100%;
	max-width: 100%;
	margin: 0;
	padding: 0;
	border-radius: 0;
	animation: none;
}

.direct-messages__thread :deep(.wrapper) {
	width: 100%;
	gap: 0;
	padding: 0;
}

.direct-messages__thread :deep(.entry__content) {
	flex: 1 1 auto;
	min-width: 0;
}

.direct-messages__thread :deep(.post-content) {
	width: auto;
	max-width: 100%;
	padding: 0.65rem 0.9rem 0.45rem;
	border: 1px solid var(--color-border);
	border-radius: 0.25rem 1rem 1rem 1rem;
	background: var(--color-main-background);
	box-shadow: none;
	transition: none;
}

.direct-messages__thread :deep(.post-content:hover) {
	border-color: var(--color-border);
	box-shadow: none;
	transform: none;
}

.direct-messages__message--outgoing :deep(.post-content) {
	border-color: transparent;
	border-radius: 1rem 0.25rem 1rem 1rem;
	background: var(--color-primary-element-light, var(--color-background-hover));
}

.direct-messages__message--grouped {
	margin-block-start: -0.45rem;
}

.direct-messages__message--grouped :deep(.post-content) {
	padding-block-start: 0.65rem;
}

.direct-messages__message--outgoing :deep(.post-content) {
	border-start-end-radius: 0.25rem;
	border-end-start-radius: 1rem;
}

.direct-messages__message:not(.direct-messages__message--outgoing) :deep(.post-content) {
	border-start-start-radius: 0.25rem;
	border-end-end-radius: 1rem;
}

.direct-messages__composer {
	border-top: 1px solid var(--color-border);
	min-height: 12rem;
	max-height: 45vh;
	overflow-y: auto;
}

.direct-messages__recipient-picker {
	padding: 1.25rem;
}

.direct-messages__recipient-search {
	display: grid;
	gap: 0.45rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.direct-messages__recipient-search input,
.direct-messages__message-form textarea {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.direct-messages__recipient-search input {
	min-height: 2.75rem;
	padding: 0 0.85rem;
}

.direct-messages__recipient-results {
	list-style: none;
	margin: 0.75rem 0 0;
	padding: 0;
}

.direct-messages__recipient-option,
.direct-messages__chosen-recipient {
	display: flex;
	width: 100%;
	align-items: center;
	gap: 0.75rem;
	padding: 0.7rem;
	border: 0;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
}

.direct-messages__recipient-option {
	cursor: pointer;
}

.direct-messages__recipient-option:hover {
	background: var(--color-background-hover);
}

.direct-messages__recipient-option span,
.direct-messages__chosen-recipient span {
	display: grid;
	flex: 1;
	min-width: 0;
	gap: 0.15rem;
}

.direct-messages__recipient-option small,
.direct-messages__chosen-recipient small {
	color: var(--color-text-maxcontrast);
}

.direct-messages__new-chat {
	display: flex;
	flex: 1;
	min-height: 0;
	flex-direction: column;
}

.direct-messages__chosen-recipient {
	padding: 0.8rem 1rem;
	border-bottom: 1px solid var(--color-border);
}

.direct-messages__new-chat-spacer {
	flex: 1;
	background: radial-gradient(ellipse at center, var(--color-background-hover), var(--color-main-background) 68%);
}

.direct-messages__message-form {
	display: flex;
	align-items: flex-end;
	gap: 0.75rem;
	padding: 0.85rem 1rem;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.direct-messages__message-form textarea {
	min-height: 2.8rem;
	max-height: 10rem;
	resize: vertical;
	padding: 0.7rem 0.9rem;
}

.direct-messages__send-error {
	margin: 0;
	padding: 0 1rem 0.75rem;
	color: var(--color-error);
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

@media (prefers-reduced-motion: reduce) {
	.direct-messages__thread :deep(.timeline-entry),
	.direct-messages__thread :deep(.post-content) {
		animation: none;
		transition: none;
		scroll-behavior: auto;
	}
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

	.direct-messages__message {
		max-width: 94%;
	}

	.direct-messages__new-button {
		max-width: 8rem;
	}
}
</style>
