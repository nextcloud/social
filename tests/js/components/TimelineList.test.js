/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showError } from '@nextcloud/dialogs'
import TimelineList from '../../../src/components/TimelineList.vue'
import eventBus from '../../../src/services/eventBus.js'
import { listen } from '@nextcloud/notify_push'
import EmptyContent from '../../../src/components/EmptyContent.vue'
import TimelineSkeleton from '../../../src/components/TimelineSkeleton.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/notify_push', () => ({ listen: vi.fn(() => false) }))

// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))

class FakeIntersectionObserver {
	static instances = []

	constructor(callback, options) {
		this.callback = callback
		this.options = options
		this.observe = vi.fn()
		this.unobserve = vi.fn()
		this.disconnect = vi.fn()
		FakeIntersectionObserver.instances.push(this)
	}
}

const observer = () => FakeIntersectionObserver.instances.at(-1)

async function intersect(isIntersecting = true) {
	observer().callback([{ isIntersecting }])
	await flushPromises()
}

function status(id) {
	return {
		id,
		created_at: `2026-09-0${id.length}T10:00:00Z`,
		content: `<p>Status ${id}</p>`,
		reblog: null,
		account: { id: '1', acct: 'alice', username: 'alice', display_name: 'Alice' },
	}
}

const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	// carries the real component's class and tabindex, because the list finds
	// entries by that class and sends focus to them
	template: '<li class="timeline-entry timeline-entry-stub" tabindex="-1" :data-id="item.id" />',
}

const entryIds = (wrapper) => wrapper.findAll('.timeline-entry-stub').map((entry) => entry.attributes('data-id'))

// what `getTimelineIdentity` is built from, so a test can swap the list the
// store is holding the way a navigation does
function showing(identity) {
	const [type, account, params] = JSON.parse(identity)

	return { type, account, params }
}

// `responses` are the successive results of `fetchTimeline`; an Error rejects
function mountList({
	timeline = [],
	parents = [],
	identity = '["home","",{}]',
	route = { name: 'timeline', params: { type: 'home' } },
	serverData = { public: false, cloudAddress: 'https://cloud.example.org' },
	responses = [[]],
	props = {},
} = {}) {
	const dispatch = vi.fn()
	for (const response of responses) {
		if (response instanceof Error) {
			dispatch.mockRejectedValueOnce(response)
		} else {
			dispatch.mockResolvedValueOnce(response)
		}
	}
	dispatch.mockResolvedValue([])

	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	const store = useTimelineStore()
	const notificationsStore = useNotificationsStore()
	vi.spyOn(store, 'fetchTimeline').mockImplementation(dispatch)
	vi.spyOn(notificationsStore, 'markNotificationsRead').mockResolvedValue(undefined)
	store.$patch({
		statuses: Object.fromEntries([...timeline, ...parents].map((entry) => [entry.id, entry])),
		timeline: timeline.map((entry) => entry.id),
		parentsTimeline: parents.map((entry) => entry.id),
		...showing(identity),
	})
	const wrapper = mount(TimelineList, {
		props: { type: 'home', ...props },
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: { TimelineEntry: TimelineEntryStub },
		},
	})
	return { wrapper, dispatch, store, notificationsStore }
}

const emptyTitle = (wrapper) => wrapper.findComponent(EmptyContent).props('item').title

describe('TimelineList', () => {
	beforeEach(() => {
		FakeIntersectionObserver.instances = []
		vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		showError.mockClear()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	describe('entries', () => {
		it('renders one entry per status of the timeline', () => {
			const { wrapper } = mountList({ timeline: [status('30'), status('20')] })
			expect(entryIds(wrapper)).toEqual(['30', '20'])
			expect(wrapper.findAllComponents(TimelineEntryStub).map((entry) => entry.props('type'))).toEqual(['home', 'home'])
		})

		it('shows the ancestors instead when asked for the parents', () => {
			const { wrapper } = mountList({ timeline: [status('30')], parents: [status('5')], props: { showParents: true } })
			expect(entryIds(wrapper)).toEqual(['5'])
		})

		it('reverses the order on request', () => {
			const { wrapper } = mountList({ timeline: [status('30'), status('20')], props: { reverseOrder: true } })
			expect(entryIds(wrapper)).toEqual(['20', '30'])
		})
	})

	describe('posts arriving while reading', () => {
		afterEach(() => {
			window.scrollY = 0
		})

		/** @param {number} y how far down the page the reader is */
		const scrolledTo = (y) => {
			Object.defineProperty(window, 'scrollY', { value: y, configurable: true, writable: true })
		}

		it('offers to jump to posts that arrived out of sight', async () => {
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9'), status('8')]] })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			const pill = wrapper.find('.new-posts-pill')
			expect(pill.exists()).toBe(true)
			expect(pill.text()).toContain('2')
		})

		it('says nothing when the top of the list is already on screen', async () => {
			scrolledTo(0)
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			// up there the posts simply appear; a pill would be noise
			expect(wrapper.find('.new-posts-pill').exists()).toBe(false)
		})

		it('scrolls back to the top and forgets the count when asked', async () => {
			scrolledTo(800)
			const scrollTo = vi.fn()
			window.scrollTo = scrollTo
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()
			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			await wrapper.find('.new-posts-pill').trigger('click')

			expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
			expect(wrapper.find('.new-posts-pill').exists()).toBe(false)
		})
	})

	describe('polling', () => {
		const setVisibility = (state) => {
			Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
			document.dispatchEvent(new Event('visibilitychange'))
		}

		afterEach(() => {
			setVisibility('visible')
		})

		it('keeps polling while the tab is watched', async () => {
			const { dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(31000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalled()
		})

		it('does not ask while the tab is hidden', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()

			// traffic nobody is waiting for is most of the traffic there is
			expect(dispatch).not.toHaveBeenCalled()
			expect(wrapper.vm.hiddenSince).toBeGreaterThan(0)
		})

		it('catches up on the way back, when it was away longer than a tick', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()
			dispatch.mockClear()
			wrapper.vm.hiddenSince = Date.now() - 60000

			setVisibility('visible')
			await flushPromises()

			expect(dispatch).toHaveBeenCalled()
			expect(wrapper.vm.hiddenSince).toBe(0)
		})

		it('does not catch up after a glance away', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()
			dispatch.mockClear()
			wrapper.vm.hiddenSince = Date.now()

			setVisibility('visible')
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('stops listening for the tab once it is gone', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			wrapper.unmount()

			expect(() => setVisibility('hidden')).not.toThrow()
		})
	})

	describe('reading with the keyboard', () => {
		afterEach(() => {
			eventBus.all.clear()
		})

		const focusedIds = (wrapper) => wrapper.findAll('.timeline-entry--focused')
			.map((entry) => entry.attributes('data-id'))

		it('walks the list with j and k, and stops at both ends', async () => {
			const { wrapper } = mountList({ timeline: [status('1'), status('22'), status('333')] })
			await flushPromises()
			// whatever order the list settled on is the order j walks
			const order = entryIds(wrapper)

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[0]])

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[1]])

			// k walks back, and the top is the top
			eventBus.emit('shortcut:previous')
			eventBus.emit('shortcut:previous')
			eventBus.emit('shortcut:previous')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[0]])
		})

		it('announces the focused post, so the post itself can act on l/b/r', async () => {
			const heard = vi.fn()
			eventBus.on('timeline:focused', heard)
			const { wrapper } = mountList({ timeline: [status('1')] })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(heard).toHaveBeenCalledWith(expect.objectContaining({ id: '1' }))
		})

		it('does nothing on an empty timeline', async () => {
			const { wrapper } = mountList({ timeline: [] })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(focusedIds(wrapper)).toEqual([])
		})

		it('stops listening once it is gone', async () => {
			const { wrapper } = mountList({ timeline: [status('1')] })
			await flushPromises()
			wrapper.unmount()

			expect(() => eventBus.emit('shortcut:next')).not.toThrow()
		})

		it('leaves the ancestors list alone, so one press moves one focus', async () => {
			// both lists in the single-post view listened, so j walked the
			// ancestors and the replies at the same time
			const { wrapper } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(focusedIds(wrapper)).toEqual([])
		})
	})

	describe('keyboard reading', () => {
		const posts = [
			{ id: '1', account: { id: 'a' }, content: 'one' },
			{ id: '2', account: { id: 'b' }, content: 'two' },
		]

		it('moves the keyboard, not only a highlight', async () => {
			const { wrapper } = mountList({ timeline: posts })
			const entries = wrapper.findAll('.timeline-entry')
			entries.forEach((entry) => {
				entry.element.focus = vi.fn()
				entry.element.scrollIntoView = vi.fn()
			})

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			// scrolling alone leaves the keyboard where it was, which is the
			// one thing a reader using j/k cannot afford
			expect(entries[0].element.focus).toHaveBeenCalledWith({ preventScroll: true })
		})

		it('gives an entry somewhere for focus to land', () => {
			const { wrapper } = mountList({ timeline: posts })

			expect(wrapper.find('.timeline-entry').attributes('tabindex')).toBe('-1')
		})
	})

	describe('marking notifications read', () => {
		// the shape the server actually sends: the row id, as a string, in `id`
		const notifications = [
			{ id: '1788875057712399', type: 'mention', account: { id: 'a' } },
			{ id: '1788875057712412', type: 'favourite', account: { id: 'b' } },
		]

		it('records the newest one seen, so the badge stops counting it', () => {
			const { notificationsStore } = mountList({
				timeline: notifications,
				props: { type: 'notifications' },
				route: { name: 'timeline', params: { type: 'notifications' } },
			})

			// the highest nid on screen, not the first or the last in the array
			expect(notificationsStore.markNotificationsRead).toHaveBeenCalledWith(1788875057712412)
		})

		it('leaves the marker alone on any other timeline', () => {
			const { notificationsStore } = mountList({ timeline: notifications, props: { type: 'home' } })

			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()
		})

		it('records nothing when there is nothing to show', () => {
			const { notificationsStore } = mountList({ timeline: [], props: { type: 'notifications' } })

			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()
		})
	})

	describe('loading pages', () => {
		it('requests the first page on mount', async () => {
			const { dispatch } = mountList()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith({})
		})

		it('requests the statuses older than the last one shown', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ max_id: 20 })
		})

		it('requests the statuses newer than the newest one shown in reverse order', async () => {
			// The newest loaded id, regardless of the created_at display order —
			// paging on any other entry refetches a page the store already has.
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], props: { reverseOrder: true } })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: 30 })
		})

		it('shows post-shaped placeholders while the first page loads, not a spinner', async () => {
			let finish
			const {
				wrapper,
			} = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })
			await flushPromises()

			// an empty page with a spinner says nothing about what is coming
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(true)
			expect(wrapper.find('.icon-loading').exists()).toBe(false)
			expect(wrapper.find('.list-end').exists()).toBe(false)

			finish([status('1')])
			await flushPromises()

			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)
			expect(wrapper.find('.list-end').exists()).toBe(true)
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('shows a spinner for a later page, where the posts are already on screen', async () => {
			let finish
			const { wrapper } = mountList({
				timeline: [status('1')],
				responses: [[status('1')], new Promise((resolve) => { finish = resolve })],
			})
			await flushPromises()
			await intersect()

			expect(wrapper.find('.icon-loading').exists()).toBe(true)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)

			finish([status('22')])
			await flushPromises()

			expect(wrapper.find('.icon-loading').exists()).toBe(false)
		})

		it('shows a failed first page as an error with a retry, not as an empty timeline', async () => {
			const { wrapper } = mountList({ responses: [new Error('network')] })
			await flushPromises()

			expect(wrapper.find('.icon-loading').exists()).toBe(false)
			// "No posts found / Posts from people you follow will show up
			// here" for a server error was the old answer
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
			expect(wrapper.find('.timeline-error').text()).toContain('The posts could not be loaded.')
			expect(wrapper.find('.timeline-error').attributes('role')).toBe('alert')
		})

		it('keeps paging alive after a failure', async () => {
			const { dispatch } = mountList({ responses: [new Error('network'), new Error('network')] })
			await flushPromises()

			// allLoaded used to be set here, which stopped the observer from
			// ever firing again: infinite scroll was dead until a reload
			await intersect()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(2)
		})

		it('clears the error and loads again when the retry is pressed', async () => {
			const { wrapper, dispatch } = mountList({ responses: [new Error('network'), [status('20')]] })
			await flushPromises()
			expect(wrapper.find('.timeline-error').exists()).toBe(true)

			await wrapper.find('.timeline-error button').trigger('click')
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(2)
			expect(wrapper.find('.timeline-error').exists()).toBe(false)
		})

		it('starts over when the store swaps the list underneath it', async () => {
			// the router-view is no longer keyed on the full path, so going
			// from Home to Global (or to another profile) reuses this
			// component: without this nothing would ask for the new list
			const { wrapper, dispatch, store } = mountList({ responses: [[]] })
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(true)
			dispatch.mockClear()
			dispatch.mockResolvedValue([status('9')])

			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({})
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('asks for the new list even while the previous one is still loading', async () => {
			// Clicking Global during the ~200 ms the home timeline is loading
			// left `loading` set, so infiniteHandler returned at once and
			// nothing was ever requested for the list now on screen.
			let finishHome
			const {
				dispatch,
				store,
			} = mountList({ responses: [new Promise((resolve) => { finishHome = resolve })] })
			await flushPromises()
			dispatch.mockClear()
			dispatch.mockResolvedValue([status('9')])

			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({})
			finishHome([])
		})

		it('lets the previous list\'s answer decide nothing once it arrives', async () => {
			let finishHome
			const {
				wrapper,
				dispatch,
				store,
			} = mountList({ responses: [new Promise((resolve) => { finishHome = resolve })] })
			await flushPromises()

			let finishFederated
			dispatch.mockClear()
			dispatch.mockReturnValue(new Promise((resolve) => {
				finishFederated = resolve
			}))
			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			// home answers "nothing more", which used to end the new list
			// before its own first page had come back
			finishHome([])
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(true)

			finishFederated([status('9')])
			await flushPromises()
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)
		})

		it('does not start over for the ancestors list, which fetches nothing', async () => {
			const { dispatch, store } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			store.$patch(showing('["single-post","",{"id":"9"}]'))
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('says less when a later page fails and the reader already has posts', async () => {
			const { wrapper } = mountList({ timeline: [status('30')], responses: [new Error('network')] })
			await flushPromises()

			expect(wrapper.find('.timeline-error').text()).toContain('No more posts could be loaded.')
		})
	})

	describe('infinite scroll', () => {
		it('watches the sentinel below the list with a margin', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			expect(observer().options).toEqual({ rootMargin: '200px' })
			expect(observer().observe).toHaveBeenCalledWith(wrapper.find('.list-sentinel').element)
		})

		it('loads the next page when the sentinel comes into view', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], responses: [[status('20')], [status('10')]] })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(1)

			await intersect()

			expect(dispatch).toHaveBeenCalledTimes(2)
			expect(dispatch).toHaveBeenLastCalledWith({ max_id: 20 })
		})

		it('does nothing when the sentinel leaves the view', async () => {
			const { dispatch } = mountList({ responses: [[status('1')]] })
			await flushPromises()
			await intersect(false)
			expect(dispatch).toHaveBeenCalledTimes(1)
		})

		it('does not request a page while one is still loading', async () => {
			let finish
			const {
				dispatch,
			} = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })

			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)

			finish([status('1')])
			await flushPromises()
			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(2)
		})

		it('stops requesting once the server returned an empty page', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)
		})
	})

	describe('polling for new statuses', () => {
		it('registers a push listener and slows polling down when push is available', async () => {
			listen.mockReturnValueOnce(true)
			const { dispatch } = mountList({ timeline: [status('30')] })
			await flushPromises()
			dispatch.mockClear()

			expect(listen).toHaveBeenCalledWith('social_timeline', expect.any(Function))

			// a pushed event refreshes immediately
			listen.mock.calls[listen.mock.calls.length - 1][1]()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: 30 })
			dispatch.mockClear()

			// the 30-second poll is off; the safety net runs every 5 minutes
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(dispatch).not.toHaveBeenCalled()

			vi.advanceTimersByTime(270 * 1000)
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: 30 })
		})

		it('asks for statuses newer than the first one every 30 seconds', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith({ min_id: 30 })
		})

		it('polls with the highest id even when a newer-dated status has a lower one', async () => {
			// A federated post can carry a high id with an old created_at. The
			// display order sorts on created_at, so timeline[0] is not the
			// newest id — polling on it would return the same page forever.
			const highIdOldDate = { ...status('50'), created_at: '2026-01-01T10:00:00Z' }
			const { dispatch } = mountList({ timeline: [status('30'), highIdOldDate] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({ min_id: 50 })
		})

		it('does not poll for ancestors', async () => {
			const { dispatch } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('does not fetch, observe or poll at all for the ancestors list', async () => {
			// the single-post view mounts two lists over one /context response:
			// the ancestors list used to make its own identical request, keep
			// its own observer and run its own 30-second interval
			const { dispatch } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
			expect(FakeIntersectionObserver.instances).toHaveLength(0)
		})

		it('says a polling failure once, not once per tick', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()
			dispatch.mockRejectedValue(new Error('down'))

			for (let tick = 0; tick < 4; tick++) {
				vi.advanceTimersByTime(30 * 1000)
				await flushPromises()
			}

			// a server that is down produced an endless stream of toasts
			expect(dispatch.mock.calls.length).toBeGreaterThanOrEqual(4)
			expect(showError).toHaveBeenCalledTimes(1)
			expect(showError).toHaveBeenCalledWith('Could not load the newest posts')
		})

		it('says it again once the server has answered in between', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()

			dispatch.mockRejectedValueOnce(new Error('down'))
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(showError).toHaveBeenCalledTimes(1)

			dispatch.mockResolvedValueOnce([])
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			dispatch.mockRejectedValueOnce(new Error('down again'))
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(showError).toHaveBeenCalledTimes(2)
		})

		it('stops catching up after ten pages, however the server answers', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()
			// a server that ignores min_id used to keep this recursing for as
			// long as the tab was open
			dispatch.mockResolvedValue([status('9')])

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledTimes(10)
		})

		it('stops polling and observing when unmounted', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			wrapper.unmount()
			vi.advanceTimersByTime(60 * 1000)
			await flushPromises()

			expect(observer().disconnect).toHaveBeenCalled()
			expect(dispatch).not.toHaveBeenCalled()
		})
	})

	describe('empty state', () => {
		it('is not shown while more statuses may still arrive', async () => {
			const { wrapper } = mountList({ responses: [[status('1')]] })
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('is shown with the home illustration once the timeline turns out empty', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).props('item')).toEqual({
				image: 'img/undraw/posts.svg',
				title: 'No posts found',
				description: 'Posts from people you follow will show up here',
			})
		})

		it.each([
			['direct', 'No direct messages found'],
			['timeline', 'No local posts found'],
			['federated', 'No global posts found'],
			['notifications', 'No notifications found'],
			['favourites', 'No liked posts found'],
			['tags', 'No posts found for this tag'],
		])('describes an empty %s timeline', async (type, title) => {
			const { wrapper } = mountList({ route: { name: 'timeline', params: { type } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe(title)
		})

		it('addresses the viewer on their own empty profile', async () => {
			const { wrapper } = mountList({ route: { name: 'profile', params: { account: 'alice' } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('You have not tooted yet')
		})

		it('names the account on somebody else\'s empty profile', async () => {
			const { wrapper } = mountList({ route: { name: 'profile', params: { account: 'bob' } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('bob hasn\'t tooted yet')
		})

		it('names the account on the public profile page even for the owner', async () => {
			const { wrapper } = mountList({
				route: { name: 'profile', params: { account: 'alice' } },
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('alice hasn\'t tooted yet')
		})

		/**
		 * A Photos or Videos tab with nothing in it is not an account that has
		 * never posted: they may have posted plenty, just none of this.
		 */
		it.each([
			['image', 'No photos yet'],
			['video', 'No videos yet'],
		])('says what the %s tab of a profile is missing', async (media, title) => {
			const { wrapper } = mountList({
				route: { name: 'profile', params: { account: 'bob' }, query: { media } },
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe(title)
		})

		it('does not rewrite the shared empty-content entry for a public profile', async () => {
			// the computed used to assign into this.emptyContent, so the
			// rewritten title stayed behind for every later route
			const first = mountList({
				route: { name: 'profile', params: { account: 'alice' } },
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})
			await flushPromises()
			expect(emptyTitle(first.wrapper)).toBe('alice hasn\'t tooted yet')

			const second = mountList({ route: { name: 'profile', params: { account: 'alice' } } })
			await flushPromises()
			expect(emptyTitle(second.wrapper)).toBe('You have not tooted yet')
		})

		it('says a thread has no replies rather than leaving a blank area', async () => {
			// /context answers with {ancestors, descendants}, whose `.length`
			// is undefined: allLoaded was never set, so nothing was said and
			// the sentinel kept asking for a page that does not exist
			const { wrapper, dispatch } = mountList({
				route: { name: 'single-post', params: { type: 'single-post', id: '1' } },
				responses: [{ ancestors: [], descendants: [] }],
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe('No replies found')

			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)
		})

		it('mentions missing replies below a single post but stays silent for its ancestors', async () => {
			const route = { name: 'single-post', params: { type: 'single-post', id: '1' } }

			const replies = mountList({ route })
			await flushPromises()
			expect(emptyTitle(replies.wrapper)).toBe('No replies found')

			const parents = mountList({ route, props: { showParents: true } })
			await flushPromises()
			expect(parents.wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})
	})
})
