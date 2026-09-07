/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import Search from '../../../src/components/Search.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

vi.mock('@nextcloud/dialogs', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
}))

const pristine = structuredClone(account.state)

const UserEntryStub = {
	name: 'UserEntry',
	props: ['item'],
	template: '<div class="user-entry-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol', display_name: 'Carol' }

const response = ({ exact = null, accounts = [], hashtags = [] } = {}) => ({
	data: {
		result: {
			accounts: { exact, result: accounts },
			hashtags: { result: hashtags.map((hashtag) => ({ hashtag })) },
		},
	},
})

let store
let get

const mountSearch = (term) => mount(Search, {
	props: { term },
	global: { plugins: [store], stubs: { UserEntry: UserEntryStub, RouterLink: RouterLinkStub } },
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
		vi.mocked(showError).mockClear()
	})

	it('clears the spinner and reports the error when a search fails, and does not block later searches', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountSearch('boom')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('#emptycontent').classes()).not.toContain('icon-loading')

		// the stuck loading flag used to block every later search
		get.mockResolvedValueOnce(response({ accounts: [bob] }))
		await wrapper.setProps({ term: 'bob' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob])
	})

	it('queries the search endpoint for the initial term and shows a spinner meanwhile', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountSearch('nextcloud')
		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/search?search=nextcloud')
		expect(wrapper.find('#emptycontent').classes()).toContain('icon-loading')
		expect(wrapper.find('h2').exists()).toBe(false)
	})

	it('lists matching accounts and hashtags and caches the accounts in the store', async () => {
		get.mockResolvedValue(response({ accounts: [bob, carol], hashtags: ['nextcloud', 'fediverse'] }))
		const wrapper = mountSearch('nextcloud')
		await flushPromises()

		expect(wrapper.find('h3').text()).toBe('Searching for nextcloud')
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob, carol])
		expect(wrapper.findAll('li.tag').map((tag) => tag.text())).toEqual(['#nextcloud', '#fediverse'])
		expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
			{ name: 'tags', params: { tag: 'nextcloud' } },
			{ name: 'tags', params: { tag: 'fediverse' } },
		])
		expect(store.getters.getAccount('bob@remote.example')).toEqual(bob)
		expect(store.getters.getAccount('carol@cloud.example.org')).toEqual(carol)
	})

	it('shows only the exact match when the server found one', async () => {
		get.mockResolvedValue(response({ exact: carol, accounts: [bob] }))
		const wrapper = mountSearch('carol')
		await flushPromises()

		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([carol])
		expect(wrapper.find('li.tag').exists()).toBe(false)
		// both the exact match and the other candidates end up in the store
		expect(store.getters.getAccount('bob@remote.example')).toEqual(bob)
	})

	it('shows an empty state with the decoded term when nothing matches', async () => {
		get.mockResolvedValue(response())
		const wrapper = mountSearch('foo%20bar')
		await flushPromises()

		const empty = wrapper.find('#emptycontent')
		expect(empty.classes()).not.toContain('icon-loading')
		expect(empty.find('h2').text()).toBe('No results found')
		expect(empty.find('p').text()).toBe('There were no results for your search: foo bar')
		expect(wrapper.find('h3').exists()).toBe(false)
	})

	it('searches again when the term changes after the previous search finished', async () => {
		get.mockResolvedValue(response({ accounts: [bob] }))
		const wrapper = mountSearch('bob')
		await flushPromises()

		get.mockResolvedValue(response({ hashtags: ['other'] }))
		await wrapper.setProps({ term: 'other' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(get).toHaveBeenLastCalledWith('/index.php/apps/social/api/v1/search?search=other')
		expect(wrapper.findAllComponents(UserEntryStub)).toHaveLength(0)
		expect(wrapper.find('li.tag').text()).toBe('#other')
	})
})
