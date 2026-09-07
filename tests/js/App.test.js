/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, shallowRef } from 'vue'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import App from '../../src/App.vue'
import account from '../../src/store/account.js'
import errors from '../../src/store/errors.js'
import settings from '../../src/store/settings.js'
import timeline from '../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = {
	account: structuredClone(account.state),
	timeline: structuredClone(timeline.state),
}

const stubs = {
	NcContent: { props: ['appName'], template: '<div class="content-stub" :data-app-name="appName"><slot /></div>' },
	NcAppContent: { template: '<main class="app-content-stub"><slot /></main>' },
	Navigation: { emits: ['search', 'reset-cache', 'open-composer'], template: '<nav class="navigation-stub" />' },
	RouterView: { template: '<div class="router-view-stub" />' },
}

const baseServerData = {
	public: false,
	setup: false,
	isAdmin: false,
	firstrun: false,
	cloudAddress: 'https://cloud.example.org',
	cliUrl: 'https://cloud.example.org',
	checks: { success: true, checks: { wellknown: true } },
}

let store
let dispatch
let route
let router
const fetchAccountInfo = vi.fn(async () => undefined)

const setServerData = (overrides = {}) => {
	setInitialState('social', 'serverData', { ...baseServerData, ...overrides })
	window._nc_initial_state?.clear()
}

const makeStore = () => {
	Object.assign(account.state, structuredClone(pristine.account))
	Object.assign(timeline.state, structuredClone(pristine.timeline))
	store = createStore({
		modules: {
			settings,
			errors,
			account: { ...account, actions: { ...account.actions, fetchAccountInfo } },
			timeline: { ...timeline, actions: { ...timeline.actions, refreshTimeline: vi.fn(), fetchTimeline: vi.fn() } },
		},
	})
	dispatch = vi.spyOn(store, 'dispatch')
	return store
}

// $route is exposed through a getter so swapping the ref triggers the
// component's $route watcher like vue-router would.
const routePlugin = {
	install(app) {
		Object.defineProperty(app.config.globalProperties, '$route', { get: () => route.value, configurable: true })
	},
}

const mountApp = () => mount(App, {
	global: { plugins: [store, routePlugin], mocks: { $router: router }, stubs },
})

describe('App', () => {
	beforeEach(() => {
		makeStore()
		fetchAccountInfo.mockClear()
		route = shallowRef({ name: 'timeline', params: {}, fullPath: '/timeline' })
		router = { push: vi.fn() }
		setServerData()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.OCA.Push
	})

	it('imports the server data and loads the current account', () => {
		mountApp()
		expect(store.state.settings.serverData).toEqual(baseServerData)
		expect(dispatch).toHaveBeenCalledWith('fetchCurrentAccountInfo', 'alice@cloud.example.org')
		expect(store.state.account.currentAccount).toBe('alice@cloud.example.org')
		// the action dispatches the lookup through its module context
		expect(fetchAccountInfo).toHaveBeenCalledTimes(1)
		expect(fetchAccountInfo.mock.calls[0][1]).toBe('alice@cloud.example.org')
	})

	it('renders the navigation and the routed view inside the app content', () => {
		const wrapper = mountApp()
		expect(wrapper.find('.content-stub').attributes('data-app-name')).toBe('social')
		expect(wrapper.find('.content-stub').classes()).not.toContain('public')
		expect(wrapper.find('.navigation-stub').exists()).toBe(true)
		expect(wrapper.find('.app-content-stub .router-view-stub').exists()).toBe(true)
		expect(wrapper.find('.setup').exists()).toBe(false)
	})

	it('drops the navigation and skips the account lookup on the public page', () => {
		setServerData({ public: true })
		const wrapper = mountApp()
		expect(wrapper.find('.content-stub').classes()).toContain('public')
		expect(wrapper.find('.navigation-stub').exists()).toBe(false)
		expect(wrapper.find('.router-view-stub').exists()).toBe(true)
		expect(dispatch).not.toHaveBeenCalledWith('fetchCurrentAccountInfo', expect.anything())
	})

	it('warns administrators about a broken .well-known setup', () => {
		setServerData({ isAdmin: true, checks: { success: false, checks: { wellknown: false } } })
		const wrapper = mountApp()
		expect(wrapper.find('.setup h3').text()).toBe('.well-known/webfinger isn\'t properly set up!')
		expect(wrapper.find('.setup a.external_link').attributes('href')).toContain('admin-setup-well-known-URL')
		expect(wrapper.find('.router-view-stub').exists()).toBe(true)
	})

	it('does not show the .well-known warning to regular users', () => {
		setServerData({ isAdmin: false, checks: { success: false, checks: { wellknown: false } } })
		expect(mountApp().find('.setup').exists()).toBe(false)
	})

	it('stores the search term coming from the navigation and clears it on navigation', async () => {
		const wrapper = mountApp()
		wrapper.findComponent(stubs.Navigation).vm.$emit('search', 'fediverse')
		expect(store.state.timeline.searchQuery).toBe('fediverse')

		route.value = { name: 'timeline', params: { type: 'federated' }, fullPath: '/timeline/federated' }
		await nextTick()
		expect(store.state.timeline.searchQuery).toBe('')
	})

	it('refreshes the server cache and then the timeline', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountApp()
		wrapper.findComponent(stubs.Navigation).vm.$emit('reset-cache')
		expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/cache/refresh')
		expect(dispatch).not.toHaveBeenCalledWith('refreshTimeline')
		await flushPromises()
		expect(dispatch).toHaveBeenCalledWith('refreshTimeline')
	})

	describe('push notifications', () => {
		const status = { id: 's1', content: '<p>live</p>', created_at: '2026-03-01T10:00:00Z' }
		let addCallback

		beforeEach(() => {
			addCallback = vi.fn()
			globalThis.OCA.Push = { isEnabled: () => true, addCallback }
		})

		it('registers for the social push channel when the push app is enabled', () => {
			mountApp()
			expect(addCallback).toHaveBeenCalledTimes(1)
			expect(addCallback.mock.calls[0][1]).toBe('social')
		})

		it('adds pushed home posts only while the home timeline is shown', () => {
			mountApp()
			const [callback] = addCallback.mock.calls[0]
			callback({ source: 'timeline.home', payload: status })
			expect(dispatch).toHaveBeenCalledWith('addToTimeline', [status])
			expect(store.state.timeline.timeline).toEqual(['s1'])

			dispatch.mockClear()
			callback({ source: 'timeline.direct', payload: { ...status, id: 's2' } })
			expect(dispatch).not.toHaveBeenCalledWith('addToTimeline', expect.anything())
		})

		it('adds pushed direct messages on the direct timeline', () => {
			route.value = { name: 'timeline', params: { type: 'direct' }, fullPath: '/timeline/direct' }
			mountApp()
			const [callback] = addCallback.mock.calls[0]
			callback({ source: 'timeline.direct', payload: status })
			expect(dispatch).toHaveBeenCalledWith('addToTimeline', [status])
			dispatch.mockClear()
			callback({ source: 'timeline.home', payload: status })
			expect(dispatch).not.toHaveBeenCalledWith('addToTimeline', expect.anything())
		})

		it('does not register when the push app is disabled', () => {
			globalThis.OCA.Push = { isEnabled: () => false, addCallback }
			mountApp()
			expect(addCallback).not.toHaveBeenCalled()
		})
	})

	describe('before the setup is finished', () => {
		it('lets administrators enter the ActivityPub base URL and finish the setup', async () => {
			setServerData({ setup: true, isAdmin: true })
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const wrapper = mountApp()

			expect(wrapper.find('.navigation-stub').exists()).toBe(false)
			expect(wrapper.find('.router-view-stub').exists()).toBe(false)
			expect(wrapper.find('.setup h2').text()).toBe('Social app setup')
			const input = wrapper.find('input.setup-input')
			expect(input.attributes('placeholder')).toBe('https://cloud.example.org')
			expect(input.attributes('required')).toBeDefined()
			expect(wrapper.find('.setup h3').exists()).toBe(false)

			await input.setValue('https://social.example.org')
			await wrapper.find('form').trigger('submit')
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/config/cloudAddress', { cloudAddress: 'https://social.example.org' })

			await flushPromises()
			// the mutation takes an object payload, so both the flag and the
			// address are actually stored (the address was dropped by the old bug)
			expect(store.state.settings.serverData.setup).toBe(false)
			expect(store.state.settings.serverData.cloudAddress).toBe('https://social.example.org')
			expect(wrapper.find('.setup h2').exists()).toBe(false)
			expect(wrapper.find('.router-view-stub').exists()).toBe(true)
		})

		it('also shows the .well-known warning on the setup form', () => {
			setServerData({ setup: true, isAdmin: true, checks: { success: false, checks: { wellknown: false } } })
			expect(mountApp().find('form h3').text()).toBe('.well-known/webfinger isn\'t properly set up!')
		})

		it('tells regular users to wait for the administrator', () => {
			setServerData({ setup: true, isAdmin: false })
			const wrapper = mountApp()
			expect(wrapper.find('.setup').text()).toBe('The Social app needs to be set up by the server administrator.')
			expect(wrapper.find('form').exists()).toBe(false)
			expect(wrapper.find('.router-view-stub').exists()).toBe(false)
		})
	})
})
