/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import Timeline from '../../../src/views/Timeline.vue'
import account from '../../../src/store/account.js'
import errors from '../../../src/store/errors.js'
import settings from '../../../src/store/settings.js'
import timeline from '../../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = {
	account: structuredClone(account.state),
	timeline: structuredClone(timeline.state),
}

const ComposerStub = {
	name: 'Composer',
	props: ['defaultVisibility', 'initialMention'],
	template: '<div class="composer-stub" />',
}
const TimelineListStub = {
	name: 'TimelineList',
	props: ['type', 'showParents', 'reverseOrder'],
	template: '<ul class="timeline-list-stub" />',
}

const nextcloud = {
	id: 'https://mastodon.xyz/users/nextcloud',
	url: 'https://mastodon.xyz/users/nextcloud',
	acct: 'nextcloud@mastodon.xyz',
	username: 'nextcloud',
	display_name: 'Nextcloud',
}

let store
let dispatch

const makeStore = (serverData = {}) => {
	Object.assign(account.state, structuredClone(pristine.account))
	Object.assign(timeline.state, structuredClone(pristine.timeline))
	store = createStore({
		modules: {
			timeline,
			settings,
			errors,
			// network actions are replaced, the synchronous ones stay real
			account: { ...account, actions: { ...account.actions, fetchAccountInfo: vi.fn(), followAccount: vi.fn() } },
		},
	})
	store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org', firstrun: false, ...serverData })
	dispatch = vi.spyOn(store, 'dispatch')
	return store
}

const mountTimeline = (route = {}) => mount(Timeline, {
	global: {
		plugins: [store],
		mocks: { $route: { name: 'timeline', params: {}, ...route } },
		stubs: { Composer: ComposerStub, TimelineList: TimelineListStub },
	},
})

describe('Timeline', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('switches the store to the home timeline when no type is in the route', () => {
		const wrapper = mountTimeline()
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type: 'home', params: {} })
		expect(store.state.timeline.type).toBe('home')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('home')
		expect(wrapper.find('h2').exists()).toBe(false)
	})

	it.each(['direct', 'timeline', 'federated', 'favourites'])('switches the store to the %s timeline from the route', (type) => {
		const wrapper = mountTimeline({ params: { type } })
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type, params: {} })
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe(type)
	})

	it('resets the previously loaded posts when switching', () => {
		store.commit('addToTimeline', [{ id: 'old', created_at: '2026-01-01T00:00:00Z' }])
		mountTimeline({ params: { type: 'federated' } })
		expect(store.state.timeline.timeline).toEqual([])
	})

	it('loads a hashtag timeline with the tag as parameter and shows the tag as heading', () => {
		const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type: 'tags', params: { tag: 'nextcloud' } })
		expect(wrapper.find('h2').text()).toBe('#nextcloud')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('tags')
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(true)
	})

	it('shows the composer on the home timeline without a preset visibility', () => {
		const composer = mountTimeline().findComponent(ComposerStub)
		expect(composer.exists()).toBe(true)
		expect(composer.props('defaultVisibility')).toBeUndefined()
	})

	it('presets the composer to direct messages on the direct timeline', () => {
		expect(mountTimeline({ params: { type: 'direct' } }).findComponent(ComposerStub).props('defaultVisibility')).toBe('direct')
	})

	it('hides the composer on the notifications timeline and titles it', () => {
		const wrapper = mountTimeline({ params: { type: 'notifications' } })
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		expect(wrapper.find('h2').text()).toBe('Notifications')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('notifications')
	})

	it('shows the active search with a way to clear it', async () => {
		const wrapper = mountTimeline()
		expect(wrapper.find('.search-active').exists()).toBe(false)

		store.commit('setSearchQuery', 'fediverse')
		await nextTick()
		expect(wrapper.find('.search-active').text()).toContain('Search: «fediverse»')

		await wrapper.find('.search-clear').trigger('click')
		expect(store.state.timeline.searchQuery).toBe('')
		expect(wrapper.find('.search-active').exists()).toBe(false)
	})

	it('does not show the welcome box or look up the Nextcloud account after the first run', () => {
		const wrapper = mountTimeline()
		expect(wrapper.find('.social__welcome').exists()).toBe(false)
		expect(dispatch).not.toHaveBeenCalledWith('fetchAccountInfo', expect.anything())
	})

	describe('on the first run', () => {
		beforeEach(() => {
			makeStore({ firstrun: true })
		})

		it('welcomes the user with their social id and looks up the official account', () => {
			const wrapper = mountTimeline()
			expect(wrapper.find('.social__welcome').exists()).toBe(true)
			expect(wrapper.find('.social-id').text()).toBe('@alice@cloud.example.org')
			expect(dispatch).toHaveBeenCalledWith('fetchAccountInfo', 'nextcloud@mastodon.xyz')
		})

		it('can be closed', async () => {
			const wrapper = mountTimeline()
			await wrapper.find('.social__welcome .close').trigger('click')
			expect(wrapper.find('.social__welcome').exists()).toBe(false)
		})

		it('suggests following the Nextcloud account and dispatches the follow', async () => {
			const wrapper = mountTimeline()
			const follow = wrapper.find('.follow-nextcloud input[type="button"]')
			expect(follow.element.value).toBe('Follow Nextcloud on mastodon.xyz')
			await follow.trigger('click')
			expect(dispatch).toHaveBeenCalledWith('followAccount', { accountToFollow: 'nextcloud@mastodon.xyz' })
		})

		it('hides the suggestion while the account is unknown and once it is followed', async () => {
			const wrapper = mountTimeline()
			// v-show toggles display: none
			const hidden = () => wrapper.find('.follow-nextcloud').element.style.display === 'none'
			// unknown yet: treated as followed so nothing flashes
			expect(hidden()).toBe(true)

			store.commit('addAccount', { actorId: nextcloud.url, data: nextcloud })
			store.commit('addRelationship', { actorId: nextcloud.id, data: { id: nextcloud.id, following: false } })
			await nextTick()
			expect(hidden()).toBe(false)

			store.commit('followAccount', nextcloud.acct)
			await nextTick()
			expect(hidden()).toBe(true)
		})
	})
})
