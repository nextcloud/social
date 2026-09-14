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
import eventBus, { LISTS_CHANGED } from '../../../src/services/eventBus.js'
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
			+ '<span class="nav-item__icon"><slot name="icon" /></span><span class="nav-item__name">{{ name }}</span>'
			+ '<span class="nav-item__counter"><slot name="counter" /></span>'
			+ '<slot name="extra" /><span class="nav-item__actions"><slot name="actions" /></span><slot /></li>',
	},
	NcCounterBubble: { props: ['count', 'type'], template: '<span class="nc-counter" :data-count="count">{{ count }}</span>' },
	NcAppNavigationSpacer: { template: '<hr>' },
	// the caption's own menu lives in a popover it only fills once opened
	NcAppNavigationCaption: { props: ['name'], template: '<h3 class="nav-caption">{{ name }}<slot name="actions" /></h3>' },
	NcActionButton: { emits: ['click'], template: '<button class="nav-caption__action" @click="$emit(\'click\')"><slot /></button>' },
	NcAppNavigationSettings: { props: ['name'], template: '<div class="nav-settings" :data-name="name"><slot /></div>' },
	NcAvatar: { props: ['user', 'displayName', 'size'], template: '<span class="nc-avatar-stub" :data-user="user" :data-size="size" />' },
	NcModal: { props: { name: String, closeOnClickOutside: Boolean }, emits: ['close'], template: '<div class="modal-stub" :data-name="name" :data-closes-on-outside-click="closeOnClickOutside ? \'yes\' : \'no\'"><slot /></div>' },
	Composer: { props: ['initialPaths'], emits: ['posted'], template: '<div class="composer-stub" :data-paths="JSON.stringify(initialPaths)" @click="$emit(\'posted\')" />' },
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

// The sidebar's requests wait for the timeline's first one (services/boot.js,
// tested on its own). Here the wait is a hook the tests can hold: it runs the
// callback at once unless a test takes it to see what is held back.
const boot = vi.hoisted(() => ({ held: [], immediate: true }))
vi.mock('../../../src/services/boot.js', () => ({
	afterFirstTimeline: (callback) => {
		if (boot.immediate) {
			callback()
		} else {
			boot.held.push(callback)
		}
	},
}))
// the instance limits are asked for alongside; not what these tests are about
vi.mock('../../../src/services/instanceLimits.js', () => ({
	DEFAULT_LIMITS: { maxCharacters: 500, maxAttachments: 10 },
	loadLimits: vi.fn(async () => ({ maxCharacters: 500, maxAttachments: 10 })),
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
			replace: vi.fn(),
			resolve: vi.fn((to) => ({ href: '/resolved/' + to.name + (to.params?.type ? '/' + to.params.type : '') })),
		}
	})

	afterEach(() => {
		vi.restoreAllMocks()
		boot.immediate = true
		boot.held = []
	})

	describe('what waits for the timeline', () => {
		it('sends none of its own requests until the timeline has had its turn', async () => {
			boot.immediate = false
			axios.get.mockClear()
			const fetchUnread = vi.spyOn(notificationsStore, 'fetchUnreadNotifications').mockResolvedValue(undefined)

			mountNavigation()
			await flushPromises()

			// trending, lists, the badge: all of them wait
			expect(axios.get).not.toHaveBeenCalled()
			expect(fetchUnread).not.toHaveBeenCalled()
			expect(boot.held.length).toBeGreaterThan(0)

			boot.held.forEach((release) => release())
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/trends/tags', expect.anything())
			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/lists')
			expect(fetchUnread).toHaveBeenCalledTimes(1)
		})
	})

	describe('arriving from "Share to Social" in the Files app', () => {
		// the dialog opens from mounted(), which renders a tick later
		const arriving = async (attach) => {
			const wrapper = mountNavigation({}, { name: 'timeline', params: { type: 'home' }, path: '/timeline/home', query: { attach } })
			await flushPromises()
			return wrapper
		}

		it('opens the New post dialog with the files already handed to the composer', async () => {
			const wrapper = await arriving(['/Photos/beach.jpg', '/Videos/talk.mp4'])

			const dialog = wrapper.find('.modal-stub[data-name="New post"]')
			expect(dialog.exists()).toBe(true)
			expect(JSON.parse(dialog.find('.composer-stub').attributes('data-paths'))).toEqual(['/Photos/beach.jpg', '/Videos/talk.mp4'])
		})

		it('takes one file as readily as several', async () => {
			const wrapper = await arriving('/Photos/beach.jpg')

			expect(JSON.parse(wrapper.find('.composer-stub').attributes('data-paths'))).toEqual(['/Photos/beach.jpg'])
		})

		it('takes the files off the address, so a reload does not attach them twice', async () => {
			await arriving(['/Photos/beach.jpg'])

			expect(router.replace).toHaveBeenCalledWith(expect.objectContaining({ query: {} }))
		})

		it('starts the next New post empty', async () => {
			const wrapper = await arriving(['/Photos/beach.jpg'])

			await wrapper.find('.composer-stub').trigger('click')
			expect(wrapper.find('.modal-stub[data-name="New post"]').exists()).toBe(false)
			await wrapper.find('.navigation__compose').trigger('click')
			expect(JSON.parse(wrapper.find('.composer-stub').attributes('data-paths'))).toEqual([])
		})

		it('closes when the reader clicks the page behind it', async () => {
			// the prop is a boolean with no default, so leaving it off meant
			// only the X closed the dialog. Nothing is lost by closing: the
			// draft is saved as it is typed and restored on the next open.
			const wrapper = await arriving(['/Photos/beach.jpg'])

			expect(wrapper.find('.modal-stub[data-name="New post"]').attributes('data-closes-on-outside-click'))
				.toBe('yes')
		})

		it('opens nothing without files on the address', () => {
			expect(mountNavigation().find('.modal-stub[data-name="New post"]').exists()).toBe(false)
			expect(router.replace).not.toHaveBeenCalled()
		})
	})

	describe('explore', () => {
		const list = (id, title, group = null) => ({ id: String(id), title, replies_policy: 'list', exclusive: false, nextcloud_group: group })
		const tag = (name) => ({ name, url: `https://cloud.example.org/tags/${name}`, following: true })

		/** Keyed on the URL rather than on call order: three reads fire here. */
		const withExplore = ({ tags = [], lists = [] } = {}) => {
			axios.get.mockImplementation((url) => Promise.resolve({
				data: url.endsWith('/followed_tags') ? tags : (url.endsWith('/lists') ? lists : []),
			}))
		}

		const listItems = (wrapper) => wrapper.findAll('.nav-item.navigation__list')
		const tagItems = (wrapper) => wrapper.findAll('.nav-item.navigation__trend')
		const explore = (wrapper) => wrapper.findAll('.nav-item.navigation__explore')[0]

		// jsdom is 768 tall, which has room for seven; these tests are about
		// what is shown rather than about how much fits, so they get a screen
		// with room for all twelve
		const TALL = 1200
		const originalHeight = window.innerHeight

		beforeEach(() => {
			window.innerHeight = TALL
		})

		afterEach(() => {
			window.innerHeight = originalHeight
		})

		it('gathers the hashtags and the lists into one entry', async () => {
			withExplore({ tags: [tag('a11y')], lists: [list(1, 'Friends')] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/lists')
			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/followed_tags')
			expect(explore(wrapper).attributes('data-name')).toBe('Explore')
			expect(tagItems(wrapper).map((e) => e.attributes('data-name'))).toEqual(['#a11y'])
			expect(listItems(wrapper).map((e) => e.attributes('data-name'))).toEqual(['Friends'])
		})

		// the chevron is the only thing before the word
		it('carries no icon of its own', async () => {
			withExplore({ lists: [list(1, 'Friends')] })
			const wrapper = mountNavigation()
			await flushPromises()

			// its own icon slot, not the children's: they keep theirs
			expect(explore(wrapper).find('.nav-item__icon').element.children).toHaveLength(0)
			expect(listItems(wrapper)[0].find('.material-design-icon').exists()).toBe(true)
		})

		// the word and the chevron, nothing else
		it('carries no count', async () => {
			withExplore({ tags: [tag('a11y'), tag('nextcloud')], lists: [list(1, 'Friends')] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(explore(wrapper).find('.nc-counter').exists()).toBe(false)
		})

		it('shows what it has room for out of everything there is', async () => {
			withExplore({ tags: Array.from({ length: 20 }, (_, i) => tag(`t${i}`)), lists: [list(1, 'Friends')] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(tagItems(wrapper).length + listItems(wrapper).length).toBe(12)
		})

		it('shows no more than twelve, however many there are', async () => {
			withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(tagItems(wrapper)).toHaveLength(12)
		})

		it('shows fewer when the window is short', async () => {
			withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
			window.innerHeight = 640
			const wrapper = mountNavigation()
			await flushPromises()

			const shortened = tagItems(wrapper).length

			expect(shortened).toBeLessThan(12)
			expect(shortened).toBeGreaterThan(0)
		})

		it('shows more again when the window grows', async () => {
			withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
			window.innerHeight = 640
			const wrapper = mountNavigation()
			await flushPromises()
			const shortened = tagItems(wrapper).length

			window.innerHeight = 1200
			window.dispatchEvent(new Event('resize'))
			await nextTick()

			expect(tagItems(wrapper).length).toBeGreaterThan(shortened)
		})

		it('keeps a place for each kind when it has to choose', async () => {
			withExplore({ tags: Array.from({ length: 30 }, (_, i) => tag(`t${i}`)), lists: [list(1, 'Friends')] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(listItems(wrapper)).toHaveLength(1)
			expect(tagItems(wrapper).length).toBeGreaterThan(0)
		})

		it('puts the lists their groups give them first', async () => {
			withExplore({ lists: [list(1, 'Friends'), list(2, 'Design', 'design'), list(3, 'Berlin office', 'berlin')] })
			const wrapper = mountNavigation()
			await flushPromises()

			expect(listItems(wrapper).map((e) => e.attributes('data-name'))).toEqual(['Design', 'Berlin office', 'Friends'])
			expect(listItems(wrapper)[0].find('.material-design-icon').classes()).toContain('account-group-icon')
			expect(listItems(wrapper)[2].find('.material-design-icon').classes()).toContain('format-list-bulleted-icon')
		})

		it('points a list at its timeline', async () => {
			withExplore({ lists: [list(4, 'Design', 'design')] })
			const wrapper = mountNavigation()
			await flushPromises()

			const to = { name: 'list', params: { id: '4' } }
			await item(wrapper, 'Design').trigger('click')

			expect(router.push).toHaveBeenCalledWith(to)
			expect(item(wrapper, 'Design').attributes('data-href')).toBe(router.resolve(to).href)
		})

		it('points a hashtag at its timeline', async () => {
			withExplore({ tags: [tag('a11y')] })
			const wrapper = mountNavigation()
			await flushPromises()

			await item(wrapper, '#a11y').trigger('click')

			expect(router.push).toHaveBeenCalledWith({ name: 'tags', params: { tag: 'a11y' } })
		})

		it('lights the list being read', async () => {
			withExplore({ lists: [list(4, 'Design', 'design'), list(5, 'Friends')] })
			const wrapper = mountNavigation({}, { name: 'list', params: { id: '4' } })
			await flushPromises()

			expect(activeNames(wrapper)).toEqual(['Design'])
		})

		it('offers the way to where lists are made', async () => {
			withExplore({ lists: [list(4, 'Design', 'design')] })
			const wrapper = mountNavigation()
			await flushPromises()

			const manage = wrapper.findAll('.nav-caption__action').find((button) => button.text() === 'Manage lists')
			expect(manage).toBeDefined()
			await manage.trigger('click')

			expect(router.push).toHaveBeenCalledWith({ name: 'settings', hash: '#lists' })
		})

		/** The settings page owns them; this sidebar holds a copy of its own. */
		it('reads them again when something says they changed', async () => {
			withExplore({ lists: [list(4, 'Design', 'design')] })
			const wrapper = mountNavigation()
			await flushPromises()

			withExplore({ lists: [list(4, 'Design', 'design'), list(5, 'Book club')] })
			eventBus.emit(LISTS_CHANGED)
			await flushPromises()

			expect(listItems(wrapper).map((e) => e.attributes('data-name'))).toEqual(['Design', 'Book club'])
		})

		/**
		 * The rail is measured rather than guessed from the window: it holds
		 * things that come and go, and zoom or a denser theme changes every
		 * height at once.
		 *
		 * jsdom lays nothing out, so the heights are given here the way a
		 * browser would report them.
		 */
		describe('measuring the rail', () => {
			/**
			 * Lays the rail out the way a browser reports it.
			 *
			 * The rows carry the fixed height between them and the Explore
			 * item carries its children, because that is how the measurement
			 * reads it. `scrollHeight` is set the way a browser sets it —
			 * the content height, or the container's when nothing overflows —
			 * so that a measurement which leant on it would be caught here.
			 */
			/**
			 * Lays the rail out the way a browser reports it.
			 *
			 * The measurement reads geometry, not `offsetHeight`: where the
			 * children start, and the pitch from one row to the next. jsdom
			 * reports zero for all of it, so it is given here.
			 */
			const layOut = (wrapper, { railHeight, childrenTop, pitch, shownRows = 2 }) => {
				const item = wrapper.findAll('.nav-item.navigation__explore')[0].element
				const list = item.parentElement

				item.querySelector('.app-navigation-entry__children')?.remove()
				const children = document.createElement('ul')
				children.className = 'app-navigation-entry__children'
				for (let i = 0; i < Math.max(2, shownRows); i++) {
					const row = document.createElement('li')
					row.getBoundingClientRect = () => ({ top: childrenTop + i * pitch, height: pitch })
					children.appendChild(row)
				}
				children.getBoundingClientRect = () => ({ top: childrenTop, height: shownRows * pitch })
				item.appendChild(children)

				list.getBoundingClientRect = () => ({ top: 0, height: railHeight })
				Object.defineProperty(list, 'clientHeight', { value: railHeight, configurable: true })
				Object.defineProperty(list, 'scrollTop', { value: 0, configurable: true })
			}

			it('shows more when the rail has more room', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				layOut(wrapper, { railHeight: 900, childrenTop: 400, pitch: 42 })
				wrapper.vm.measureRail()
				await nextTick()
				const roomy = tagItems(wrapper).length

				layOut(wrapper, { railHeight: 700, childrenTop: 400, pitch: 42 })
				wrapper.vm.measureRail()
				await nextTick()
				const tight = tagItems(wrapper).length

				expect(roomy).toBeGreaterThan(tight)
			})

			/**
			 * The children are in the rail too. A measurement that counted
			 * them would grow every time it was applied and shrink every time
			 * it was read back, and the entry would flicker between two
			 * lengths for ever.
			 */
			/**
			 * It shrank correctly and then never grew back: `scrollHeight` is
			 * the content height only while the content overflows, and is the
			 * container's height otherwise — so "the rail less the children"
			 * grew with the window and left the free space pinned at whatever
			 * the children already took.
			 */
			it('grows again after it has shrunk', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				layOut(wrapper, { railHeight: 520, childrenTop: 400, pitch: 38, shownRows: 3 })
				wrapper.vm.measureRail()
				await nextTick()
				const shrunk = tagItems(wrapper).length

				layOut(wrapper, { railHeight: 900, childrenTop: 400, pitch: 38, shownRows: shrunk })
				wrapper.vm.measureRail()
				await nextTick()

				expect(tagItems(wrapper).length).toBeGreaterThan(shrunk)
			})

			it('settles rather than feeding on itself', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				layOut(wrapper, { railHeight: 900, childrenTop: 400, pitch: 42 })
				wrapper.vm.measureRail()
				await nextTick()
				const first = tagItems(wrapper).length

				// measuring again changes nothing, because the figure is taken
				// from everything except the children
				wrapper.vm.measureRail()
				await nextTick()
				wrapper.vm.measureRail()
				await nextTick()

				expect(tagItems(wrapper).length).toBe(first)
			})

			/**
			 * What the reader saw: the entries ran on past the bottom of the
			 * rail and under the account footer. The pitch from one row to the
			 * next is the figure that carries their margins — `offsetHeight`
			 * does not, and summing that let exactly the margins overflow.
			 */
			it('never asks for more rows than fit between the children and the bottom', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				const railHeight = 800
				const childrenTop = 500
				const pitch = 42
				layOut(wrapper, { railHeight, childrenTop, pitch })
				wrapper.vm.measureRail()
				await nextTick()

				const shown = tagItems(wrapper).length

				expect(childrenTop + shown * pitch).toBeLessThanOrEqual(railHeight)
			})

			// no room at all: they go rather than run under the footer
			it('shows none when there is no room left', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				layOut(wrapper, { railHeight: 520, childrenTop: 500, pitch: 42 })
				wrapper.vm.measureRail()
				await nextTick()

				expect(tagItems(wrapper).length).toBe(0)
				// the entry itself stays, so it can still be collapsed
				expect(wrapper.findAll('.nav-item.navigation__explore')).toHaveLength(1)
			})

			it('falls back to the window when there is nothing laid out', async () => {
				withExplore({ tags: Array.from({ length: 40 }, (_, i) => tag(`t${i}`)) })
				const wrapper = mountNavigation()
				await flushPromises()

				// jsdom reports 0 for every height, which is what this is
				expect(tagItems(wrapper).length).toBe(12)
			})
		})

		it('leaves the entry out for a reader with neither', async () => {
			withExplore({})
			const wrapper = mountNavigation()
			await flushPromises()

			expect(wrapper.findAll('.nav-item.navigation__explore')).toHaveLength(0)
			expect(wrapper.text()).not.toContain('Explore')
		})
	})

	it('lists the fixed entries in order, without an errors entry when there are none', () => {
		expect(itemNames(mountNavigation())).toEqual([
			'My Feed',
			'Photos',
			'Videos',
			'Activities',
			'Direct messages',
			'Discover',
			'My profile',
			'Follow requests',
			'Liked posts',
			'Bookmarks',
			'Statistics',
			'Blocked and muted accounts',
			'Settings',
		])
	})

	/**
	 * The top level is for the timelines a reader moves between all day. The
	 * rest are things they go looking for, including their own profile, and
	 * eight equal-weight entries made the first five harder to pick out.
	 */
	it('keeps the timelines at the top level and the rest under the account', () => {
		const wrapper = mountNavigation()

		expect(moreNames(wrapper)).toEqual([
			'My profile',
			'Follow requests',
			'Liked posts',
			'Bookmarks',
			'Statistics',
			'Blocked and muted accounts',
			'Settings',
		])

		const topLevel = itemNames(wrapper).filter((name) => !moreNames(wrapper).includes(name))
		expect(topLevel).toEqual([
			'My Feed',
			'Photos',
			'Videos',
			'Activities',
			'Direct messages',
			'Discover',
		])
	})

	// the account at the bottom

	/**
	 * The way out of every app in Nextcloud is the thing at the bottom with
	 * your face on it, so the menu hangs off the reader's own account rather
	 * than off the word "More".
	 */
	it('names the menu at the bottom after the reader, not "More"', () => {
		expect(moreMenu(mountNavigation()).attributes('data-name')).toBe('Alice')
	})

	/**
	 * `NcAppNavigationSettings` draws a cog and has no slot to replace it, so
	 * the picture is handed to the stylesheet instead.
	 */
	it('puts the reader\'s face on it', () => {
		const style = moreMenu(mountNavigation()).attributes('style') ?? ''

		expect(style).toContain('--social-face')
		expect(style).toContain('/avatar/alice/64')
	})

	/** Their own page is the first thing behind their own face. */
	it('keeps a way to their own profile in the menu', async () => {
		const wrapper = mountNavigation()
		const profile = item(wrapper, 'My profile')

		expect(profile.attributes('data-href')).toBe(router.resolve({ name: 'profile', params: { account: 'alice' } }).href)

		await profile.trigger('click')

		expect(router.push).toHaveBeenCalledWith({ name: 'profile', params: { account: 'alice' } })
	})

	/**
	 * They are scopes of the Home page, set by the switcher above the posts,
	 * rather than places of their own — and two ways to reach the same list,
	 * one of which says it is somewhere else, is one too many.
	 */
	it('does not list Local and Global, which the switcher sets', () => {
		const names = itemNames(mountNavigation())

		expect(names).not.toContain('Local')
		expect(names).not.toContain('Global')
	})

	it.each([
		['My Feed', { name: 'timeline' }],
		['Activities', { name: 'timeline', params: { type: 'notifications' } }],
		['Direct messages', { name: 'timeline', params: { type: 'direct' } }],
		['Liked posts', { name: 'timeline', params: { type: 'favourites' } }],
		['Statistics', { name: 'statistics' }],
		['Follow requests', { name: 'follow-requests' }],
		['Bookmarks', { name: 'timeline', params: { type: 'bookmarks' } }],
		['My profile', { name: 'profile', params: { account: 'alice' } }],
		['Settings', { name: 'settings' }],
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
		expect(item(mountNavigation(), 'Activities').find('.nc-counter').exists()).toBe(false)
		expect(item(mountNavigation({ unread: 5 }), 'Activities').find('.nc-counter').attributes('data-count')).toBe('5')
	})

	// Routes come from the real router rather than being written out here: an
	// optional param the URL leaves out arrives as '', and a hand-written
	// `params: {}` hid that difference — which is how Home came to be the one
	// page that never highlighted itself.
	it.each([
		['/timeline', 'My Feed'],
		['/timeline/', 'My Feed'],
		['/timeline/notifications', 'Activities'],
		['/timeline/direct', 'Direct messages'],
		['/timeline/timeline', 'My Feed'],
		['/timeline/federated', 'My Feed'],
		['/timeline/photos', 'Photos'],
		['/timeline/videos', 'Videos'],
		['/timeline/favourites', 'Liked posts'],
		['/timeline/bookmarks', 'Bookmarks'],
		['/follow_requests', 'Follow requests'],
		['/@alice', 'My profile'],
		['/@alice/followers', 'My profile'],
		['/@alice/following', 'My profile'],
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

	it('calls the reader by the name they publish under, not by their login', () => {
		// it read "Profile" with the Nextcloud login name pushed to the far right
		// of the row, which is neither the name they publish under nor their handle
		const menu = moreMenu(mountNavigation())

		expect(menu.attributes('data-name')).toBe('Alice')
		expect(menu.attributes('data-name')).not.toContain('@alice')
	})

	/** One account row, not two: the list above it has none. */
	it('does not also keep the account in the list above', () => {
		expect(itemNames(mountNavigation())).not.toContain('Alice')
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

	// the call to action

	/**
	 * The one thing in the sidebar that is not a place to go. It was a
	 * navigation row with `href="#"`, which needed a `.prevent` to stop the
	 * bare fragment becoming a history entry; a button has nowhere to go.
	 */
	it('offers New post as a button rather than as a row', () => {
		const wrapper = mountNavigation()
		const button = wrapper.find('.navigation__compose')

		expect(button.element.tagName).toBe('BUTTON')
		expect(button.text()).toContain('New post')
		expect(itemNames(wrapper)).not.toContain('New post')
	})

	/** It is what the app is for, so it comes before the places to go. */
	it('puts it above everything else in the sidebar', () => {
		const wrapper = mountNavigation()
		const first = wrapper.find('nav').element.querySelector('.navigation__compose, .nav-item')

		expect(first.classList.contains('navigation__compose')).toBe(true)
	})

	/**
	 * The primary variant, which is what a call to action looks like here, and
	 * the full width of the sidebar rather than a button floating in it.
	 */
	it('looks like the call to action it is', () => {
		const button = mountNavigation().find('.navigation__compose')

		expect(button.classes()).toContain('button-vue--primary')
		expect(button.classes()).toContain('button-vue--wide')
	})

	it('opens the composer modal from "New post"', async () => {
		const wrapper = mountNavigation()
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
		await wrapper.find('.navigation__compose').trigger('click')
		const modal = wrapper.find('.modal-stub')
		expect(modal.attributes('data-name')).toBe('New post')
		expect(modal.find('.composer-stub').exists()).toBe(true)
	})

	it('closes the composer modal once the post is away', async () => {
		const wrapper = mountNavigation()
		await wrapper.find('.navigation__compose').trigger('click')
		expect(wrapper.find('.modal-stub').exists()).toBe(true)

		// the composer cleared its box and the modal stayed open, which reads
		// as if nothing had been sent
		await wrapper.find('.composer-stub').trigger('click')

		expect(wrapper.find('.modal-stub').exists()).toBe(false)
	})

	it('offers nothing more in the footer menu than what it says it does', () => {
		const wrapper = mountNavigation()
		expect(moreMenu(wrapper).attributes('data-name')).toBe('Alice')

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
			expect(itemNames(wrapper).slice(0, 2)).toEqual(['Errors', 'My Feed'])
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

	/**
	 * The account menu's rows slide in when it opens, and the stylesheet drives
	 * that off `aria-expanded` on the accordion's own button — the component
	 * offers no `open` prop and no event, and its internal class names are
	 * content-hashed. If a release of @nextcloud/vue ever stops rendering that
	 * attribute, or moves the entries out from under `.navigation__more`, the
	 * animation dies silently and nothing else would notice.
	 */
	describe('the hook the account menu animates from', () => {
		// the real accordion, not the stub the other tests use: the point here
		// is precisely what @nextcloud/vue renders
		const mountWithRealMenu = async () => {
			const realPinia = createPinia()
			setActivePinia(realPinia)
			useSettingsStore().setServerData({ public: false })
			await appRouter.push('/timeline')
			await appRouter.isReady()

			return mount(Navigation, {
				global: {
					plugins: [realPinia, appRouter],
					stubs: { ...realStubs, NcAppNavigationSettings: false },
				},
			})
		}

		it('puts an aria-expanded button inside the menu', async () => {
			const menu = (await mountWithRealMenu()).find('.navigation__more')

			expect(menu.exists()).toBe(true)
			expect(menu.find('button[aria-expanded]').exists()).toBe(true)
		})

		/**
		 * Why the delay is passed from the template rather than counted in CSS.
		 * Every entry sits in a wrapper of its own, so each is its parent's
		 * first child and `nth-child` would hand them all the same delay. If
		 * that ever changes, this fails and the simpler approach becomes
		 * available.
		 */
		it('gives every entry a wrapper of its own, so the DOM cannot be counted', async () => {
			const entries = (await mountWithRealMenu())
				.find('.navigation__more')
				.findAll('.app-navigation-entry')

			expect(entries.length).toBeGreaterThan(1)
			const parents = new Set(entries.map((entry) => entry.element.parentElement))
			expect(parents.size).toBe(entries.length)
		})

		it('numbers the entries from nothing, in the order they are drawn', async () => {
			const menu = (await mountWithRealMenu()).find('.navigation__more')
			const indices = menu.findAll('[style*="--entry-index"]')
				.map((item) => item.attributes('style').match(/--entry-index:\s*(\d+)/)[1])

			// 0, 1, 2 … with none skipped and none repeated
			expect(indices).toEqual(indices.map((_, position) => String(position)))
		})
	})

	it.each([
		['My Feed', '/index.php/apps/social/timeline'],
		['Activities', '/index.php/apps/social/timeline/notifications'],
		['Direct messages', '/index.php/apps/social/timeline/direct'],
		['Follow requests', '/index.php/apps/social/follow_requests'],
		['Liked posts', '/index.php/apps/social/timeline/favourites'],
		['Bookmarks', '/index.php/apps/social/timeline/bookmarks'],
		['My profile', '/index.php/apps/social/@alice'],
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
		const names = ['My Feed', 'Photos', 'Videos', 'Activities', 'Direct messages', 'Liked posts', 'Bookmarks']

		expect(lit(await mountReal('/timeline/direct'), names)).toEqual(['Direct messages'])
		expect(lit(await mountReal('/timeline'), names)).toEqual(['My Feed'])
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

	/**
	 * It is a button rather than a link with `href="#"`, so there is no
	 * fragment to land in the address bar and nothing to prevent: the composer
	 * opens and the page the reader was on stays the page they are on.
	 */
	it('opens the composer without navigating anywhere', async () => {
		const wrapper = await mountReal()

		await wrapper.find('.navigation__compose').trigger('click')
		await flushPromises()

		expect(wrapper.find('.navigation__compose').element.tagName).toBe('BUTTON')
		// the modal is teleported out of the component, so it is looked for
		// where it actually lands
		expect(document.querySelector('.modal-composer')).not.toBeNull()
		expect(appRouter.currentRoute.value.name).toBe('timeline')
	})
})
