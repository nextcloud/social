/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import Search from '../../../src/components/Search.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = structuredClone(account.state)

const UserEntryStub = {
	name: 'UserEntry',
	props: ['item'],
	template: '<div class="user-entry-stub" />',
}
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	template: '<li class="timeline-entry-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="empty-stub"><span class="empty-name">{{ name }}</span><span class="empty-description">{{ description }}</span></div>',
}
const NcLoadingIconStub = { name: 'NcLoadingIcon', template: '<span class="loading-stub" />' }

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol', display_name: 'Carol' }

const status = (id) => ({ id, content: `<p>post ${id}</p>`, created_at: '2026-01-01T00:00:00Z', account: bob })

/**
 * The v2 search response: three flat lists, the way Mastodon answers.
 *
 * @param {object} results what the server found
 * @param {Array} [results.accounts] matching accounts
 * @param {Array} [results.statuses] matching statuses
 * @param {Array} [results.hashtags] matching hashtag names
 * @return {object} an axios-shaped response
 */
const response = ({ accounts = [], statuses = [], hashtags = [] } = {}) => ({
	data: {
		accounts,
		statuses,
		hashtags: hashtags.map((name) => ({ name, url: `https://cloud.example.org/timeline/tags/${name}`, history: [] })),
	},
})

const SEARCH_URL = '/index.php/apps/social/api/v2/search'

let store
let get

const mountSearch = (term) => mount(Search, {
	props: { term },
	global: {
		plugins: [store],
		stubs: {
			UserEntry: UserEntryStub,
			TimelineEntry: TimelineEntryStub,
			RouterLink: RouterLinkStub,
			NcEmptyContent: NcEmptyContentStub,
			NcLoadingIcon: NcLoadingIconStub,
		},
	},
})

describe('Search', () => {
	beforeEach(() => {
		Object.assign(account.state, structuredClone(pristine))
		store = createStore({ modules: { account, settings } })
		store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org' })
		get = vi.spyOn(axios, 'get')
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks the server rather than filtering the posts already loaded', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountSearch('nextcloud')

		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith(SEARCH_URL, { params: { q: 'nextcloud', limit: 20 } })
		expect(wrapper.find('.loading-stub').exists()).toBe(true)
	})

	it('lists the accounts, hashtags and posts the server found, and caches the accounts', async () => {
		get.mockResolvedValue(response({
			accounts: [bob, carol],
			hashtags: ['nextcloud', 'fediverse'],
			statuses: [status('1'), status('2')],
		}))
		const wrapper = mountSearch('nextcloud')
		await flushPromises()

		expect(wrapper.find('h1').text()).toBe('Search results for “nextcloud”')
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob, carol])
		expect(wrapper.findAll('li.tag').map((tag) => tag.text())).toEqual(['#nextcloud', '#fediverse'])
		expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
			{ name: 'tags', params: { tag: 'nextcloud' } },
			{ name: 'tags', params: { tag: 'fediverse' } },
		])
		expect(wrapper.findAllComponents(TimelineEntryStub).map((entry) => entry.props('item').id)).toEqual(['1', '2'])

		expect(store.getters.getAccount('bob@remote.example')).toEqual(bob)
		expect(store.getters.getAccount('carol@cloud.example.org')).toEqual(carol)
	})

	it('leaves out the sections the server found nothing for', async () => {
		get.mockResolvedValue(response({ accounts: [bob] }))
		const wrapper = mountSearch('bob')
		await flushPromises()

		expect(wrapper.findAllComponents(UserEntryStub)).toHaveLength(1)
		expect(wrapper.find('li.tag').exists()).toBe(false)
		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(false)
		expect(wrapper.findComponent(NcEmptyContentStub).exists()).toBe(false)
	})

	it('shows an empty state with the decoded term when nothing matches', async () => {
		get.mockResolvedValue(response())
		const wrapper = mountSearch('foo%20bar')
		await flushPromises()

		expect(wrapper.find('.empty-name').text()).toBe('No results found')
		expect(wrapper.find('.empty-description').text()).toContain('foo bar')
		expect(wrapper.find('.loading-stub').exists()).toBe(false)
	})

	it('shows a failure as an error with a retry, and does not block later searches', async () => {
		get.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountSearch('boom')
		await flushPromises()

		expect(wrapper.find('.loading-stub').exists()).toBe(false)
		expect(wrapper.find('.social__search-error').text()).toContain('The search could not be run.')
		expect(wrapper.find('.social__search-error').attributes('role')).toBe('alert')

		get.mockResolvedValueOnce(response({ accounts: [bob] }))
		await wrapper.find('.social__search-error button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob])
	})

	it('debounces a changing term into one request', async () => {
		vi.useFakeTimers()
		try {
			get.mockResolvedValue(response({ accounts: [bob] }))
			const wrapper = mountSearch('b')
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(1)

			await wrapper.setProps({ term: 'bo' })
			await wrapper.setProps({ term: 'bob' })
			// re-filtering and re-sorting per keystroke was the old cost
			expect(get).toHaveBeenCalledTimes(1)

			vi.advanceTimersByTime(300)
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(2)
			expect(get).toHaveBeenLastCalledWith(SEARCH_URL, { params: { q: 'bob', limit: 20 } })
		} finally {
			vi.useRealTimers()
		}
	})

	it('asks nothing at all for an empty term', async () => {
		const wrapper = mountSearch('   ')
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcEmptyContentStub).exists()).toBe(true)
	})
})
