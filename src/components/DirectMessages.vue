<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="direct-messages" :class="{ 'direct-messages--selected': selectedConversationId !== '' }">
		<aside class="direct-messages__list-panel" :aria-label="t('social', 'Direct message conversations')">
			<div class="direct-messages__list-heading">
				<h2>{{ t('social', 'Conversations') }}</h2>
				<NcButton variant="primary" @click="newMessageOpen = !newMessageOpen">
					{{ t('social', 'New message') }}
				</NcButton>
			</div>

			<div v-if="newMessageOpen" class="direct-messages__new-message">
				<Composer defaultVisibility="direct" />
			</div>

			<p v-if="loadingList" class="direct-messages__state" role="status">
				{{ t('social', 'Loading conversations…') }}
			</p>
			<p v-else-if="listError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not load conversations') }}
			</p>
			<p v-else-if="conversations.length === 0" class="direct-messages__state">
				{{ t('social', 'No direct conversations yet') }}
			</p>

			<ul v-else class="direct-messages__list">
				<li v-for="conversation in conversations" :key="conversation.id">
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
							<span class="direct-messages__conversation-title">
								{{ conversationName(conversation) }}
								<span v-if="conversation.unread" class="direct-messages__unread">
									{{ t('social', 'Unread') }}
								</span>
							</span>
							<span class="direct-messages__preview">{{ preview(conversation.last_status) }}</span>
						</span>
					</button>
				</li>
			</ul>
		</aside>

		<section v-if="activeConversation" class="direct-messages__thread-panel" :aria-label="threadLabel">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="$emit('select', '')">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<h2>{{ conversationName(activeConversation) }}</h2>
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
				<TimelineEntry
					v-for="message in messages"
					:key="message.id"
					:item="message"
					type="direct" />
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
			<p>{{ t('social', 'Choose a conversation to read') }}</p>
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

		async refreshSelectedConversation() {
			await this.loadConversations()
			if (this.selectedConversationId !== '') {
				await this.loadThread(this.selectedConversationId)
			}
		},
	},
}
</script>

<style scoped>
.direct-messages {
	display: grid;
	grid-template-columns: minmax(17rem, 0.8fr) minmax(0, 1.4fr);
	min-height: 34rem;
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
	border-right: 1px solid var(--color-border);
}

.direct-messages__list-heading,
.direct-messages__thread-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	padding: 0.75rem 1rem;
	border-bottom: 1px solid var(--color-border);
}

.direct-messages__list-heading h2,
.direct-messages__thread-heading h2 {
	margin: 0;
	font-size: 1rem;
}

.direct-messages__list {
	list-style: none;
	margin: 0;
	padding: 0;
	overflow-y: auto;
}

.direct-messages__conversation {
	display: flex;
	width: 100%;
	align-items: center;
	gap: 0.75rem;
	padding: 0.8rem 1rem;
	border: 0;
	border-bottom: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	text-align: left;
	cursor: pointer;
}

.direct-messages__conversation:hover,
.direct-messages__conversation--selected {
	background: var(--color-background-hover);
}

.direct-messages__conversation--unread .direct-messages__conversation-title {
	font-weight: 700;
}

.direct-messages__conversation-copy {
	display: grid;
	min-width: 0;
	gap: 0.25rem;
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

.direct-messages__unread {
	margin-left: 0.4rem;
	color: var(--color-primary-element);
	font-size: 0.8rem;
}

.direct-messages__state {
	margin: auto;
	padding: 1rem;
	text-align: center;
}

.direct-messages__thread-panel--empty {
	color: var(--color-text-maxcontrast);
}

.direct-messages__thread {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 0.5rem;
	min-height: 0;
	padding: 1rem;
	overflow-y: auto;
}

.direct-messages__thread :deep(.timeline-entry) {
	max-width: 100%;
}

.direct-messages__composer {
	border-top: 1px solid var(--color-border);
}

.direct-messages__back {
	display: none;
}

@media (max-width: 700px) {
	.direct-messages {
		grid-template-columns: minmax(0, 1fr);
		min-height: 28rem;
	}

	.direct-messages__list-panel {
		border-right: 0;
	}

	.direct-messages--selected .direct-messages__list-panel,
	.direct-messages:not(.direct-messages--selected) .direct-messages__thread-panel {
		display: none;
	}

	.direct-messages__back {
		display: inline-flex;
	}
}
</style>
