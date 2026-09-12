/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import Navigation from '../../../src/components/Navigation.vue'
import appRouter from '../../../src/router.js'
import axios from '@nextcloud/axios'
import { useErrorsStore } from '../../../src/store/errors.js'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

// Simple stand-ins for the @nextcloud/vue navigation shell that render their
// slots and expose the props the component drives them with.
const stubs = {
	NcAppNavigation: { template: '<nav><slot name="search" /><slot name="list" /><slot name="footer" /></nav>' },
	NcAppNavigationSearch: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input class="nav-search" :value="modelValue" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	// `counter` is a slot in @nextcloud/vue 9, not a prop, and there is no
	// `subname` slot — the app's extras go through `extra`
	NcAppNavigationItem: {
		props: ['name', 'active', 'href', 'target', 'to'],
		emits: ['click'],
		template: '<li class="nav-item" :class="{ active }" :data-name="name" :data-href="href" :data-to="to && JSON.stringify(to)" @click="$emit(\'click\', $event)">'
			+ '<slot name="icon" /><span class="nav-item__name">{{ name }}</span>'
			+ '<span class="nav-item__counter"><slot name="counter" /></span>'
			+ '<slot name="extra" /><slot /></li>',
	},
	NcCounterBubble: { props: ['count', 'type'], template: '<span class="nc-counter" :data-count="count">{{ count }}</span>' },
	NcAppNavigationSpacer: { template: '<hr>' },
	NcAppNavigationSettings: { props: ['name'], template: '<div class="nav-settings" :data-name="name"><slot /></div>' },
	NcAvatar: { props: ['user', 'displayName', 'size'], template: '<span class="nc-avatar-stub" :data-user="user" :data-size="size" />' },
	NcModal: { props: ['name'], emits: ['close'], template: '<div class="modal-stub" :data-name="name"><slot /></div>' },
	Composer: { emits: ['posted'], template: '<div class="composer-stub" @click="$emit(\'posted\')" />' },
}

let pinia
let errorsStore
let notificationsStore
let settingsStore
let router

function mountNavigation(options = {}, route = { name: 'timeline', params: {} }) {
	if (options.unread !== undefined) {
		notificationsStore.setUnreadNotifications(options.unread)
	}

	return mount(Navigation, {
		global: { plugins: [pinia], mocks: { $route: route, $router: router }, stubs },
	})
}

const items = (wrapper) => wrapper.findAll('.nav-item')
const itemNames = (wrapper) => items(wrapper).map((item) => item.attributes('data-name'))
const item = (wrapper, name) => items(wrapper).find((candidate) => candidate.attributes('data-name') === name)
const activeNames = (wrapper) => items(wrapper).filter((candidate) => candidate.classes('active')).map((candidate) => candidate.attributes('data-name'))
// what the footer's collapsible menu holds, as against the sidebar's top level
const moreMenu = (wrapper) => wrapper.find('.nav-settings')
const moreNames = (wrapper) => moreMenu(wrapper).findAll('.nav-item').map((candidate) => candidate.attributes('data-name'))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: [] })) },
}))

describe('Navigation', () => {
	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		errorsStore = useErrorsStore()
		notificationsStore = useNotificationsStore()
		settingsStore = useSettingsStore()
		errorsStore.clearErrors()
		settingsStore.setServerData({ public: false, cloudAddress: 'https://cloud.example.org' })
		router = {
			push: vi.fn(),
			resolve: vi.fn((to) => ({ href: '/resolved/' + to.name + (to.params?.type ? '/' + to.params.type : '') })),
		}
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('lists the fixed entries in order, without an errors entry when there are none', () => {
		expect(itemNames(mountNavigation())).toEqual([
			'New post',
			'Home',
			'Photos',
			'Notifications',
			'Direct messages',
			'Local',
			'Global',
			'Alice',
			'Follow requests',
			'Liked posts',
			'Bookmarks',
			'Blocked and muted accounts',
		])
	})

	/**
	 * The top level is for the timelines a reader moves between all day. The
	 * three below are things they go looking for, and eight equal-weight
	 * entries made the first five harder to pick out.
	 */
	it('keeps the timelines at the top level and the rest under More', () => {
		const wrapper = mountNavigation()

		expect(moreMenu(wrapper).attributes('data-name')).toBe('More')
		expect(moreNames(wrapper)).toEqual([
			'Follow requests',
			'Liked posts',
			'Bookmarks',
			'Blocked and muted accounts',
		])

		const topLevel = itemNames(wrapper).filter((name) => !moreNames(wrapper).includes(name))
		expect(topLevel).toEqual([
			'New post',
			'Home',
			'Photos',
			'Notifications',
			'Direct messages',
			'Local',
			'Global',
			'Alice',
		])
	})

	it.each([
		['Home', { name: 'timeline' }],
		['Notifications', { name: 'timeline', params: { type: 'notifications' } }],
		['Direct messages', { name: 'timeline', params: { type: 'direct' } }],
		['Local', { name: 'timeline', params: { type: 'timeline' } }],
		['Global', { name: 'timeline', params: { type: 'federated' } }],
		['Liked posts', { name: 'timeline', params: { type: 'favourites' } }],
		['Follow requests', { name: 'follow-requests' }],
		['Bookmarks', { name: 'timeline', params: { type: 'bookmarks' } }],
		['Alice', { name: 'profile', params: { account: 'alice' } }],
	])('points the %s entry at its route', async (name, to) => {
		// an href so it is a real link, and a click that stays in the app: with
		// `to` the component ORs vue-router's own idea of active into the entry,
		// and /timeline counts as active for every /timeline/* page
		const entry = item(mountNavigation(), name)

		expect(entry.attributes('data-href')).toBe(router.resolve(to).href)

		await entry.trigger('click')
		expect(router.push).toHaveBeenCalledWith(to)
	})

	it('offers the blocked and muted accounts in the settings section', () => {
		const wrapper = mountNavigation()
		const entry = item(wrapper, 'Blocked and muted accounts')

		expect(entry.attributes('data-href')).toBe(router.resolve({ name: 'blocked-accounts' }).href)
		expect(wrapper.find('.nav-settings').text()).toContain('Blocked and muted accounts')
	})

	describe('what the instance is talking about', () => {
		const tag = (name, uses) => ({
			name,
			url: `https://cloud.example.org/timeline/tags/${name}`,
			history: [{ day: '1757280000', uses: String(uses), accounts: '0' }],
		})

		it('lists the trending hashtags with how often they were used', async () => {
			axios.get.mockResolvedValueOnce({ data: [tag('nextcloud', 12), tag('fediverse', 3)] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(
				'/index.php/apps/social/api/v1/trends/tags',
				{ params: { limit: 5 } },
			)
			const trends = wrapper.findAll('.nav-item').filter((item) => item.attributes('data-name')?.startsWith('#'))
			expect(trends.map((item) => item.attributes('data-name'))).toEqual(['#nextcloud', '#fediverse'])
			expect(trends[0].text()).toContain('12')
		})

		it('points a trending tag at its timeline', async () => {
			axios.get.mockResolvedValueOnce({ data: [tag('nextcloud', 12)] })
			const wrapper = mountNavigation()
			await flushPromises()

			const to = { name: 'tags', params: { tag: 'nextcloud' } }
			await item(wrapper, '#nextcloud').trigger('click')

			expect(router.push).toHaveBeenCalledWith(to)
			expect(item(wrapper, '#nextcloud').attributes('data-href')).toBe(router.resolve(to).href)
		})

		it('leaves the section out on a quiet instance', async () => {
			axios.get.mockResolvedValueOnce({ data: [] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(wrapper.findAll('.nav-item').filter((entry) => entry.attributes('data-name')?.startsWith('#'))).toHaveLength(0)
		})

		it('says nothing when the counts cannot be read', async () => {
			axios.get.mockRejectedValueOnce(new Error('boom'))
			const wrapper = mountNavigation()
			await flushPromises()

			// a sidebar section is not worth an error message
			expect(wrapper.findAll('.nav-item').filter((entry) => entry.attributes('data-name')?.startsWith('#'))).toHaveLength(0)
		})
	})

	it('shows how many notifications are waiting, through the counter slot', () => {
		// `:counter="…"` was silently ignored in @nextcloud/vue 9, so the
		// badge never appeared at all
		expect(item(mountNavigation(), 'Notifications').find('.nc-counter').exists()).toBe(false)
		expect(item(mountNavigation({ unread: 5 }), 'Notifications').find('.nc-counter').attributes('data-count')).toBe('5')
	})

	// Routes come from the real router rather than being written out here: an
	// optional param the URL leaves out arrives as '', and a hand-written
	// `params: {}` hid that difference — which is how Home came to be the one
	// page that never highlighted itself.
	it.each([
		['/timeline', 'Home'],
		['/timeline/', 'Home'],
		['/timeline/notifications', 'Notifications'],
		['/timeline/direct', 'Direct messages'],
		['/timeline/timeline', 'Local'],
		['/timeline/federated', 'Global'],
		['/timeline/favourites', 'Liked posts'],
		['/timeline/bookmarks', 'Bookmarks'],
		['/follow_requests', 'Follow requests'],
		['/@alice', 'Alice'],
		['/@alice/followers', 'Alice'],
		['/@alice/following', 'Alice'],
	])('marks only one entry active on %s', (path, active) => {
		const wrapper = mountNavigation({}, appRouter.resolve(path))
		expect(activeNames(wrapper)).toEqual([active])
	})

	it.each([
		['/timeline/tags/nextcloud'],
		['/@alice/112000000000000001'],
	])('marks no entry active on %s, which no entry stands for', (path) => {
		expect(activeNames(mountNavigation({}, appRouter.resolve(path)))).toEqual([])
	})

	it('does not mark the own profile entry active for somebody else\'s profile', () => {
		const wrapper = mountNavigation({}, appRouter.resolve('/@bob@remote.example'))
		expect(activeNames(wrapper)).toEqual([])
	})

	it('names the profile entry after the reader, not after their login', () => {
		// it read "Profile" with the Nextcloud login name pushed to the far right
		// of the row, which is neither the name they publish under nor their handle
		const profile = item(mountNavigation(), 'Alice')

		expect(profile.attributes('data-name')).toBe('Alice')
		expect(profile.text()).not.toContain('@alice')
	})

	it('gives the profile entry a portrait big enough to recognise', () => {
		const avatar = item(mountNavigation(), 'Alice').find('.nc-avatar-stub')

		expect(avatar.attributes('data-user')).toBe('alice')
		expect(Number(avatar.attributes('data-size'))).toBeGreaterThanOrEqual(32)
	})

	it('emits the search term once the typing settles, not per keystroke', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = mountNavigation()
			await wrapper.find('.nav-search').setValue('next')
			await wrapper.find('.nav-search').setValue('nextcloud')
			// searching now costs a request; un-debounced it was one per letter
			expect(wrapper.emitted('search')).toBeUndefined()

			vi.advanceTimersByTime(300)
			expect(wrapper.emitted('search')).toEqual([['nextcloud']])
		} finally {
			vi.useRealTimers()
		}
	})

	it('opens the composer modal from "New post"', async () => {
		const wrapper = mountNavigation()
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
		await item(wrapper, 'New post').trigger('click')
		const modal = wrapper.find('.modal-stub')
		expect(modal.attributes('data-name')).toBe('New post')
		expect(modal.find('.composer-stub').exists()).toBe(true)
	})

	it('closes the composer modal once the post is away', async () => {
		const wrapper = mountNavigation()
		await item(wrapper, 'New post').trigger('click')
		expect(wrapper.find('.modal-stub').exists()).toBe(true)

		// the composer cleared its box and the modal stayed open, which reads
		// as if nothing had been sent
		await wrapper.find('.composer-stub').trigger('click')

		expect(wrapper.find('.modal-stub').exists()).toBe(false)
	})

	it('offers nothing more in the footer menu than what it says it does', () => {
		const wrapper = mountNavigation()
		expect(moreMenu(wrapper).attributes('data-name')).toBe('More')

		// the cache reset posted to a route that never existed, and the help
		// link pointed at a personal fork; both are gone
		expect(item(wrapper, 'Reset local cache')).toBeUndefined()
		expect(item(wrapper, 'Help & documentation')).toBeUndefined()
		expect(wrapper.emitted('reset-cache')).toBeUndefined()
	})

	describe('errors', () => {
		beforeEach(() => {
			errorsStore.addError({ title: 'Account lookup failed', message: 'Could not load bob' })
			errorsStore.addError({ title: 'Post failed', message: 'Server unreachable' })
		})

		it('adds an errors entry with the error count', () => {
			const wrapper = mountNavigation()
			expect(itemNames(wrapper).slice(0, 3)).toEqual(['New post', 'Errors', 'Home'])
			expect(item(wrapper, 'Errors').find('.nc-counter').attributes('data-count')).toBe('2')
		})

		it('opens a modal listing the errors and dismisses a single one through the store', async () => {
			const dismiss = vi.spyOn(errorsStore, 'dismissAppError')
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')

			const modal = wrapper.find('.modal-stub[data-name="Errors"]')
			expect(modal.findAll('.modal-errors__title').map((title) => title.text())).toEqual(['Account lookup failed', 'Post failed'])
			expect(modal.findAll('.modal-errors__message').map((message) => message.text())).toEqual(['Could not load bob', 'Server unreachable'])

			const [firstError] = errorsStore.appErrors
			await modal.find('.modal-errors__item button').trigger('click')
			expect(dismiss).toHaveBeenCalledWith(firstError.id)
			await nextTick()
			expect(modal.findAll('.modal-errors__title').map((title) => title.text())).toEqual(['Post failed'])
			expect(item(wrapper, 'Errors').find('.nc-counter').attributes('data-count')).toBe('1')
		})

		it('offers "Dismiss all" only for several errors and clears them all', async () => {
			const clear = vi.spyOn(errorsStore, 'clearErrors')
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')

			const dismissAll = () => wrapper.findAll('.modal-stub button').filter((button) => button.text() === 'Dismiss all')
			expect(dismissAll()).toHaveLength(1)

			await dismissAll()[0].trigger('click')
			expect(clear).toHaveBeenCalled()
			await nextTick()
			expect(wrapper.find('.modal-errors__item').exists()).toBe(false)
			expect(dismissAll()).toHaveLength(0)
			expect(item(wrapper, 'Errors')).toBeUndefined()
		})

		it('hides "Dismiss all" when only one error is left', async () => {
			errorsStore.dismissError(errorsStore.appErrors[1].id)
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')
			expect(wrapper.findAll('.modal-stub button').map((button) => button.text())).toEqual(['Dismiss'])
		})
	})

	it('keeps the search box on the term the URL is showing', async () => {
		const searchPinia = createPinia()
		setActivePinia(searchPinia)
		useSettingsStore().setServerData({ public: false })
		const timelineStore = useTimelineStore()
		timelineStore.setSearchQuery('nextcloud')
		const wrapper = mount(Navigation, {
			global: { plugins: [searchPinia], mocks: { $route: { name: 'search', params: { term: 'nextcloud' } }, $router: router }, stubs },
		})
		expect(wrapper.find('.nav-search').element.value).toBe('nextcloud')

		// navigating away clears the query; read once in mounted() the box kept
		// showing a term nothing was being searched for any more
		timelineStore.setSearchQuery('')
		await nextTick()
		expect(wrapper.find('.nav-search').element.value).toBe('')
	})
})

// The sidebar shell is stubbed everywhere above, which cannot show what the
// entries really render. These use the component the app uses.
describe('Navigation entries are links', () => {
	const realStubs = {
		NcAppNavigation: { template: '<nav><slot name="list" /><slot name="footer" /></nav>' },
		NcAppNavigationSearch: true,
		NcAppNavigationSettings: { template: '<div><slot /></div>' },
		NcAvatar: true,
	}

	const mountReal = async (path = '/timeline') => {
		const realPinia = createPinia()
		setActivePinia(realPinia)
		useSettingsStore().setServerData({ public: false })
		await appRouter.push(path)
		await appRouter.isReady()

		return mount(Navigation, { global: { plugins: [realPinia, appRouter], stubs: realStubs } })
	}

	const link = (wrapper, name) => wrapper.findAll('a').find((anchor) => anchor.text().startsWith(name))

	it.each([
		['Home', '/index.php/apps/social/timeline'],
		['Notifications', '/index.php/apps/social/timeline/notifications'],
		['Direct messages', '/index.php/apps/social/timeline/direct'],
		['Local', '/index.php/apps/social/timeline/timeline'],
		['Global', '/index.php/apps/social/timeline/federated'],
		['Follow requests', '/index.php/apps/social/follow_requests'],
		['Liked posts', '/index.php/apps/social/timeline/favourites'],
		['Bookmarks', '/index.php/apps/social/timeline/bookmarks'],
		['Alice', '/index.php/apps/social/@alice'],
		['Blocked and muted accounts', '/index.php/apps/social/blocked'],
	])('gives %s a real href', async (name, href) => {
		expect(link(await mountReal(), name).attributes('href')).toBe(href)
	})

	it('lights exactly one entry, whichever page is open', async () => {
		// NcAppNavigationItem ORs its own router-derived active state with the
		// `active` prop, and vue-router counts /timeline as active while
		// /timeline/direct is open — so Home stayed lit alongside whichever
		// timeline the reader had actually chosen.
		const entry = (wrapper, name) => wrapper.findAll('li').find((li) => li.text().startsWith(name))
		const lit = (wrapper, names) => names.filter((name) => entry(wrapper, name)?.find('.app-navigation-entry').classes().includes('active'))
		const names = ['Home', 'Notifications', 'Direct messages', 'Local', 'Global', 'Liked posts', 'Bookmarks']

		expect(lit(await mountReal('/timeline/direct'), names)).toEqual(['Direct messages'])
		expect(lit(await mountReal('/timeline'), names)).toEqual(['Home'])
		expect(lit(await mountReal('/timeline/favourites'), names)).toEqual(['Liked posts'])
	})

	it('does not let the browser follow the anchor as well', async () => {
		// NcAppNavigationItem falls back to href="#" for an entry with no `to`,
		// and only calls preventDefault() when it has one. Driven from a click
		// handler instead, the fragment navigation that followed the click
		// reached vue-router as a popstate and cancelled the route change that
		// the click had just started — which is why "Follow requests", whose
		// chunk still had to be fetched, went nowhere while every entry on the
		// already-loaded timeline chunk resolved before the popstate landed.
		const wrapper = await mountReal()
		const event = new MouseEvent('click', { bubbles: true, cancelable: true })
		link(wrapper, 'Follow requests').element.dispatchEvent(event)
		await flushPromises()

		expect(event.defaultPrevented).toBe(true)
		// the view is a lazy chunk, so the navigation lands a few ticks later
		await vi.waitFor(() => expect(appRouter.currentRoute.value.name).toBe('follow-requests'))
	})

	it('opens the composer without navigating anywhere', async () => {
		const wrapper = await mountReal()
		const event = new MouseEvent('click', { bubbles: true, cancelable: true })
		link(wrapper, 'New post').element.dispatchEvent(event)
		await flushPromises()

		// nothing to route to, so the bare href="#" must not become a history entry
		expect(event.defaultPrevented).toBe(true)
		expect(appRouter.currentRoute.value.name).toBe('timeline')
	})
})
