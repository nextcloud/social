/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import Profile from '../../../src/views/Profile.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ProfileInfoStub = { name: 'ProfileInfo', props: ['uid'], template: '<section class="profile-info-stub" />' }
const ComposerStub = { name: 'Composer', props: ['initialMention', 'defaultVisibility'], template: '<div class="composer-stub" />' }
const RouterViewStub = { name: 'RouterView', props: ['name'], template: '<div class="router-view-stub" :data-name="name" />' }

const bob = {
	id: 'https://remote.example/users/bob',
	nid: '77',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
}
const carol = {
	id: 'https://cloud.example.org/users/carol',
	url: 'https://cloud.example.org/users/carol',
	acct: 'carol',
	username: 'carol',
	display_name: 'Carol',
}
const alice = {
	id: 'https://cloud.example.org/users/alice',
	url: 'https://cloud.example.org/users/alice',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
}
const known = { 'bob@remote.example': bob, 'carol@cloud.example.org': carol, 'alice@cloud.example.org': alice }

let pinia
let accountStore

// Simulates the server: known handles are put into the store, unknown ones
// resolve to nothing like the real action does after an error.
const fetchAccount = vi.fn(async (handle) => {
	await Promise.resolve()
	const data = known[handle]
	if (!data) {
		return undefined
	}
	accountStore.addAccount({ actorId: data.url, data })

	return data
})

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	vi.spyOn(accountStore, 'fetchAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'fetchPublicAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'fetchAccountRelationshipInfo').mockResolvedValue([])
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })

	return pinia
}

function mountProfile(route) {
	return mount(Profile, {
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: { ProfileInfo: ProfileInfoStub, Composer: ComposerStub, RouterView: RouterViewStub },
		},
	})
}

describe('Profile', () => {
	beforeEach(() => {
		makeStore()
		fetchAccount.mockClear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the routed remote account, then its relationship, and shows the profile with the details view', async () => {
		const wrapper = mountProfile({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(wrapper.classes()).toContain('icon-loading')
		expect(wrapper.findComponent(ProfileInfoStub).exists()).toBe(false)
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('bob@remote.example')

		await flushPromises()
		expect(accountStore.fetchAccountRelationshipInfo).toHaveBeenCalledWith(['77'])
		expect(wrapper.classes()).not.toContain('icon-loading')
		expect(wrapper.findComponent(ProfileInfoStub).props('uid')).toBe('bob@remote.example')
		expect(wrapper.findComponent(RouterViewStub).props('name')).toBe('details')
	})

	it('completes a bare local uid with the instance host before asking the server', async () => {
		const wrapper = mountProfile({ name: 'profile', params: { account: 'carol' } })
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('carol@cloud.example.org')
		await flushPromises()
		// without a numeric id the relationship lookup falls back to the actor id
		expect(accountStore.fetchAccountRelationshipInfo).toHaveBeenCalledWith(['https://cloud.example.org/users/carol'])
		expect(wrapper.findComponent(ProfileInfoStub).props('uid')).toBe('carol')
	})

	it('stays in the loading state when the account cannot be resolved', async () => {
		const wrapper = mountProfile({ name: 'profile', params: { account: 'nobody@remote.example' } })
		await flushPromises()
		expect(wrapper.classes()).toContain('icon-loading')
		expect(wrapper.findComponent(ProfileInfoStub).exists()).toBe(false)
		expect(wrapper.findComponent(RouterViewStub).exists()).toBe(false)
		expect(accountStore.fetchAccountRelationshipInfo).not.toHaveBeenCalled()
	})

	it('does nothing without an account in the route or the server data', () => {
		mountProfile({ name: 'profile', params: {} })
		expect(fetchAccount).not.toHaveBeenCalled()
	})

	it('reloads when the route switches to another account', async () => {
		const route = reactive({ name: 'profile', params: { account: 'bob@remote.example' } })
		const wrapper = mountProfile(route)
		await flushPromises()

		route.params.account = 'carol'
		await flushPromises()
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('carol@cloud.example.org')
		expect(wrapper.findComponent(ProfileInfoStub).props('uid')).toBe('carol')
	})

	describe('composer', () => {
		beforeEach(() => {
			accountStore.addAccount({ actorId: alice.url, data: alice })
			accountStore.setCurrentAccount('alice@cloud.example.org')
		})

		it('offers a direct message to the shown account on the posts tab', async () => {
			const wrapper = mountProfile({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()
			const composer = wrapper.findComponent(ComposerStub)
			expect(composer.props('defaultVisibility')).toBe('direct')
			expect(composer.props('initialMention')).toEqual(bob)
		})

		it('does not pre-fill a mention on the own profile', async () => {
			const wrapper = mountProfile({ name: 'profile', params: { account: 'alice' } })
			await flushPromises()
			expect(wrapper.findComponent(ComposerStub).props('initialMention')).toBeNull()
		})

		it('is hidden on the followers and following tabs', async () => {
			const wrapper = mountProfile({ name: 'profile.followers', params: { account: 'bob@remote.example' } })
			await flushPromises()
			expect(wrapper.findComponent(ProfileInfoStub).exists()).toBe(true)
			expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		})

		it('is hidden while no current account is known', async () => {
			accountStore.setCurrentAccount('')
			const wrapper = mountProfile({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()
			expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		})
	})

	describe('on the public page', () => {
		beforeEach(() => {
			makeStore({ public: true, account: 'carol' })
		})

		it('uses the public lookup for the account from the server data and skips the relationship', async () => {
			const wrapper = mountProfile({ name: undefined, params: {} })
			expect(accountStore.fetchPublicAccountInfo).toHaveBeenCalledWith('carol@cloud.example.org')
			await flushPromises()
			expect(accountStore.fetchAccountRelationshipInfo).not.toHaveBeenCalled()
			expect(accountStore.fetchAccountInfo).not.toHaveBeenCalled()
			expect(wrapper.findComponent(ProfileInfoStub).props('uid')).toBe('carol')
			expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		})
	})

	describe('when the account cannot be loaded', () => {
		const NcEmptyContentStub = { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="empty-content-stub" :data-name="name" />' }

		// the lookup coming back empty is what makes this reachable: it used to
		// be guarded by `accountLoaded && !accountInfo`, which are the same
		// question asked twice and so never both true
		it('renders NcEmptyContent with the not-found message as its name prop', async () => {
			const wrapper = mount(Profile, {
				global: {
					plugins: [makeStore()],
					mocks: { $route: { name: 'profile', params: { account: 'nobody@remote.example' } } },
					stubs: { ProfileInfo: ProfileInfoStub, Composer: ComposerStub, RouterView: RouterViewStub, NcEmptyContent: NcEmptyContentStub },
				},
			})
			await flushPromises()
			const empty = wrapper.findComponent(NcEmptyContentStub)
			expect(empty.exists()).toBe(true)
			expect(empty.props('name')).toBe('User not found')
			expect(wrapper.findComponent(ProfileInfoStub).exists()).toBe(false)
		})
	})
})
