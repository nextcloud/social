/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TimelineEntry from '../../../src/components/TimelineEntry.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useTimelineStore } from '../../../src/store/timeline.js'

const alice = {
	id: '1',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	url: 'https://cloud.example.org/@alice',
	avatar: 'https://cloud.example.org/avatar/alice/64',
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
}

const post = {
	id: 'p1',
	created_at: '2026-09-01T10:00:00Z',
	content: '<p>Original post</p>',
	visibility: 'public',
	reblog: null,
	account: alice,
}

const storedPost = { ...post, content: '<p>Original post, as kept in the store</p>' }

const boost = {
	id: 'b1',
	created_at: '2026-09-02T10:00:00Z',
	content: '',
	visibility: 'public',
	reblog: post,
	account: bob,
}

function notification(type, extra = {}) {
	return {
		id: `n-${type}`,
		type,
		created_at: '2026-09-03T10:00:00Z',
		account: bob,
		status: post,
		...extra,
	}
}

const TimelinePostStub = {
	name: 'TimelinePost',
	props: ['item', 'type'],
	template: '<div class="timeline-post-stub" />',
}
const TimelineAvatarStub = {
	name: 'TimelineAvatar',
	props: ['item'],
	template: '<div class="timeline-avatar-stub" />',
}
const UserEntryStub = {
	name: 'UserEntry',
	props: ['item', 'displayFollowButton'],
	template: '<div class="user-entry-stub" />',
}

function mountEntry(item, props = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const timelineStore = useTimelineStore()
	timelineStore.addToStatuses(storedPost)
	const wrapper = mount(TimelineEntry, {
		props: { item, type: 'home', ...props },
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'timeline', params: { type: 'home' } } },
			stubs: {
				TimelinePost: TimelinePostStub,
				TimelineAvatar: TimelineAvatarStub,
				UserEntry: UserEntryStub,
				RouterLink: RouterLinkStub,
			},
		},
	})
	return { wrapper, timelineStore }
}

describe('TimelineEntry', () => {
	describe('a plain post', () => {
		it('renders the avatar and the post itself without any header', () => {
			const { wrapper } = mountEntry(post)

			expect(wrapper.element.tagName).toBe('LI')
			expect(wrapper.classes()).toContain('timeline-entry')
			expect(wrapper.classes()).not.toContain('with-header')
			expect(wrapper.classes()).not.toContain('notification')
			expect(wrapper.find('.boost').exists()).toBe(false)
			expect(wrapper.find('.notification__header').exists()).toBe(false)

			expect(wrapper.findComponent(TimelineAvatarStub).props('item')).toEqual(post)
			expect(wrapper.findComponent(TimelinePostStub).props()).toEqual({ item: post, type: 'home' })
			expect(wrapper.findComponent(UserEntryStub).exists()).toBe(false)
		})

		it('can be rendered as another element', () => {
			const { wrapper } = mountEntry(post, { element: 'div' })
			expect(wrapper.element.tagName).toBe('DIV')
		})
	})

	/**
	 * Every entry in the list has to have the same edges. A notification is a
	 * card because it is a thing that happened; a boost and a plain post are
	 * both just a post, so neither takes one.
	 */
	it('gives a card to notifications and to nothing else', () => {
		expect(mountEntry(post).wrapper.classes()).not.toContain('with-header')
		expect(mountEntry(boost).wrapper.classes()).not.toContain('with-header')
		expect(mountEntry(notification('favourite'), { type: 'notifications' }).wrapper.classes())
			.toContain('with-header')
	})

	describe('a boost', () => {
		it('shows who boosted and links to their profile', () => {
			const { wrapper } = mountEntry(boost)

			// a line above the post, not a box around it: a card here nested a
			// card and left boosted posts narrower than every post around them
			expect(wrapper.classes()).not.toContain('with-header')
			const header = wrapper.find('.boost')
			expect(header.find('.repeat-icon').exists()).toBe(true)
			expect(header.find('img').attributes('src')).toBe(bob.avatar)
			expect(header.find('.post-author').text()).toBe('Bob')
			expect(header.text()).toMatch(/Bob\s+boosted$/)
			expect(header.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
		})

		it('renders the boosted post from the store so later actions are reflected', () => {
			// the boost carries its own copy of the post; what is rendered is
			// the store's, which is the one a like or an edit changes
			const { wrapper } = mountEntry(boost)

			expect(wrapper.findComponent(TimelinePostStub).props('item')).toEqual(storedPost)
			expect(wrapper.findComponent(TimelineAvatarStub).props('item')).toEqual(storedPost)
		})
	})

	describe('a notification about a post', () => {
		it.each([
			['favourite', 'heart-icon', 'bob@remote.example liked your post'],
			['reblog', 'repeat-icon', 'bob@remote.example boosted your post'],
			['mention', 'at-icon', 'bob@remote.example mentioned you'],
			['status', 'message-outline-icon', 'bob@remote.example posted a status'],
			['update', 'message-plus-outline-icon', 'bob@remote.example edited a status'],
			['poll', 'poll-icon', 'bob@remote.example ended the poll'],
		])('describes a %s with its icon and summary', (type, iconClass, summary) => {
			const { wrapper } = mountEntry(notification(type), { type: 'notifications' })

			expect(wrapper.classes()).toContain('notification')
			expect(wrapper.classes()).toContain('with-header')
			const header = wrapper.find('.notification__header')
			expect(header.find('img').attributes('src')).toBe(bob.avatar)
			expect(header.findAll('.material-design-icon')).toHaveLength(1)
			expect(header.find('.material-design-icon').classes()).toContain(iconClass)
			expect(header.find('.notification__summary').text()).toBe(summary)
		})

		it('renders the post concerned without an avatar and links to it', () => {
			const { wrapper } = mountEntry(notification('favourite'), { type: 'notifications' })

			expect(wrapper.findComponent(TimelinePostStub).props()).toEqual({ item: post, type: 'notifications' })
			expect(wrapper.findComponent(TimelineAvatarStub).exists()).toBe(false)
			expect(wrapper.findComponent(UserEntryStub).exists()).toBe(false)

			const link = wrapper.find('.notification__details').findComponent(RouterLinkStub)
			// the profile part of the link is the actor handle, not their display name
			expect(link.props('to')).toMatchObject({ name: 'single-post', params: { account: 'bob@remote.example', id: 'p1', type: 'single-post' } })
			expect(link.attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
		})

		it('renders a post notification whose status has gone without crashing and without a link', () => {
			const { wrapper } = mountEntry(notification('favourite', { status: null }), { type: 'notifications' })

			const details = wrapper.find('.notification__details')
			expect(details.findComponent(RouterLinkStub).exists()).toBe(false)
			expect(details.find('.post-timestamp').attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
			expect(wrapper.findComponent(TimelinePostStub).exists()).toBe(false)
		})
	})

	describe('a notification about an account', () => {
		it.each([
			['follow', 'account-plus-outline-icon', 'bob@remote.example started to follow you'],
			['follow_request', 'account-question-icon', 'bob@remote.example requested to follow you'],
		])('shows the %s as an account entry without a follow button', (type, iconClass, summary) => {
			const { wrapper } = mountEntry(notification(type, { status: undefined }), { type: 'notifications' })

			const header = wrapper.find('.notification__header')
			expect(header.find('.material-design-icon').classes()).toContain(iconClass)
			expect(header.find('.notification__summary').text()).toBe(summary)

			expect(wrapper.findComponent(UserEntryStub).props()).toEqual({ item: bob, displayFollowButton: false })
			expect(wrapper.findComponent(TimelinePostStub).exists()).toBe(false)
			expect(wrapper.findComponent(TimelineAvatarStub).exists()).toBe(false)

			// nothing to open, so the timestamp is not a link
			expect(wrapper.find('.notification__details').findComponent(RouterLinkStub).exists()).toBe(false)
			expect(wrapper.find('.notification__details .post-timestamp').attributes('data-timestamp')).toBe('2026-09-03T10:00:00Z')
		})
	})

	describe('an unknown notification type', () => {
		it('still shows the actor and the post, with an empty summary and no icon', () => {
			const { wrapper } = mountEntry(notification('something.new'), { type: 'notifications' })

			const header = wrapper.find('.notification__header')
			expect(header.find('img').attributes('src')).toBe(bob.avatar)
			expect(header.find('.material-design-icon').exists()).toBe(false)
			expect(header.find('.notification__summary').text()).toBe('')
			expect(wrapper.findComponent(TimelinePostStub).props('item')).toEqual(post)
		})
	})
})
