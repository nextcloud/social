/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import OStatus from '../../../src/views/OStatus.vue'
import account from '../../../src/store/account.js'
import errors from '../../../src/store/errors.js'
import settings from '../../../src/store/settings.js'

const pristine = structuredClone(account.state)

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const currentUser = { uid: 'alice', displayName: 'Alice' }

let store
let dispatch
let pending
const fetchAccount = vi.fn(() => pending)

const setState = (key, value) => {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

const makeStore = () => {
	Object.assign(account.state, structuredClone(pristine))
	store = createStore({
		modules: {
			settings,
			errors,
			account: {
				...account,
				actions: { ...account.actions, fetchAccountInfo: fetchAccount, fetchPublicAccountInfo: fetchAccount, followAccount: vi.fn() },
			},
		},
	})
	dispatch = vi.spyOn(store, 'dispatch')
	return store
}

const mountView = () => mount(OStatus, { global: { plugins: [store], stubs: { NcAvatar: NcAvatarStub } } })

describe('OStatus', () => {
	beforeEach(() => {
		pending = new Promise(() => {})
		fetchAccount.mockClear()
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete window.oc_current_user
	})

	describe('following a remote account from this instance', () => {
		const serverData = { public: false, cloudAddress: 'https://cloud.example.org', account: 'bob@remote.example', local: '', currentUser }

		beforeEach(() => {
			setState('serverData', serverData)
		})

		it('imports the server data and current user and looks up the remote account', () => {
			mountView()
			expect(store.state.settings.serverData).toEqual(serverData)
			expect(window.oc_current_user).toEqual(currentUser)
			expect(dispatch).toHaveBeenCalledWith('fetchAccountInfo', 'bob@remote.example')
			expect(dispatch).not.toHaveBeenCalledWith('fetchPublicAccountInfo', expect.anything())
		})

		it('asks the logged-in user to confirm, naming the account before it is loaded', () => {
			const wrapper = mountView()
			const headings = wrapper.findAll('h2').map((heading) => heading.text())
			expect(headings).toEqual(['Follow on Nextcloud Social', 'bob@remote.example'])
			expect(wrapper.text()).toContain('Hello')
			expect(wrapper.text()).toContain('Alice')
			expect(wrapper.text()).toContain('Please confirm that you want to follow this account:')
			expect(wrapper.find('form input[type="submit"]').element.value).toBe('Follow')
			expect(wrapper.text()).not.toContain('You are following this account')
		})

		it('shows the avatar of the remote account through the app proxy once loaded', async () => {
			pending = Promise.resolve(bob)
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.findComponent(NcAvatarStub).props('url'))
				.toBe('/index.php/apps/social/api/v1/global/actor/avatar?id=https://remote.example/users/bob')
			expect(wrapper.findComponent(NcAvatarStub).props('size')).toBe(128)
		})

		it('dispatches the follow with the cloud id of the current user on submit', async () => {
			pending = Promise.resolve(bob)
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('form').trigger('submit')
			expect(dispatch).toHaveBeenCalledWith('followAccount', expect.objectContaining({ currentAccount: 'alice@cloud.example.org' }))
		})
	})

	describe('following a local account from another instance', () => {
		const serverData = { public: true, cloudAddress: 'https://cloud.example.org', account: '', local: 'carol' }

		beforeEach(() => {
			setState('serverData', serverData)
		})

		it('looks up the local account publicly and explains the redirect', () => {
			const wrapper = mountView()
			expect(dispatch).toHaveBeenCalledWith('fetchPublicAccountInfo', 'carol')
			expect(dispatch).not.toHaveBeenCalledWith('fetchAccountInfo', expect.anything())
			expect(wrapper.text()).toContain('You are going to follow:')
			expect(wrapper.find('h2').text()).toBe('carol')
			expect(wrapper.findComponent(NcAvatarStub).props('user')).toBe('carol')
			expect(wrapper.find('input[type="text"]').attributes('placeholder')).toBe('name@domain of your federation account')
			expect(wrapper.find('input[type="submit"]').element.value).toBe('Continue')
			expect(wrapper.text()).toContain('We will redirect you to your homeserver to follow this account.')
		})

		it('resolves the remote follow link for the entered handle and redirects there', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { result: { url: 'https://other.example/authorize_interaction?uri=carol@cloud.example.org' } } })
			// jsdom cannot navigate: intercept the assignment to window.location
			const descriptor = Object.getOwnPropertyDescriptor(window, 'location')
			const location = window.location
			const navigate = vi.fn()
			Object.defineProperty(window, 'location', { get: () => location, set: navigate, configurable: true })
			try {
				const wrapper = mountView()
				await wrapper.find('input[type="text"]').setValue('dave@other.example')
				await wrapper.find('form').trigger('submit')
				await flushPromises()

				expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/ostatus/link/carol/dave@other.example')
				expect(navigate).toHaveBeenCalledWith('https://other.example/authorize_interaction?uri=carol@cloud.example.org')
			} finally {
				Object.defineProperty(window, 'location', descriptor)
			}
		})
	})

	it('shows a spinner while no account was resolved yet', async () => {
		setState('serverData', { public: true, cloudAddress: 'https://cloud.example.org', local: 'carol' })
		pending = Promise.resolve(undefined)
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.find('.icon-loading-dark').exists()).toBe(true)
		expect(wrapper.find('h2').exists()).toBe(false)
	})
})
