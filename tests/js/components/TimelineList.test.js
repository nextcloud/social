/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showError } from '@nextcloud/dialogs'
import TimelineList from '../../../src/components/TimelineList.vue'
import EmptyContent from '../../../src/components/EmptyContent.vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

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

const intersect = async (isIntersecting = true) => {
	observer().callback([{ isIntersecting }])
	await flushPromises()
}

const status = (id) => ({
	id,
	created_at: `2026-09-0${id.length}T10:00:00Z`,
	content: `<p>Status ${id}</p>`,
	reblog: null,
	account: { id: '1', acct: 'alice', username: 'alice', display_name: 'Alice' },
})

const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	template: '<li class="timeline-entry-stub" :data-id="item.id" />',
}

const entryIds = (wrapper) => wrapper.findAll('.timeline-entry-stub').map((entry) => entry.attributes('data-id'))

// `responses` are the successive results of `fetchTimeline`; an Error rejects
const mountList = ({
	timeline = [],
	parents = [],
	searchQuery = '',
	route = { name: 'timeline', params: { type: 'home' } },
	serverData = { public: false, cloudAddress: 'https://cloud.example.org' },
	responses = [[]],
	props = {},
} = {}) => {
	const dispatch = vi.fn()
	for (const response of responses) {
		if (response instanceof Error) {
			dispatch.mockRejectedValueOnce(response)
		} else {
			dispatch.mockResolvedValueOnce(response)
		}
	}
	dispatch.mockResolvedValue([])

	const $store = {
		dispatch,
		commit: vi.fn(),
		getters: {
			// fresh arrays, as the real getters return, so reverse() cannot leak
			get getTimeline() {
				return [...timeline]
			},
			get getParentsTimeline() {
				return [...parents]
			},
			getSearchQuery: searchQuery,
			getServerData: serverData,
		},
	}
	const wrapper = mount(TimelineList, {
		props: { type: 'home', ...props },
		global: {
			mocks: { $store, $route: route },
			stubs: { TimelineEntry: TimelineEntryStub },
		},
	})
	return { wrapper, dispatch }
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

	describe('loading pages', () => {
		it('requests the first page on mount', async () => {
			const { dispatch } = mountList()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith('fetchTimeline', {})
		})

		it('requests the statuses older than the last one shown', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith('fetchTimeline', { max_id: 20 })
		})

		it('requests the statuses newer than the first one shown in reverse order', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], props: { reverseOrder: true } })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith('fetchTimeline', { min_id: 20 })
		})

		it('shows a spinner while a page is loading and the end marker afterwards', async () => {
			let finish
			const { wrapper } = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })
			await flushPromises()
			expect(wrapper.find('.icon-loading').exists()).toBe(true)
			expect(wrapper.find('.list-end').exists()).toBe(false)

			finish([status('1')])
			await flushPromises()

			expect(wrapper.find('.icon-loading').exists()).toBe(false)
			expect(wrapper.find('.list-end').exists()).toBe(true)
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('reports a failed page and stops loading', async () => {
			const { wrapper, dispatch } = mountList({ responses: [new Error('network')] })
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to load more timeline entries')
			expect(wrapper.find('.icon-loading').exists()).toBe(false)
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(true)

			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)
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
			expect(dispatch).toHaveBeenLastCalledWith('fetchTimeline', { max_id: 20 })
		})

		it('does nothing when the sentinel leaves the view', async () => {
			const { dispatch } = mountList({ responses: [[status('1')]] })
			await flushPromises()
			await intersect(false)
			expect(dispatch).toHaveBeenCalledTimes(1)
		})

		it('does not request a page while one is still loading', async () => {
			let finish
			const { dispatch } = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })

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
		it('asks for statuses newer than the first one every 30 seconds', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith('fetchTimeline', { min_id: '30' })
		})

		it('does not poll for ancestors', async () => {
			const { dispatch } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
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

		it('explains an empty search result', async () => {
			const { wrapper } = mountList({ searchQuery: 'nothing-matches' })
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).props('item')).toEqual({
				title: 'No posts match your search',
				description: 'Try a different search term',
			})
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
