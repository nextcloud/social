/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ProfileFollowers from '../../../src/views/ProfileFollowers.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const UserEntryStub = { name: 'UserEntry', props: ['item'], template: '<div class="user-entry-stub" />' }

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol' }
const dave = { id: 'https://other.example/users/dave', url: 'https://other.example/users/dave', acct: 'dave@other.example', username: 'dave' }
const erin = { id: 'https://other.example/users/erin', url: 'https://other.example/users/erin', acct: 'erin@other.example', username: 'erin' }

let pinia
let store

const fetchFollowers = vi.fn(async ({ account: handle }) => {
	store.addFollowers({ account: handle, data: [dave, erin] })
})
const fetchFollowing = vi.fn(async ({ account: handle }) => {
	store.addFollowing({ account: handle, data: [carol] })
})

function mountView(route) {
	return mount(ProfileFollowers, {
		global: { plugins: [pinia], mocks: { $route: route }, stubs: { UserEntry: UserEntryStub } },
	})
}

const shown = (wrapper) => wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item').acct)

describe('ProfileFollowers', () => {
	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useAccountStore()
		vi.spyOn(store, 'fetchAccountFollowers').mockImplementation(fetchFollowers)
		vi.spyOn(store, 'fetchAccountFollowing').mockImplementation(fetchFollowing)
		useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org' })
		store.addAccount({ actorId: bob.url, data: bob })
		store.addAccount({ actorId: carol.url, data: carol })
		fetchFollowers.mockClear()
		fetchFollowing.mockClear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('fetches and lists the followers of the routed account', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		expect(store.fetchAccountFollowers).toHaveBeenCalledWith({ account: 'bob@remote.example' })
		expect(store.fetchAccountFollowing).not.toHaveBeenCalled()
		await flushPromises()
		expect(shown(wrapper)).toEqual(['dave@other.example', 'erin@other.example'])
	})

	it('fetches and lists the accounts the routed account follows', async () => {
		const wrapper = mountView({ name: 'profile.following', params: { account: 'bob@remote.example' } })
		expect(store.fetchAccountFollowing).toHaveBeenCalledWith({ account: 'bob@remote.example' })
		await flushPromises()
		expect(shown(wrapper)).toEqual(['carol'])
	})

	it('completes a bare local uid with the instance host', () => {
		mountView({ name: 'profile.followers', params: { account: 'carol' } })
		expect(store.fetchAccountFollowers).toHaveBeenCalledWith({ account: 'carol@cloud.example.org' })
	})

	it('does nothing without an account in the route', () => {
		const wrapper = mountView({ name: 'profile.followers', params: {} })
		expect(store.fetchAccountFollowers).not.toHaveBeenCalled()
		expect(store.fetchAccountFollowing).not.toHaveBeenCalled()
		expect(shown(wrapper)).toEqual([])
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('shows a loading indicator while the list is being fetched', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		await flushPromises()
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)

		store.setFollowersLoading({ actorId: bob.url, loading: true })
		await nextTick()
		expect(wrapper.find('.loading-indicator').text()).toBe('Loading …')

		store.setFollowersLoading({ actorId: bob.url, loading: false })
		await nextTick()
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('only reacts to the loading flag of the shown list', async () => {
		const wrapper = mountView({ name: 'profile.following', params: { account: 'bob@remote.example' } })
		store.setFollowersLoading({ actorId: bob.url, loading: true })
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
		expect(store.fetchAccountFollowing).toHaveBeenCalledWith({ account: 'bob@remote.example' })
		expect(shown(wrapper)).toEqual(['carol'])
	})

	it('refetches when the route points to another account', async () => {
		const route = reactive({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		mountView(route)
		route.params.account = 'carol'
		await flushPromises()
		expect(store.fetchAccountFollowers).toHaveBeenLastCalledWith({ account: 'carol@cloud.example.org' })
	})

	it('forwards the pagination cursor as maxId when loading more', async () => {
		const wrapper = mountView({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
		await flushPromises()
		// the first page set the cursor from the last loaded follower
		expect(wrapper.vm.maxId).toBe(erin.id)
		store.fetchAccountFollowers.mockClear()
		store.fetchAccountFollowing.mockClear()

		wrapper.vm.loadMoreIfNeeded()

		expect(store.fetchAccountFollowers).toHaveBeenCalledWith({ account: 'bob@remote.example', maxId: erin.id })
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
