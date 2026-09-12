/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, shallowRef } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import App from '../../src/App.vue'
import ShortcutHelp from '../../src/components/ShortcutHelp.vue'
import eventBus from '../../src/services/eventBus.js'
import { useAccountStore } from '../../src/store/account.js'
import { useSettingsStore } from '../../src/store/settings.js'
import { useTimelineStore } from '../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const stubs = {
	NcContent: { props: ['appName'], template: '<div class="content-stub" :data-app-name="appName"><slot /></div>' },
	NcAppContent: { template: '<main class="app-content-stub"><slot /></main>' },
	Navigation: { emits: ['search'], template: '<nav class="navigation-stub" />' },
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

let pinia
let accountStore
let settingsStore
let timelineStore
let route
let router

function setServerData(overrides = {}) {
	setInitialState('social', 'serverData', { ...baseServerData, ...overrides })
	window._nc_initial_state?.clear()
}

function makeStore() {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	settingsStore = useSettingsStore()
	timelineStore = useTimelineStore()
	// the network calls the App would make on mount, and the two actions the
	// assertions below watch for
	vi.spyOn(accountStore, 'fetchAccountInfo').mockResolvedValue(undefined)
	// watched, not replaced: what they do to the store is part of the assertions
	vi.spyOn(accountStore, 'fetchCurrentAccountInfo')
	vi.spyOn(timelineStore, 'addToTimeline')
	vi.spyOn(timelineStore, 'refreshTimeline').mockResolvedValue(undefined)
	vi.spyOn(timelineStore, 'fetchTimeline').mockResolvedValue(undefined)

	return pinia
}

// $route is exposed through a getter so swapping the ref triggers the
// component's $route watcher like vue-router would.
const routePlugin = {
	install(app) {
		Object.defineProperty(app.config.globalProperties, '$route', { get: () => route.value, configurable: true })
	},
}

function mountApp() {
	return mount(App, {
		global: { plugins: [pinia, routePlugin], mocks: { $router: router }, stubs },
	})
}

describe('App', () => {
	beforeEach(() => {
		makeStore()
		route = shallowRef({ name: 'timeline', params: {}, fullPath: '/timeline' })
		router = { push: vi.fn() }
		setServerData()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.OCA.Push
	})

	describe('keyboard shortcuts', () => {
		afterEach(() => {
			eventBus.all.clear()
		})

		it('opens and closes the shortcut sheet on ?', async () => {
			const wrapper = mountApp()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(false)

			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(true)

			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(false)
		})

		it('lists every shortcut with its keys and what it does', () => {
			const wrapper = mountApp()
			const shortcuts = wrapper.findComponent(ShortcutHelp).vm.shortcuts

			expect(shortcuts.length).toBeGreaterThan(5)
			for (const shortcut of shortcuts) {
				expect(shortcut.keys.length).toBeGreaterThan(0)
				expect(shortcut.label).toBeTruthy()
			}
		})

		it('stops listening once the app is gone', () => {
			const wrapper = mountApp()
			wrapper.unmount()

			expect(() => eventBus.emit('shortcut:help')).not.toThrow()
		})
	})

	it('imports the server data and loads the current account', () => {
		mountApp()
		expect(settingsStore.serverData).toEqual(baseServerData)
		expect(accountStore.fetchCurrentAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
		expect(accountStore.currentAccountHandle).toBe('alice@cloud.example.org')
		// the action asks the account store for the lookup itself
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledTimes(1)
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
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
		expect(accountStore.fetchCurrentAccountInfo).not.toHaveBeenCalled()
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

	describe('searching', () => {
		it('takes the term to the search route rather than filtering what is loaded', () => {
			const wrapper = mountApp()
			wrapper.findComponent(stubs.Navigation).vm.$emit('search', 'fediverse')

			expect(timelineStore.searchQuery).toBe('fediverse')
			expect(router.push).toHaveBeenCalledWith({ name: 'search', params: { term: 'fediverse' } })
		})

		it('refines the term in place, so Back does not walk out through the keystrokes', () => {
			route = shallowRef({ name: 'search', params: { term: 'fedi' }, fullPath: '/search/fedi' })
			router.replace = vi.fn()
			const wrapper = mountApp()

			wrapper.findComponent(stubs.Navigation).vm.$emit('search', 'fediverse')

			expect(router.replace).toHaveBeenCalledWith({ name: 'search', params: { term: 'fediverse' } })
			expect(router.push).not.toHaveBeenCalled()
		})

		it('leaves the search route when the box is emptied', () => {
			route = shallowRef({ name: 'search', params: { term: 'fedi' }, fullPath: '/search/fedi' })
			const wrapper = mountApp()

			wrapper.findComponent(stubs.Navigation).vm.$emit('search', '   ')

			expect(timelineStore.searchQuery).toBe('')
			expect(router.push).toHaveBeenCalledWith({ name: 'timeline' })
		})

		it('goes nowhere when the box is emptied off the search route', () => {
			const wrapper = mountApp()
			wrapper.findComponent(stubs.Navigation).vm.$emit('search', '')

			expect(router.push).not.toHaveBeenCalled()
		})

		it('keeps the stored term in step with the route', async () => {
			mountApp()

			route.value = { name: 'search', params: { term: 'fediverse' }, fullPath: '/search/fediverse' }
			await nextTick()
			expect(timelineStore.searchQuery).toBe('fediverse')

			route.value = { name: 'timeline', params: { type: 'federated' }, fullPath: '/timeline/federated' }
			await nextTick()
			expect(timelineStore.searchQuery).toBe('')
		})
	})

	it('does not remount the whole view on every route change', () => {
		// :key="$route.fullPath" refetched page one of the timeline and
		// landed at the top for every navigation, Back included
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')
		expect(source).not.toMatch(/<router-view[^>]*:key=/)
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
			expect(timelineStore.addToTimeline).toHaveBeenCalledWith([status])
			expect(timelineStore.timeline).toEqual(['s1'])

			timelineStore.addToTimeline.mockClear()
			callback({ source: 'timeline.direct', payload: { ...status, id: 's2' } })
			expect(timelineStore.addToTimeline).not.toHaveBeenCalled()
		})

		it('adds pushed direct messages on the direct timeline', () => {
			route.value = { name: 'timeline', params: { type: 'direct' }, fullPath: '/timeline/direct' }
			mountApp()
			const [callback] = addCallback.mock.calls[0]
			callback({ source: 'timeline.direct', payload: status })
			expect(timelineStore.addToTimeline).toHaveBeenCalledWith([status])
			timelineStore.addToTimeline.mockClear()
			callback({ source: 'timeline.home', payload: status })
			expect(timelineStore.addToTimeline).not.toHaveBeenCalled()
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
			expect(settingsStore.serverData.setup).toBe(false)
			expect(settingsStore.serverData.cloudAddress).toBe('https://social.example.org')
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
