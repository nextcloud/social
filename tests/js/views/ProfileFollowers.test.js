/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createStore } from 'vuex'
import ProfileFollowers from '../../../src/views/ProfileFollowers.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = structuredClone(account.state)

const UserEntryStub = { name: 'UserEntry', props: ['item'], template: '<div class="user-entry-stub" />' }

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol' }
const dave = { id: 'https://other.example/users/dave', url: 'https://other.example/users/dave', acct: 'dave@other.example', username: 'dave' }
const erin = { id: 'https://other.example/users/erin', url: 'https://other.example/users/erin', acct: 'erin@other.example', username: 'erin' }

let store
let dispatch

const fetchFollowers = vi.fn(async ({ commit }, { account: handle }) => {
	commit('addFollowers', { account: handle, data: [dave, erin] })
})
const fetchFollowing = vi.fn(async ({ commit }, { account: handle }) => {
	commit('addFollowing', { account: handle, data: [carol] })
})

const mountView = (route) => mount(ProfileFollowers, {
	global: { plugins: [store], mocks: { $route: route }, stubs: { UserEntry: UserEntryStub } },
})

const shown = (wrapper) => wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item').acct)

describe('ProfileFollowers', () => {
	beforeEach(() => {
		Object.assign(account.state, structuredClone(pristine))
		store = createStore({
			modules: {
				settings,
				account: {
					...account,
					actions: { ...account.actions, fetchAccountFollowers: fetchFollowers, fetchAccountFollowing: fetchFollowing },
				},
			},
		})
		store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org' })
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addAccount', { actorId: carol.url, data: carol })
		dispatch = vi.spyOn(store, 'dispatch')
		fetchFollowers.mockClear()
		fetchFollowing.mockClear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('fetches and lists the followers of the routed account', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		expect(dispatch).toHaveBeenCalledWith('fetchAccountFollowers', { account: 'bob@remote.example' })
		expect(dispatch).not.toHaveBeenCalledWith('fetchAccountFollowing', expect.anything())
		await flushPromises()
		expect(shown(wrapper)).toEqual(['dave@other.example', 'erin@other.example'])
	})

	it('fetches and lists the accounts the routed account follows', async () => {
		const wrapper = mountView({ name: 'profile.following', params: { account: 'bob@remote.example' } })
		expect(dispatch).toHaveBeenCalledWith('fetchAccountFollowing', { account: 'bob@remote.example' })
		await flushPromises()
		expect(shown(wrapper)).toEqual(['carol'])
	})

	it('completes a bare local uid with the instance host', () => {
		mountView({ name: 'profile.followers', params: { account: 'carol' } })
		expect(dispatch).toHaveBeenCalledWith('fetchAccountFollowers', { account: 'carol@cloud.example.org' })
	})

	it('does nothing without an account in the route', () => {
		const wrapper = mountView({ name: 'profile.followers', params: {} })
		expect(dispatch).not.toHaveBeenCalled()
		expect(shown(wrapper)).toEqual([])
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('shows a loading indicator while the list is being fetched', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		await flushPromises()
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)

		store.commit('setFollowersLoading', { actorId: bob.url, loading: true })
		await nextTick()
		expect(wrapper.find('.loading-indicator').text()).toBe('Loading …')

		store.commit('setFollowersLoading', { actorId: bob.url, loading: false })
		await nextTick()
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('only reacts to the loading flag of the shown list', async () => {
		const wrapper = mountView({ name: 'profile.following', params: { account: 'bob@remote.example' } })
		store.commit('setFollowersLoading', { actorId: bob.url, loading: true })
		await nextTick()
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('switches lists when the route moves between followers and following', async () => {
		const route = reactive({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		const wrapper = mountView(route)
		await flushPromises()
		expect(shown(wrapper)).toEqual(['dave@other.example', 'erin@other.example'])

		route.name = 'profile.following'
		await flushPromises()
		expect(dispatch).toHaveBeenCalledWith('fetchAccountFollowing', { account: 'bob@remote.example' })
		expect(shown(wrapper)).toEqual(['carol'])
	})

	it('refetches when the route points to another account', async () => {
		const route = reactive({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		mountView(route)
		route.params.account = 'carol'
		await flushPromises()
		expect(dispatch).toHaveBeenLastCalledWith('fetchAccountFollowers', { account: 'carol@cloud.example.org' })
	})

	it('forwards the pagination cursor as maxId when loading more', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		await flushPromises()
		// the first page set the cursor from the last loaded follower
		expect(wrapper.vm.maxId).toBe(erin.id)
		dispatch.mockClear()

		wrapper.vm.loadMoreIfNeeded()

		expect(dispatch).toHaveBeenCalledWith('fetchAccountFollowers', { account: 'bob@remote.example', maxId: erin.id })
	})

	it('watches the end of the list for infinite scrolling and stops on unmount', () => {
		const observe = vi.fn()
		const disconnect = vi.fn()
		vi.stubGlobal('IntersectionObserver', class {

			constructor(callback, options) {
				this.options = options
			}

			observe = observe
			disconnect = disconnect

		})
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		return nextTick().then(() => {
			expect(observe).toHaveBeenCalledWith(wrapper.find('.list-sentinel').element)
			wrapper.unmount()
			expect(disconnect).toHaveBeenCalledTimes(1)
			vi.unstubAllGlobals()
		})
	})
})
