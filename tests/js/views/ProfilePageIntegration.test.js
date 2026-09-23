/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ProfilePageIntegration from '../../../src/views/ProfilePageIntegration.vue'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ProfileStatusCardStub = { name: 'ProfileStatusCard', props: ['status'], template: '<li class="profile-status-card-stub" />' }

const bob = { id: 'https://cloud.example.org/users/bob', acct: 'bob', username: 'bob', display_name: 'Bob' }
const statuses = [
	{ id: '1', content: '<p>first</p>', account: bob },
	{ id: '2', content: '<p>second</p>', account: bob },
]
const homeStatuses = [
	{ id: '2', content: '<p>second from the home feed</p>', account: { ...bob, acct: 'alice' } },
	{ id: 'home-1', content: '<p>From someone followed</p>', account: { ...bob, acct: 'followed@example.org' } },
]

let get

function mountSection(userId) {
	return mount(ProfilePageIntegration, {
		props: { userId },
		global: { stubs: { ProfileStatusCard: ProfileStatusCardStub, Composer: { template: '<div class="composer-stub" />' } } },
	})
}

describe('ProfilePageIntegration', () => {
	beforeEach(() => {
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.endsWith('/timelines/home') ? homeStatuses : url.endsWith('/statuses') ? statuses : bob,
		}))
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('requests the profile posts by URL-encoded account id', () => {
		mountSection('bob@remote.example')
		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example/statuses')
	})

	it('renders the section heading and one entry per post', async () => {
		const wrapper = mountSection('bob')
		expect(wrapper.find('h2').text()).toBe('Social')
		expect(wrapper.findAllComponents(ProfileStatusCardStub)).toHaveLength(0)

		await flushPromises()
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status'))).toEqual(statuses)
		expect(wrapper.find('.social-profile__counts').exists()).toBe(false)
	})

	it('adds the authenticated reader home feed to their own native profile', async () => {
		const wrapper = mountSection('alice')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/home', { params: { limit: 20 } })
		expect(wrapper.find('.social-profile__home-heading').text()).toContain('My Feed')
		expect(wrapper.find('.composer-stub').exists()).toBe(true)
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['1', '2', 'home-1'])
		expect(wrapper.findAllComponents(ProfileStatusCardStub).filter((entry) => entry.props('status').id === '2')).toHaveLength(1)
	})

	it('does not contact the server without a user id', () => {
		const wrapper = mountSection('')
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('h2').text()).toBe('Social')
	})

	it('keeps the section usable when the posts cannot be loaded', async () => {
		get.mockRejectedValue(new Error('404'))
		const wrapper = mountSection('bob')
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Social')
		expect(wrapper.findAllComponents(ProfileStatusCardStub)).toHaveLength(0)
	})
})
