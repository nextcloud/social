/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import UserEntry from '../../../src/components/UserEntry.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

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

let pinia
let accountStore

const makeStore = (serverData = {}) => {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	vi.spyOn(accountStore, 'fetchRelationship').mockResolvedValue([])

	return pinia
}

const mountEntry = (item, props = {}) => mount(UserEntry, {
	props: { item, ...props },
	global: {
		plugins: [pinia],
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

	it('asks for the relationship through the batching action, not one request per entry', () => {
		mountEntry(remote)
		// a page of twenty followers used to be twenty round-trips, because
		// the guard tested a `relationship` this component never defined
		expect(accountStore.fetchRelationship).toHaveBeenCalledWith('https://remote.example/users/bob')
	})

	it('does not ask again for a relationship the store already knows', () => {
		accountStore.addRelationship({
			actorId: remote.id,
			data: { id: remote.id, following: true, requested: false },
		})
		mountEntry(remote)
		expect(accountStore.fetchRelationship).not.toHaveBeenCalledWith(remote.id)
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
			expect(accountStore.fetchRelationship).not.toHaveBeenCalled()
		})
	})
})
