/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import TimelineSinglePost from '../../../src/views/TimelineSinglePost.vue'
import eventBus from '../../../src/services/eventBus.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ComposerStub = { name: 'Composer', template: '<div class="composer-stub" />' }
const TimelineListStub = {
	name: 'TimelineList',
	props: { type: String, showParents: Boolean, reverseOrder: Boolean },
	template: '<ul class="timeline-list-stub"><li data-social-status="reply-1" /></ul>',
}
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type', 'element'],
	template: '<div class="timeline-entry-stub" />',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="empty-stub"><span class="empty-name">{{ name }}</span></div>',
}

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob' }
const status = { id: '123', uri: 'https://remote.example/users/bob/statuses/123', content: '<p>Hello</p>', created_at: '2026-01-01T00:00:00Z', account: bob }
const fromServer = { ...status, content: '<p>Server copy</p>' }
const parent = { id: '120', uri: 'https://remote.example/users/bob/statuses/120', content: '<p>Parent</p>', created_at: '2026-01-01T00:00:00Z', account: bob }
const grandParent = { ...parent, id: '119', uri: 'https://remote.example/users/bob/statuses/119' }

let pinia
let accountStore
let store
const fetchAccount = vi.fn(async () => bob)

const setState = (key, value) => {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

const makeStore = (serverData = {}) => {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	store = useTimelineStore()
	vi.spyOn(accountStore, 'fetchAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'fetchPublicAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(store, 'changeTimelineType')
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })

	return pinia
}

// every view registers an event bus listener, so unmount them after each test
const mounted = []

const mountView = (route = reactive({ name: 'single-post', params: { account: 'bob', id: '123' } })) => {
	const wrapper = mount(TimelineSinglePost, {
		attachTo: document.body,
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: {
				Composer: ComposerStub,
				TimelineList: TimelineListStub,
				TimelineEntry: TimelineEntryStub,
				NcEmptyContent: NcEmptyContentStub,
			},
		},
	})
	mounted.push(wrapper)
	return wrapper
}

describe('TimelineSinglePost', () => {
	beforeEach(() => {
		// the view derives the account from the browser URL
		window.history.replaceState({}, '', '/apps/social/@bob/123')
		setState('item', fromServer)
		makeStore()
		fetchAccount.mockClear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
	})

	it('switches the store to the single post context of the routed post', () => {
		store.addToStatuses(status)
		mountView()
		expect(store.changeTimelineType).toHaveBeenCalledWith({
			type: 'single-post',
			params: { account: 'bob', id: '123', type: 'single-post', singlePost: '123' },
		})
		expect(store.type).toBe('single-post')
		expect(store.params.singlePost).toBe('123')
	})

	it('prefers the already loaded post over the server-rendered copy', () => {
		store.addToStatuses(status)
		const wrapper = mountView()
		expect(wrapper.findComponent(TimelineEntryStub).props('item')).toEqual(status)
		expect(store.getSinglePost.content).toBe('<p>Hello</p>')
	})

	it('falls back to the post from the initial state when it is not in the store yet', () => {
		const wrapper = mountView()
		expect(store.getSinglePost).toEqual(fromServer)
		expect(wrapper.findComponent(TimelineEntryStub).props('item')).toEqual(fromServer)
	})

	it('renders the main post as a block with the ancestors above and the replies below', () => {
		store.addToStatuses(status)
		const wrapper = mountView()
		const entry = wrapper.findComponent(TimelineEntryStub)
		expect(entry.props('type')).toBe('single-post')
		expect(entry.props('element')).toBe('div')
		expect(entry.classes()).toContain('main-post')

		const lists = wrapper.findAllComponents(TimelineListStub)
		expect(lists).toHaveLength(2)
		expect(lists[0].props('showParents')).toBe(true)
		expect(lists[0].props('reverseOrder')).toBe(true)
		expect(lists[1].props('showParents')).toBe(false)
		expect(lists[1].classes()).toContain('descendants')
	})

	it('loads the author taken from the URL', async () => {
		mountView()
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('bob')
		expect(accountStore.fetchPublicAccountInfo).not.toHaveBeenCalled()
		await flushPromises()
		expect(fetchAccount).toHaveBeenCalledTimes(1)
	})

	it('uses the public author lookup on the public page', () => {
		makeStore({ public: true })
		mountView()
		expect(accountStore.fetchPublicAccountInfo).toHaveBeenCalledWith('bob')
		expect(accountStore.fetchAccountInfo).not.toHaveBeenCalled()
	})

	it('only shows the composer when a reply was requested', async () => {
		const wrapper = mountView()
		const composer = () => wrapper.find('.composer-stub').element.style.display
		expect(composer()).toBe('none')
		store.setComposerDisplayStatus(true)
		await nextTick()
		expect(composer()).toBe('')
	})

	it('survives the ancestors arriving, whenever they arrive', async () => {
		const wrapper = mountView()
		expect(wrapper.find('.social__wrapper').exists()).toBe(true)

		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()

		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(true)
	})

	it('scrolls the post into view when its first ancestors are loaded', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		const wrapper = mountView()
		expect(scrollIntoView).not.toHaveBeenCalled()

		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()
		await nextTick()

		expect(scrollIntoView).toHaveBeenCalledTimes(1)
		expect(scrollIntoView.mock.instances[0]).toBe(wrapper.findComponent(TimelineEntryStub).element)
		expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' })
	})

	it('does not scroll again when more ancestors follow', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		mountView()
		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()
		await nextTick()

		store.addToTimeline({ ancestors: [parent, grandParent], descendants: [] })
		await nextTick()
		await nextTick()

		expect(scrollIntoView).toHaveBeenCalledTimes(1)
	})

	it('scrolls to the post being replied to and stops listening after unmount', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		const wrapper = mountView()

		eventBus.emit('composer-reply', { id: 'reply-1' })
		await nextTick()
		expect(scrollIntoView).toHaveBeenCalledTimes(1)
		expect(scrollIntoView.mock.instances[0]).toBe(wrapper.find('[data-social-status="reply-1"]').element)
		expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' })

		wrapper.unmount()
		eventBus.emit('composer-reply', { id: 'reply-1' })
		await nextTick()
		expect(scrollIntoView).toHaveBeenCalledTimes(1)
	})

	it('takes the author from the route, not from the shape of the browser URL', async () => {
		// this used to split window.location.href and slice a '@' off the
		// second-to-last segment, which broke on any other URL shape
		window.history.replaceState({}, '', '/index.php/apps/social/some/other/path')
		mountView(reactive({ name: 'single-post', params: { account: '@carol@remote.example', id: '9' } }))

		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('carol@remote.example')
		expect(store.changeTimelineType).toHaveBeenCalledWith({
			type: 'single-post',
			params: { account: 'carol@remote.example', id: '9', type: 'single-post', singlePost: '9' },
		})
	})

	it('renders a page rather than throwing when the post is gone', () => {
		// loadState throws when the key is absent, which is what a deleted
		// post looks like — and it threw inside beforeMount, so the view
		// never rendered at all
		setState('item', undefined)
		window._nc_initial_state?.clear()
		document.getElementById('initial-state-social-item')?.remove()

		const wrapper = mountView()

		expect(wrapper.find('.social__wrapper').exists()).toBe(true)
		expect(wrapper.find('.empty-name').text()).toBe('This post is not available')
		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(false)
	})

	it('reloads when the route moves to another post in the same view', async () => {
		store.addToStatuses(status)
		const route = reactive({ name: 'single-post', params: { account: 'bob', id: '123' } })
		mountView(route)
		store.changeTimelineType.mockClear()

		route.params.id = '456'
		await nextTick()

		expect(store.changeTimelineType).toHaveBeenCalledWith(expect.objectContaining({
			params: expect.objectContaining({ id: '456' }),
		}))
	})

	it('leaves other composer-reply listeners attached when it unmounts', () => {
		const other = vi.fn()
		eventBus.on('composer-reply', other)
		const wrapper = mountView()

		wrapper.unmount()
		eventBus.emit('composer-reply', { id: 'reply-1' })

		expect(other).toHaveBeenCalledTimes(1)
		eventBus.off('composer-reply', other)
	})
})
