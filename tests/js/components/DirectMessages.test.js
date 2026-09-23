/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import DirectMessages from '../../../src/components/DirectMessages.vue'

const bob = { id: 'https://remote.example/users/bob', acct: 'bob@remote.example', display_name: 'Bob' }
const latest = { id: '11', content: '<p>Latest reply</p>', account: bob }
const conversation = { id: '10', unread: true, accounts: [bob], last_status: latest }
const context = {
	ancestors: [{ id: '9', content: '<p>Earlier message</p>', account: bob }],
	descendants: [{ id: '12', content: '<p>Next message</p>', account: bob }],
}

const stubs = {
	ActorAvatar: { props: ['actor', 'size', 'link'], template: '<span class="avatar-stub">{{ actor.display_name }}</span>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
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

	beforeEach(() => {
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/conversations')) {
				return { data: [structuredClone(conversation)] }
			}
			return { data: context }
		})
		post = vi.spyOn(axios, 'post').mockResolvedValue({ data: conversation })
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

	it('offers a clear empty inbox and a direct way to start a message', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountMessages()
		await flushPromises()

		expect(wrapper.find('.direct-messages__welcome').text()).toContain('Your messages, together')
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
		expect(wrapper.findAll('.message-stub').map((entry) => entry.attributes('data-hide-author'))).toEqual(['false', 'true', 'true'])
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/conversations/10/read')
		expect(wrapper.find('.direct-messages__unread-dot').exists()).toBe(false)
		expect(wrapper.find('.direct-messages__message-form textarea').exists()).toBe(true)
	})

	it('searches for one recipient and starts an existing chat instead of duplicating it', async () => {
		const wrapper = mountMessages()
		await flushPromises()
		get.mockResolvedValueOnce({ data: { accounts: [bob] } })
		await wrapper.vm.searchAccounts('bob')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/global/accounts/search', { params: { search: 'bob' } })
		await wrapper.vm.startConversation(bob)
		expect(wrapper.emitted('select')).toEqual([['10']])
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

		expect(wrapper.find('.direct-messages__inbox-empty').text()).toContain('No direct conversations yet')
		expect(wrapper.find('.direct-messages__thread-panel--empty').text()).toContain('Choose a conversation to pick up where you left off')
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
