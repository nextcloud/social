/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createStore } from 'vuex'
import ProfileTimeline from '../../../src/views/ProfileTimeline.vue'
import timeline from '../../../src/store/timeline.js'

const pristine = structuredClone(timeline.state)

const TimelineListStub = {
	name: 'TimelineList',
	props: ['type'],
	template: '<ul class="timeline-list-stub" />',
}

let store
let dispatch

const mountView = (route) => mount(ProfileTimeline, {
	global: { plugins: [store], mocks: { $route: route }, stubs: { TimelineList: TimelineListStub } },
})

describe('ProfileTimeline', () => {
	beforeEach(() => {
		Object.assign(timeline.state, structuredClone(pristine))
		store = createStore({ modules: { timeline } })
		store.commit('addToTimeline', [{ id: 'old', created_at: '2026-01-01T00:00:00Z' }])
		dispatch = vi.spyOn(store, 'dispatch')
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('switches the store to the posts of the routed account and renders the list', () => {
		const wrapper = mountView({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(dispatch).toHaveBeenCalledWith('changeTimelineTypeAccount', 'bob@remote.example')
		expect(store.state.timeline.type).toBe('account')
		expect(store.state.timeline.account).toBe('bob@remote.example')
		expect(store.state.timeline.timeline).toEqual([])
		expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
	})

	it('leaves the store alone without an account in the route', () => {
		mountView({ name: 'profile', params: {} })
		expect(dispatch).not.toHaveBeenCalled()
		expect(store.state.timeline.timeline).toEqual(['old'])
	})

	it('reloads when the route points to another account', async () => {
		const route = reactive({ name: 'profile', params: { account: 'bob@remote.example' } })
		mountView(route)
		route.params.account = 'carol'
		await nextTick()
		expect(dispatch).toHaveBeenLastCalledWith('changeTimelineTypeAccount', 'carol')
		expect(store.state.timeline.account).toBe('carol')
	})
})
