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
				<h2>{{ t('social', 'Messages') }}</h2>
				<NcButton
					variant="tertiary"
					class="direct-messages__new-button"
					:aria-label="t('social', 'New message')"
					@click="newMessageOpen = true">
					<template #icon>
						<MessagePlusOutline :size="20" />
					</template>
				</NcButton>
			</header>
			<div class="direct-messages__list-tools">
				<NcTextField
					v-model="searchQuery"
					class="direct-messages__search"
					:label="t('social', 'Search conversations')"
					:placeholder="t('social', 'Search conversations')"
					type="search" />
				<nav class="direct-messages__filters" :aria-label="t('social', 'Filter conversations')">
					<button
						type="button"
						:aria-pressed="filterMode === 'all'"
						:class="{ 'direct-messages__filter--active': filterMode === 'all' }"
						@click="filterMode = 'all'">
						{{ t('social', 'All') }}
					</button>
					<button
						type="button"
						:aria-pressed="filterMode === 'unread'"
						:class="{ 'direct-messages__filter--active': filterMode === 'unread' }"
						@click="filterMode = 'unread'">
						{{ t('social', 'Unread ({count})', { count: unreadCount }) }}
					</button>
				</nav>
			</div>

			<p v-if="loadingList" class="direct-messages__state" role="status">
				{{ t('social', 'Loading conversations…') }}
			</p>
			<p v-else-if="listError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not load conversations') }}
			</p>
			<p v-if="removeError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not remove conversation') }}
			</p>
			<div v-else-if="conversations.length === 0" class="direct-messages__inbox-empty">
				<MessageOutline :size="24" aria-hidden="true" />
				<strong>{{ t('social', 'No conversations yet') }}</strong>
				<NcButton class="direct-messages__mobile-start" variant="tertiary" @click="newMessageOpen = true">
					{{ t('social', 'Start a conversation') }}
				</NcButton>
			</div>
			<p v-else-if="filteredConversations.length === 0" class="direct-messages__state">
				{{ t('social', 'No conversations match your search') }}
			</p>

			<ul v-else class="direct-messages__list">
				<li v-for="conversation in filteredConversations" :key="conversation.id">
					<NcListItem
						class="direct-messages__conversation"
						:forceDisplayActions="true"
						:name="conversationName(conversation)"
						:details="formatTime(conversation.last_status?.created_at)"
						:active="String(conversation.id) === selectedConversationId"
						:bold="conversation.unread"
						:actionsAriaLabel="t('social', 'Conversation actions')"
						:linkAriaLabel="t('social', 'Conversation with {name}', { name: conversationName(conversation) })"
						@click="selectConversation(String(conversation.id), $event)">
						<template #icon>
							<ActorAvatar
								v-if="conversationPeer(conversation)"
								:actor="conversationPeer(conversation)"
								:size="40"
								:link="false"
								class="direct-messages__conversation-avatar" />
						</template>
						<template #subname>
							<span class="direct-messages__preview">{{ preview(conversation.last_status, conversation) || t('social', 'No messages yet') }}</span>
						</template>
						<template #indicator>
							<span v-if="conversation.unread" class="direct-messages__unread-dot" :aria-label="t('social', 'Unread')" />
						</template>
						<template #actions>
							<NcActionButton
								:closeAfterClick="true"
								:disabled="removingConversationId === String(conversation.id)"
								@click.stop="removeConversation(conversation)">
								<template #icon>
									<DeleteOutline :size="20" />
								</template>
								{{ t('social', 'Remove conversation') }}
							</NcActionButton>
						</template>
					</NcListItem>
				</li>
			</ul>
		</aside>

		<section v-if="newMessageOpen" class="direct-messages__thread-panel direct-messages__new-message-panel" :aria-label="t('social', 'New direct message')">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="newMessageOpen = false">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<ActorAvatar
					v-if="newRecipient"
					:actor="newRecipient"
					:size="40"
					:link="false" />
				<div class="direct-messages__thread-person">
					<h2>{{ newRecipient ? newRecipient.display_name || newRecipient.username || newRecipient.acct : t('social', 'New message') }}</h2>
					<p>{{ newRecipient ? t('social', 'Private conversation') : t('social', 'Choose a person to start a private chat') }}</p>
				</div>
				<NcButton
					v-if="newRecipient"
					variant="tertiary"
					class="direct-messages__change-person"
					@click="newRecipient = null">
					{{ t('social', 'Change person') }}
				</NcButton>
			</header>
			<div v-if="!newRecipient" class="direct-messages__recipient-picker">
				<div class="direct-messages__recipient-intro">
					<h3>{{ t('social', 'Who would you like to message?') }}</h3>
				</div>
				<NcTextField
					v-model="recipientQuery"
					class="direct-messages__recipient-search"
					:label="t('social', 'Search for a person by name or @username')"
					:placeholder="t('social', 'Search for a person by name or @username')"
					autocomplete="off"
					type="search" />
				<div class="direct-messages__people-heading">
					<h3>{{ recipientQuery.trim().length >= 2 ? t('social', 'Search results') : t('social', 'People you know') }}</h3>
					<span v-if="searchingAccounts || loadingSuggestions" role="status">{{ t('social', 'Searching…') }}</span>
				</div>
				<p v-if="searchError" class="direct-messages__state direct-messages__recipient-feedback" role="alert">
					{{ t('social', 'Could not search for people. Please try again.') }}
				</p>
				<ul v-if="visibleRecipients.length" class="direct-messages__recipient-results">
					<li v-for="account in visibleRecipients" :key="account.id || account.acct">
						<NcListItem
							class="direct-messages__recipient-option"
							:name="account.display_name || account.username || account.acct"
							:linkAriaLabel="t('social', 'Start a conversation with {name}', { name: account.display_name || account.acct })"
							@click="startConversation(account, $event)">
							<template #icon>
								<ActorAvatar :actor="account" :size="40" :link="false" />
							</template>
							<template #subname>
								@{{ account.acct }}
							</template>
						</NcListItem>
					</li>
				</ul>
				<p v-else-if="recipientQuery.trim().length >= 2 && !searchingAccounts && !searchError" class="direct-messages__state direct-messages__recipient-feedback">
					{{ t('social', 'No people found') }}
				</p>
				<p v-else-if="!loadingSuggestions && !visibleRecipients.length" class="direct-messages__state direct-messages__recipient-feedback">
					{{ t('social', 'Search for someone to start a conversation') }}
				</p>
			</div>
			<div v-else class="direct-messages__new-chat">
				<div class="direct-messages__new-chat-intro">
					<MessageOutline :size="36" aria-hidden="true" />
					<h3>{{ t('social', 'Start a conversation with {name}', { name: newRecipient.display_name || newRecipient.username || newRecipient.acct }) }}</h3>
					<p>{{ t('social', 'Private conversation') }}</p>
				</div>
				<form class="direct-messages__message-form" @submit.prevent="sendMessage">
					<NcTextArea
						v-model="messageText"
						class="direct-messages__message-input"
						:label="t('social', 'Write a message…')"
						:disabled="sendingMessage"
						:placeholder="t('social', 'Write a message…')"
						resize="vertical" />
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
					v-if="conversationPeer(activeConversation)"
					:actor="conversationPeer(activeConversation)"
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
					class="direct-messages__message-row"
					:class="{
						'direct-messages__message--outgoing': isOutgoing(message),
						'direct-messages__message--grouped': !showMessageAuthor(message, index),
					}">
					<time v-if="showDaySeparator(message, index)" class="direct-messages__day" :datetime="message.created_at">
						{{ formatDay(message.created_at) }}
					</time>
					<div class="direct-messages__message">
						<TimelineEntry
							:item="messageForDisplay(message)"
							type="direct"
							element="article"
							:hideAvatar="true"
							:hideAuthor="true" />
						<time class="direct-messages__message-time" :datetime="message.created_at" :title="formatDay(message.created_at)">
							{{ formatMessageTime(message.created_at) }}
						</time>
					</div>
				</div>
				<p v-if="messages.length === 0" class="direct-messages__state">
					{{ t('social', 'No messages in this conversation') }}
				</p>
			</div>

			<form class="direct-messages__message-form" @submit.prevent="sendMessage">
				<NcTextArea
					v-model="messageText"
					class="direct-messages__message-input"
					:label="t('social', 'Write a message…')"
					:disabled="sendingMessage"
					:placeholder="t('social', 'Write a message…')"
					resize="vertical" />
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
					<MessageOutline :size="32" />
				</div>
				<h2>{{ t('social', 'Start a private chat') }}</h2>
				<p>{{ t('social', 'Choose a conversation or find someone to message.') }}</p>
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
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import axios from '@nextcloud/axios'
import ActorAvatar from './ActorAvatar.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import TimelineEntry from './TimelineEntry.vue'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'

export default {
	name: 'DirectMessages',
	components: {
		ActorAvatar,
		MessageOutline,
		MessagePlusOutline,
		DeleteOutline,
		NcActionButton,
		NcButton,
		NcListItem,
		NcTextArea,
		NcTextField,
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
			suggestedAccounts: [],
			loadingSuggestions: false,
			searchingAccounts: false,
			searchError: false,
			newRecipient: null,
			messageText: '',
			sendingMessage: false,
			sendError: false,
			accountSearchTimer: null,
			accountSearchRequest: 0,
			suggestionsRequest: 0,
			threadRequest: 0,
			removingConversationId: '',
			removeError: false,
		}
	},

	computed: {
		filteredConversations() {
			const query = this.searchQuery.trim().toLocaleLowerCase()
			return this.conversations.filter((conversation) => (this.filterMode !== 'unread' || conversation.unread)
				&& (!query || this.conversationName(conversation).toLocaleLowerCase().includes(query)
					|| this.preview(conversation.last_status, conversation).toLocaleLowerCase().includes(query)))
		},

		unreadCount() {
			return this.conversations.filter((conversation) => conversation.unread).length
		},

		activeConversation() {
			return this.conversations.find((conversation) => String(conversation.id) === this.selectedConversationId) ?? null
		},

		visibleRecipients() {
			const query = this.recipientQuery.trim().replace(/^@/, '').toLocaleLowerCase()
			const seen = new Set()
			return [
				...(query.length >= 2 ? this.accountResults : []),
				...this.conversations.flatMap((conversation) => conversation.accounts ?? []),
				...this.suggestedAccounts,
			].filter((account) => {
				const key = String(account?.id || account?.acct || '').toLocaleLowerCase()
				const name = String(account?.display_name || account?.username || '').toLocaleLowerCase()
				if (!account?.acct || !key || seen.has(key) || this.isOwnAccount(account)
					|| (query && !name.includes(query) && !String(account.acct).toLocaleLowerCase().includes(query))) {
					return false
				}
				seen.add(key)
				return true
			}).slice(0, 12)
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
		newMessageOpen(open) {
			if (open) {
				this.newRecipient = null
				this.recipientQuery = ''
				this.searchError = false
				this.loadSuggestedAccounts()
			} else {
				clearTimeout(this.accountSearchTimer)
				this.accountSearchRequest++
				this.suggestionsRequest++
				this.searchingAccounts = false
			}
		},

		recipientQuery(query) {
			clearTimeout(this.accountSearchTimer)
			this.accountSearchRequest++
			this.accountResults = []
			this.searchError = false
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
		isOwnAccount(account) {
			return account.acct === this.currentUserId
				|| (account.username === this.currentUserId && !String(account.acct ?? '').includes('@'))
		},

		async loadSuggestedAccounts() {
			const request = ++this.suggestionsRequest
			this.loadingSuggestions = true
			try {
				const { data: me } = await axios.get(generateUrl('apps/social/api/v1/accounts/verify_credentials'))
				if (!me?.id || !this.newMessageOpen || request !== this.suggestionsRequest) {
					return
				}
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(String(me.id))}/following`), { params: { limit: 20 } })
				if (this.newMessageOpen && request === this.suggestionsRequest) {
					this.suggestedAccounts = Array.isArray(data) ? data : []
				}
			} catch (error) {
				logger.error('Failed to load suggested direct message recipients', { error })
			} finally {
				if (request === this.suggestionsRequest) {
					this.loadingSuggestions = false
				}
			}
		},

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
				const { data } = await axios.get(generateUrl('apps/social/api/v1/accounts/search'), { params: { q: query, limit: 8, resolve: query.startsWith('@') } })
				if (request !== this.accountSearchRequest) {
					return
				}
				const accounts = Array.isArray(data) ? data : []
				const seen = new Set()
				this.accountResults = accounts.filter((account) => {
					const key = String(account.id || account.acct).toLocaleLowerCase()
					if (!account.acct || seen.has(key) || this.isOwnAccount(account)) {
						return false
					}
					seen.add(key)
					return true
				}).slice(0, 8)
			} catch (error) {
				if (request === this.accountSearchRequest) {
					this.searchError = true
					logger.error('Failed to search for direct message recipients', { error })
				}
			} finally {
				if (request === this.accountSearchRequest) {
					this.searchingAccounts = false
				}
			}
		},

		startConversation(account, event) {
			event?.preventDefault?.()
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
			const peer = this.conversationPeer(conversation)
			return peer?.display_name || peer?.acct || peer?.username || t('social', 'Unknown account')
		},

		conversationPeer(conversation) {
			return (conversation?.accounts ?? []).find((account) => account.acct !== this.currentUserId && account.username !== this.currentUserId) ?? conversation?.accounts?.[0] ?? null
		},

		selectConversation(id, event) {
			event?.preventDefault?.()
			this.newMessageOpen = false
			this.messageText = ''
			this.sendError = false
			this.$emit('select', id)
		},

		preview(status, conversation = null) {
			return htmlToPlainText(this.withoutProtocolRecipient(status, this.conversationPeer(conversation))).replace(/\s+/g, ' ').trim()
		},

		/**
		 * Direct posts carry a leading account mention for ActivityPub delivery.
		 * It is routing metadata in this view; the chat header already identifies
		 * the peer, so repeating that mention in every bubble is noise.
		 *
		 * @param {object} message Direct message status.
		 * @return {object} the original message unless a leading protocol mention was removed
		 */
		messageForDisplay(message) {
			const content = this.withoutProtocolRecipient(message, this.conversationPeer(this.activeConversation))
			return content === message.content ? message : { ...message, content }
		},

		/**
		 * Remove only the first ActivityPub h-card at the start of a direct
		 * message. Other mentions in the message body keep their meaning.
		 *
		 * @param {object} message Direct message status.
		 * @param {object|null} recipient Conversation partner whose routing mention is hidden.
		 * @return {string}
		 */
		withoutProtocolRecipient(message, recipient = null) {
			const content = message?.content ?? ''
			if (message?.visibility !== 'direct' || !content || typeof document === 'undefined') {
				return content
			}

			const wrapper = document.createElement('div')
			wrapper.innerHTML = content
			const paragraph = wrapper.firstElementChild?.tagName === 'P' ? wrapper.firstElementChild : wrapper
			let first = paragraph.firstChild
			while (first?.nodeType === Node.TEXT_NODE && !first.textContent.trim()) {
				first = first.nextSibling
			}
			if (first?.nodeType === Node.TEXT_NODE) {
				const leadingMention = first.textContent.match(/^(\s*)@([\w.-]+(?:@[\w.-]+)?)(?=\s|$)/u)
				if (!leadingMention || !this.isProtocolRecipient(leadingMention[2], recipient)) {
					return content
				}
				first.textContent = first.textContent.slice(leadingMention[0].length).replace(/^\s+/, '')
				if (!first.textContent.trim()) {
					first.remove()
				}
				return wrapper.innerHTML
			}
			if (first?.nodeType !== Node.ELEMENT_NODE || !first.matches('.h-card, .mention, a.mention, span.mention')) {
				return content
			}
			const linkedAccount = first.querySelector('a[href]')?.getAttribute('href') ?? first.getAttribute('href') ?? ''
			const visibleMention = first.textContent.replace(/^@/, '').trim()
			if (!this.isProtocolRecipient(visibleMention, recipient, linkedAccount) && !first.matches('.h-card')) {
				return content
			}

			const next = first.nextSibling
			first.remove()
			if (next?.nodeType === Node.TEXT_NODE) {
				next.textContent = next.textContent.replace(/^\s+/, '')
			}
			if (paragraph !== wrapper && !paragraph.textContent.trim() && !paragraph.children.length) {
				paragraph.remove()
			}

			return wrapper.innerHTML
		},

		isProtocolRecipient(mention, recipient, href = '') {
			if (!recipient) {
				return true
			}
			const values = [recipient.acct, recipient.username, recipient.preferred_username].filter(Boolean).map((value) => String(value).replace(/^@/, '').toLocaleLowerCase())
			const normalizedMention = String(mention).replace(/^@/, '').toLocaleLowerCase()
			return values.includes(normalizedMention) || (href && values.some((value) => href.toLocaleLowerCase().includes(value)))
		},

		async removeConversation(conversation) {
			const id = String(conversation.id)
			this.removingConversationId = id
			this.removeError = false
			try {
				await axios.delete(generateUrl(`/apps/social/api/v1/conversations/${encodeURIComponent(id)}`))
				this.conversations = this.conversations.filter((item) => String(item.id) !== id)
				if (this.selectedConversationId === id) {
					this.$emit('select', '')
				}
			} catch (error) {
				logger.error('Could not remove direct message conversation', { error, id })
				this.removeError = true
			} finally {
				this.removingConversationId = ''
			}
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

		formatDay(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined, { dateStyle: 'medium' })
		},

		formatMessageTime(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
		},

		showDaySeparator(message, index) {
			if (index === 0) {
				return true
			}
			const current = new Date(message.created_at)
			const previous = new Date(this.messages[index - 1]?.created_at)
			return current.toDateString() !== previous.toDateString()
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
	grid-template-columns: clamp(17.5rem, 23vw, 20rem) minmax(0, 1fr);
	width: 100%;
	height: calc(100dvh - var(--header-height, 50px) - 0.6rem);
	min-height: 36rem;
	background: var(--color-main-background);
}

.direct-messages__list-panel,
.direct-messages__thread-panel {
	display: flex;
	min-width: 0;
	min-height: 0;
	flex-direction: column;
}

.direct-messages__list-panel {
	border-inline-end: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.direct-messages__list-heading,
.direct-messages__thread-heading {
	display: flex;
	min-height: 4.75rem;
	align-items: center;
	gap: 0.8rem;
	padding: 0.75rem 1.25rem;
	border-bottom: 1px solid var(--color-border);
}

.direct-messages__list-heading {
	justify-content: space-between;
	padding-inline-start: 3.5rem;
}

.direct-messages__list-heading h2,
.direct-messages__thread-heading h2 {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 650;
}

.direct-messages__new-button {
	flex: 0 0 auto;
}

.direct-messages__list-tools {
	padding: 0.8rem 1rem 0;
}

.direct-messages__search {
	display: block;
}

.direct-messages__search :deep(.input-field__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 2.3rem;
	padding-block: 0.4rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.direct-messages__search :deep(input) {
	min-height: 2.3rem;
	padding-block: 0.4rem;
}

.direct-messages__filters {
	display: flex;
	width: fit-content;
	gap: 0.2rem;
	margin-block-start: 0.5rem;
	padding: 0.2rem;
	border-radius: 999px;
	background: var(--color-background-hover);
}

.direct-messages__filters button {
	min-height: 1.9rem;
	padding: 0.25rem 0.7rem;
	border: 0;
	border-radius: 999px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font: inherit;
	font-size: 0.82rem;
	font-weight: 600;
	line-height: 1.2;
	cursor: pointer;
}

.direct-messages__filters button:hover,
.direct-messages__filters .direct-messages__filter--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 3px var(--color-box-shadow);
}

.direct-messages__filters button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.direct-messages__list {
	flex: 1;
	min-height: 0;
	margin: 0;
	padding: 0.35rem 0;
	list-style: none;
	overflow-y: auto;
}

.direct-messages :deep(.direct-messages__conversation.list-item__wrapper) {
	padding: 0;
}

.direct-messages :deep(.direct-messages__conversation .list-item__anchor) {
	min-height: 4.75rem;
	padding: 0.6rem 1rem;
	border-radius: 0;
}

.direct-messages__preview {
	max-width: 100%;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 0.86rem;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.direct-messages__unread-dot {
	width: 0.5rem;
	height: 0.5rem;
	border-radius: 50%;
	background: var(--color-primary-element);
}

.direct-messages__state {
	margin: auto 0;
	padding: 1.5rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__inbox-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.65rem;
	padding: 2.5rem 1rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__inbox-empty strong {
	font-size: 0.9rem;
	font-weight: 500;
}

.direct-messages__mobile-start {
	display: none;
}

.direct-messages__thread-heading {
	flex: 0 0 auto;
}

.direct-messages__thread-person {
	min-width: 0;
}

.direct-messages__thread-person h2 {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.direct-messages__thread-person p {
	margin: 0.1rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.82rem;
}

.direct-messages__thread {
	display: flex;
	flex: 1;
	min-height: 0;
	flex-direction: column;
	gap: 0.9rem;
	padding: 1.5rem clamp(1.25rem, 5vw, 4rem);
	overflow-y: auto;
	background: var(--color-main-background);
}

.direct-messages__message-row {
	display: flex;
	width: 100%;
	flex-direction: column;
	align-items: flex-start;
}

.direct-messages__message--grouped {
	margin-block-start: -0.55rem;
}

.direct-messages__message {
	display: flex;
	width: fit-content;
	max-width: min(76%, 42rem);
	flex-direction: column;
	align-items: flex-start;
}

.direct-messages__message--outgoing .direct-messages__message {
	align-self: flex-end;
	align-items: flex-end;
}

.direct-messages__day {
	align-self: center;
	margin: 0.5rem 0 1.4rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
}

.direct-messages__message-time {
	margin: 0.2rem 0.4rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.72rem;
	line-height: 1.25;
}

.direct-messages__thread :deep(.timeline-entry),
.direct-messages__thread :deep(.wrapper),
.direct-messages__thread :deep(.entry__content) {
	width: 100%;
	max-width: 100%;
	margin: 0;
	padding: 0;
	gap: 0;
	border: 0;
	animation: none;
}

.direct-messages__thread :deep(.post-content) {
	width: 100%;
	max-width: 100%;
	padding: 0.7rem 1rem;
	border: 0;
	border-radius: 1rem 1rem 1rem 0.3rem;
	background: var(--color-background-hover);
	box-shadow: none;
	font-size: 0.94rem;
	line-height: 1.45;
	transition: none;
}

.direct-messages__thread :deep(.post-content:hover),
.direct-messages__thread :deep(.post-content:focus-within) {
	border: 0;
	box-shadow: none;
	transform: none;
}

.direct-messages__message--outgoing :deep(.post-content) {
	border-radius: 1rem 1rem 0.3rem 1rem;
	background: var(--color-primary-element-light);
}

.direct-messages__thread :deep(.post-header) {
	display: none;
}

.direct-messages__thread :deep(.post-message) {
	margin: 0;
}

.direct-messages__recipient-picker {
	flex: 1;
	min-height: 0;
	padding: clamp(1.5rem, 4vw, 3rem) clamp(1.25rem, 5vw, 4rem);
	overflow-y: auto;
}

.direct-messages__recipient-intro {
	max-width: 42rem;
	margin: 0 auto 1.5rem;
}

.direct-messages__recipient-intro h3 {
	margin: 0 0 0.25rem;
	color: var(--color-main-text);
	font-size: 1.25rem;
	font-weight: 650;
}

.direct-messages__recipient-search {
	display: block;
	max-width: 42rem;
	margin: 0 auto;
}

.direct-messages__recipient-search :deep(.input-field__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 3rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.direct-messages__people-heading {
	display: flex;
	max-width: 42rem;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	margin: 2rem auto 0.5rem;
	padding-inline: 0.5rem;
	color: var(--color-text-maxcontrast);
}

.direct-messages__people-heading h3 {
	margin: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
	font-weight: 650;
}

.direct-messages__people-heading span {
	font-size: 0.8rem;
}

.direct-messages__recipient-results {
	max-width: 42rem;
	margin: 0 auto;
	padding: 0;
	list-style: none;
}

.direct-messages :deep(.direct-messages__recipient-option.list-item__wrapper) {
	padding: 0;
}

.direct-messages :deep(.direct-messages__recipient-option .list-item__anchor) {
	min-height: 4.5rem;
	padding: 0.6rem 0.75rem;
	border-radius: var(--border-radius-large);
}

.direct-messages__recipient-feedback {
	max-width: 42rem;
	margin: 0 auto;
	padding: 1.25rem 0.5rem;
	text-align: start;
}

.direct-messages__new-chat {
	display: flex;
	flex: 1;
	min-height: 0;
	flex-direction: column;
}

.direct-messages__change-person {
	margin-inline-start: auto;
}

.direct-messages__new-chat-intro {
	display: flex;
	flex: 1;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.45rem;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__new-chat-intro h3 {
	margin: 0.75rem 0 0;
	color: var(--color-main-text);
	font-size: 1.2rem;
}

.direct-messages__new-chat-intro p {
	margin: 0;
}

.direct-messages__message-form {
	display: flex;
	align-items: flex-end;
	gap: 0.75rem;
	padding: 0.85rem clamp(1rem, 3vw, 2rem);
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.direct-messages__message-input {
	flex: 1;
	min-width: 0;
}

.direct-messages__message-form :deep(.textarea__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 2.3rem;
	max-height: 7rem;
	padding: 0.45rem 0.75rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	resize: vertical;
}

.direct-messages__send-error {
	margin: 0;
	padding: 0 1rem 0.75rem;
	color: var(--color-error);
}

.direct-messages__back {
	display: none;
}

.direct-messages__thread-panel--empty {
	color: var(--color-text-maxcontrast);
}

.direct-messages__welcome {
	max-width: 28rem;
	margin: auto;
	padding: 2rem 1.5rem;
	text-align: center;
}

.direct-messages__welcome-mark {
	margin: 0 auto 1rem;
	color: var(--color-primary-element);
}

.direct-messages__welcome h2 {
	margin: 0 0 0.5rem;
	color: var(--color-main-text);
	font-size: 1.3rem;
}

.direct-messages__welcome p {
	margin: 0 0 1.25rem;
	line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
	.direct-messages__thread :deep(.timeline-entry),
	.direct-messages__thread :deep(.post-content) {
		animation: none;
		transition: none;
		scroll-behavior: auto;
	}
}

@media (max-width: 980px) {
	.direct-messages {
		grid-template-columns: minmax(0, 1fr);
		min-height: calc(100dvh - var(--header-height, 50px) - 0.6rem);
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

	.direct-messages__mobile-start {
		display: inline-flex;
	}

	.direct-messages__thread-heading {
		padding-inline: 0.75rem;
	}

	.direct-messages__message {
		max-width: 88%;
	}
}
</style>
