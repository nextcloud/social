/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import ProfileTimeline from '../../../src/views/ProfileTimeline.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

const TimelineListStub = {
	name: 'TimelineList',
	props: ['type'],
	template: '<ul class="timeline-list-stub" />',
}
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	template: '<li class="pinned-entry-stub">{{ item.id }}</li>',
}

let pinia
let store
let dispatch

function mountView(route) {
	return mount(ProfileTimeline, {
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: { TimelineList: TimelineListStub, TimelineEntry: TimelineEntryStub },
		},
	})
}

const pinnedIds = (wrapper) => wrapper.findAll('.pinned-entry-stub').map((entry) => entry.text())

describe('ProfileTimeline', () => {
	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useTimelineStore()
		store.addToTimeline([{ id: 'old', created_at: '2026-01-01T00:00:00Z' }])
		dispatch = vi.spyOn(store, 'changeTimelineTypeAccount')
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('switches the store to the posts of the routed account and renders the list', () => {
		const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(dispatch).toHaveBeenCalledWith('bob@remote.example')
		expect(store.type).toBe('account')
		expect(store.account).toBe('bob@remote.example')
		expect(store.timeline).toEqual([])
		expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
	})

	it('leaves the store alone without an account in the route', () => {
		mountView({ name: 'profile', params: {} })
		expect(dispatch).not.toHaveBeenCalled()
		expect(store.timeline).toEqual(['old'])
	})

	it('reloads when the route points to another account', async () => {
		const route = reactive({ name: 'profile', params: { account: 'bob@remote.example' } })
		mountView(route)
		route.params.account = 'carol'
		await nextTick()
		expect(dispatch).toHaveBeenLastCalledWith('carol')
		expect(store.account).toBe('carol')
	})

	describe('pinned posts', () => {
		it('renders the pinned posts of the account above the timeline', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 'pin-2' }, { id: 'pin-1' }] })

			const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(
				'/index.php/apps/social/api/v1/accounts/bob@remote.example/statuses',
				{ params: { pinned: true } },
			)
			expect(pinnedIds(wrapper)).toEqual(['pin-2', 'pin-1'])
		})

		it('shows no pinned section for an account without pins', async () => {
			const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()

			expect(wrapper.find('.profile-pinned').exists()).toBe(false)
		})

		it('drops the pinned posts of the previous account when the route changes', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 'pin-1' }] })
			const route = reactive({ name: 'profile', params: { account: 'bob@remote.example' } })
			const wrapper = mountView(route)
			await flushPromises()
			expect(pinnedIds(wrapper)).toEqual(['pin-1'])

			axios.get.mockResolvedValue({ data: [] })
			route.params.account = 'carol'
			await nextTick()
			expect(pinnedIds(wrapper)).toEqual([], 'the old pins never linger on the new profile')
			await flushPromises()
			expect(pinnedIds(wrapper)).toEqual([])
		})

		it('leaves the timeline alone when the pinned posts cannot be loaded', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()

			expect(wrapper.find('.profile-pinned').exists()).toBe(false)
			expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
		})

		it('asks for no pins without an account in the route', async () => {
			mountView({ name: 'profile', params: {} })
			await flushPromises()

			expect(axios.get).not.toHaveBeenCalled()
		})

		it('routes them through the store, so acting on one shows', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 'pin-1', created_at: '2026-01-01T00:00:00Z', favourited: false, favourites_count: 0 }] })
			const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()

			// they used to live in local component data, and every mutation in
			// the store is guarded by `state.statuses[id] !== undefined`, so
			// liking or unpinning a pinned post was a UI no-op
			expect(store.statuses['pin-1']).toBeDefined()

			store.likeStatus({ status: { id: 'pin-1' } })
			await nextTick()

			expect(wrapper.findComponent(TimelineEntryStub).props('item')).toMatchObject({
				favourited: true,
				favourites_count: 1,
			})
		})

		it('drops a pinned post the store no longer holds instead of rendering an empty card', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 'pin-1', created_at: '2026-01-01T00:00:00Z' }] })
			const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
			await flushPromises()
			expect(pinnedIds(wrapper)).toEqual(['pin-1'])

			store.removeStatus({ id: 'pin-1' })
			await nextTick()

			expect(pinnedIds(wrapper)).toEqual([])
		})
	})
})
