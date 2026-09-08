/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import UserEntry from '../../../src/components/UserEntry.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = structuredClone(account.state)

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}
const FollowButtonStub = {
	name: 'FollowButton',
	props: ['uid'],
	template: '<button class="follow-button-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}

const local = {
	id: 'https://cloud.example.org/users/carol',
	url: 'https://cloud.example.org/users/carol',
	acct: 'carol',
	username: 'carol',
	display_name: 'Carol',
	avatar: 'https://cloud.example.org/avatar/carol/128',
	note: '<p>Local <strong>bio</strong></p>',
}
const remote = {
	id: 'https://remote.example/users/bob',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	avatar: 'https://remote.example/media/bob.png',
	note: '<p>Hi <script>alert(1)</script><a href="javascript:alert(2)" onclick="x()">link</a> <a href="https://remote.example/bob" target="_self" rel="opener">site</a></p>',
}

let store

const makeStore = (serverData = {}) => {
	Object.assign(account.state, structuredClone(pristine))
	store = createStore({ modules: { account, settings } })
	store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	vi.spyOn(store, 'dispatch').mockResolvedValue([])
	return store
}

const mountEntry = (item, props = {}) => mount(UserEntry, {
	props: { item, ...props },
	global: {
		plugins: [store],
		stubs: { NcAvatar: NcAvatarStub, FollowButton: FollowButtonStub, RouterLink: RouterLinkStub },
	},
})

describe('UserEntry', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the names and links a local account to its profile', () => {
		const wrapper = mountEntry(local)
		expect(wrapper.find('.post-author').text()).toBe('Carol')
		expect(wrapper.find('.user-description').text()).toBe('carol')
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'carol' } })
		expect(wrapper.find('a[target="_blank"]').exists()).toBe(false)
	})

	it('uses the server avatar for a local account', () => {
		const avatar = mountEntry(local).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('carol')
		expect(avatar.props('size')).toBe(32)
		expect(avatar.props('disableTooltip')).toBe(true)
		expect(avatar.props('url')).toBeUndefined()
	})

	it('uses the delivered avatar URL for a remote account and links to its full handle', () => {
		const wrapper = mountEntry(remote)
		expect(wrapper.findComponent(NcAvatarStub).props('url')).toBe('https://remote.example/media/bob.png')
		expect(wrapper.findComponent(NcAvatarStub).props('user')).toBeUndefined()
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'bob@remote.example' } })
	})

	it('injects the bio only after sanitising the remote HTML', () => {
		const bio = mountEntry(remote).find('.user-details p')
		expect(bio.text()).toBe('Hi link site')
		expect(bio.html()).not.toContain('<script')
		expect(bio.html()).not.toContain('javascript:')
		expect(bio.html()).not.toContain('onclick')
		const [dangerous, safe] = bio.findAll('a')
		// the javascript: target is dropped, the text is kept
		expect(dangerous.attributes('href')).toBeUndefined()
		// a real link is forced to open safely in a new tab
		expect(safe.attributes('href')).toBe('https://remote.example/bob')
		expect(safe.attributes('rel')).toBe('nofollow noopener noreferrer')
		expect(safe.attributes('target')).toBe('_blank')
	})

	it('keeps the formatting of a harmless bio', () => {
		expect(mountEntry(local).find('.user-details p').html()).toContain('<p>Local <strong>bio</strong></p>')
	})

	it('renders an empty bio paragraph when the account has no note', () => {
		expect(mountEntry({ ...local, note: undefined }).find('.user-details p').text()).toBe('')
	})

	it('fetches the relationship with the account for logged-in viewers', () => {
		mountEntry(remote)
		expect(store.dispatch).toHaveBeenCalledWith('fetchAccountRelationshipInfo', ['https://remote.example/users/bob'])
	})

	it('shows the follow button by default and hides it on request', () => {
		expect(mountEntry(remote).findComponent(FollowButtonStub).props('uid')).toBe('bob@remote.example')
		expect(mountEntry(remote, { displayFollowButton: false }).findComponent(FollowButtonStub).exists()).toBe(false)
	})

	describe('on the public page', () => {
		beforeEach(() => {
			makeStore({ public: true })
		})

		it('links straight to the remote profile in a new tab instead of the app route', () => {
			const wrapper = mountEntry(remote)
			const link = wrapper.find('a[target="_blank"]')
			expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false)
			expect(link.attributes('href')).toBe('https://remote.example/users/bob')
			expect(link.attributes('rel')).toBe('noreferrer')
			expect(link.find('.post-author').text()).toBe('Bob')
		})

		it('does not ask the server for a relationship', () => {
			mountEntry(remote)
			expect(store.dispatch).not.toHaveBeenCalled()
		})
	})
})
