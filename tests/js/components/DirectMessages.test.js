/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import DirectMessages from '../../../src/components/DirectMessages.vue'

const bob = { id: 'https://remote.example/users/bob', acct: 'bob@remote.example', display_name: 'Bob' }
const latest = { id: '11', content: '<p>Latest reply</p>', created_at: '2026-09-23T12:00:00Z', account: bob }
const conversation = { id: '10', unread: true, accounts: [bob], last_status: latest }
const context = {
	ancestors: [{ id: '9', content: '<p>Earlier message</p>', created_at: '2026-09-22T11:00:00Z', account: bob }],
	descendants: [{ id: '12', content: '<p>Next message</p>', created_at: '2026-09-23T12:10:00Z', account: { acct: 'alice', username: 'alice' } }],
}

const stubs = {
	ActorAvatar: { props: ['actor', 'size', 'link'], template: '<span class="avatar-stub">{{ actor.display_name }}</span>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcActionButton: { template: '<button v-bind="$attrs"><slot name="icon" /><slot /></button>' },
	NcListItem: {
		props: ['name', 'details', 'active', 'bold', 'linkAriaLabel'],
		template: '<button v-bind="$attrs" class="native-list-item-stub"><slot name="icon" /><span>{{ name }}</span><slot name="subname" /><slot name="indicator" /><span class="native-actions"><slot name="actions" /></span></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'type'],
		emits: ['update:modelValue'],
		template: '<label><span>{{ label }}</span><input :value="modelValue" :placeholder="placeholder" :type="type" @input="$emit(\'update:modelValue\', $event.target.value)"></label>',
	},
	NcTextArea: {
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<label><span>{{ label }}</span><textarea :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
	},
	TimelineEntry: { props: ['item', 'type', 'hideAuthor', 'hideAvatar'], template: '<article class="message-stub" :data-id="item.id" :data-hide-author="hideAuthor" :data-hide-avatar="hideAvatar">{{ item.content }}</article>' },
}

function mountMessages(selectedConversationId = '') {
	return mount(DirectMessages, {
		props: { selectedConversationId },
		global: { stubs },
	})
}

describe('DirectMessages', () => {
	let get
	let post
	let remove

	beforeEach(() => {
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [structuredClone(conversation)] }
			}
			return { data: context }
		})
		post = vi.spyOn(axios, 'post').mockResolvedValue({ data: conversation })
		remove = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads and previews conversations from the Mastodon-compatible endpoint', async () => {
		const wrapper = mountMessages()
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations', { params: { limit: 40 } })
		expect(wrapper.find('.direct-messages__list').text()).toContain('Bob')
		expect(wrapper.find('.direct-messages__preview').text()).toBe('Latest reply')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})

	it('emits the selected conversation id for the route to remember', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__conversation').trigger('click')

		expect(wrapper.emitted('select')).toEqual([['10']])
	})

	it('opens a recipient search with no social visibility controls', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')

		expect(wrapper.find('.direct-messages__new-message-panel').exists()).toBe(true)
		expect(wrapper.find('.direct-messages__recipient-search input').exists()).toBe(true)
		expect(wrapper.find('.direct-messages__new-message-panel').text()).not.toContain('Public')
		expect(wrapper.find('.direct-messages__new-message-panel .composer-stub').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__list-panel .composer-stub').exists()).toBe(false)
	})

	it('filters the inbox by the other participant and latest message', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__search input').setValue('nobody')

		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__state').text()).toBe('No conversations match your search')

		await wrapper.find('.direct-messages__search input').setValue('latest')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(true)
	})

	it('filters unread conversations and updates the unread badge as they are read', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			{ id: '20', unread: false, accounts: [{ ...bob, id: 'friend', display_name: 'Charlie' }], last_status: { id: '21', content: '<p>Older</p>', created_at: '2026-09-22T09:00:00Z' } },
		] })
		const wrapper = mountMessages()
		await flushPromises()
		expect(wrapper.find('.direct-messages__filters').text()).toContain('Unread (1)')
		await wrapper.findAll('.direct-messages__filters button')[1].trigger('click')
		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
		expect(wrapper.find('.direct-messages__conversation').text()).toContain('Bob')
	})

	it('uses accessible selected state for the all and unread filters', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		const [all, unread] = wrapper.findAll('.direct-messages__filters button')

		expect(all.attributes('aria-pressed')).toBe('true')
		expect(unread.attributes('aria-pressed')).toBe('false')
		await unread.trigger('click')
		expect(all.attributes('aria-pressed')).toBe('false')
		expect(unread.attributes('aria-pressed')).toBe('true')
	})

	it('offers a clear empty inbox and a direct way to start a message', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__welcome').text()).toContain('Start a private chat')
		await wrapper.find('.direct-messages__welcome button').trigger('click')
		expect(wrapper.find('.direct-messages__new-message-panel').exists()).toBe(true)
	})

	it('shows the whole thread and marks it read', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/11/context')
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-id'))).toEqual(['9', '11', '12'])
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-hide-author'))).toEqual(['true', 'true', 'true'])
		expect(wrapper.findAll('.direct-messages__day')).toHaveLength(2)
		expect(wrapper.findAll('.direct-messages__message-time')).toHaveLength(3)
		expect(wrapper.findAll('.direct-messages__message--outgoing')).toHaveLength(1)
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__message-form textarea').exists()).toBe(true)
	})

	it('searches for one recipient and starts an existing chat instead of duplicating it', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		get.mockResolvedValueOnce({ data: [bob] })
		await wrapper.vm.searchAccounts('bob')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: 'bob', limit: 8, resolve: false } })
		expect(wrapper.vm.accountResults).toEqual([bob])
		await wrapper.vm.startConversation(bob)
		expect(wrapper.emitted('select')).toEqual([['10']])
	})

	it('shows followed people before typing and searches Mastodon account results', async () => {
		get.mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [] }
			}
			if (url.endsWith('/verify_credentials')) {
				return { data: { id: '42' } }
			}
			if (url.endsWith('/42/following')) {
				return { data: [bob] }
			}
			return { data: [{ ...bob, acct: 'bob@remote.example' }] }
		})
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/42/following', { params: { limit: 20 } })
		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
		await wrapper.find('.direct-messages__recipient-search input').setValue('bob')
		await wrapper.vm.searchAccounts('bob')
		await flushPromises()

		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
		expect(wrapper.find('.direct-messages__people-heading').text()).toContain('Search results')
		await wrapper.find('.direct-messages__recipient-search input').setValue('@bob')
		await wrapper.vm.searchAccounts('@bob')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/search', { params: { q: '@bob', limit: 8, resolve: true } })
		expect(wrapper.find('.direct-messages__recipient-results').text()).toContain('Bob')
	})

	it('hides only the leading protocol recipient mention in direct message bubbles and previews', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		const message = {
			visibility: 'direct',
			content: '<p><span class="h-card"><a href="https://remote.example/@bob">@<span>bob</span></a></span> hello <span class="h-card"><a>@carol</a></span></p>',
		}

		const rendered = wrapper.vm.messageForDisplay(message)
		expect(rendered.content).toBe('<p>hello <span class="h-card"><a>@carol</a></span></p>')
		expect(message.content).toContain('bob')
		expect(wrapper.vm.preview(message)).toBe('hello @carol')
		expect(wrapper.vm.messageForDisplay({ ...message, visibility: 'public' }).content).toContain('@bob')
	})

	it('removes recipient routing mentions returned as ordinary links or plain text', async () => {
		const wrapper = mountMessages('10')
		await flushPromises()
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p><a class="mention" href="https://remote.example/@bob">@bob@remote.example</a> hello</p>' }).content).toBe('<p>hello</p>')
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p>@bob@remote.example hello</p>' }).content).toBe('<p>hello</p>')
		expect(wrapper.vm.messageForDisplay({ visibility: 'direct', content: '<p><a class="mention" href="https://elsewhere.example/@carol">@carol</a> hello</p>' }).content).toContain('@carol')
	})

	it('dismisses a conversation from the inbox using the native row action', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.native-actions button').trigger('click')

		expect(remove).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10')
		expect(wrapper.find('.direct-messages__conversation').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__inbox-empty').exists()).toBe(true)
	})

	it('shows a search failure instead of claiming nobody exists', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.find('.direct-messages__list-heading button').trigger('click')
		await wrapper.find('.direct-messages__recipient-search input').setValue('alice')
		get.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.searchAccounts('alice')
		await flushPromises()

		expect(wrapper.find('.direct-messages__recipient-feedback[role="alert"]').text()).toContain('Could not search for people')
	})

	it('sends a private message with the selected recipient attached automatically', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		wrapper.vm.newRecipient = bob
		wrapper.vm.messageText = 'Hello there'
		await wrapper.vm.sendMessage()

		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses', {
			status: '@bob@remote.example Hello there',
			visibility: 'direct',
		})
		expect(wrapper.vm.messageText).toBe('')
	})

	it('keeps one inbox row per person even when the API returns duplicate threads', async () => {
		get.mockResolvedValueOnce({ data: [
			structuredClone(conversation),
			{ ...structuredClone(conversation), id: '11', unread: false },
		] })
		const wrapper = mountMessages()
		await flushPromises()
		expect(wrapper.findAll('.direct-messages__conversation')).toHaveLength(1)
	})

	it('shows an empty state when there are no conversations', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__inbox-empty').text()).toContain('No conversations yet')
		expect(wrapper.find('.direct-messages__thread-panel--empty').text()).toContain('Choose a conversation or find someone to message')
	})

	it('keeps failed read markers from hiding a loaded thread', async () => {
		post.mockRejectedValue(new Error('offline'))
		const wrapper = mountMessages()
		await flushPromises()
		await wrapper.setProps({ selectedConversationId: '10' })
		await flushPromises()

		expect(wrapper.findAll('.message-stub')).toHaveLength(3)
		expect(wrapper.find('.direct-messages__state[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(true)
	})
})
