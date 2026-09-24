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

const TimelineEntryStub = { name: 'TimelineEntry', props: ['item', 'type'], template: '<li class="timeline-entry-stub" />' }

const bob = { id: 'https://cloud.example.org/users/bob', acct: 'bob', username: 'bob', display_name: 'Bob' }
const statuses = [
	{ id: '1', content: '<p>first</p>', account: bob },
	{ id: '2', content: '<p>second</p>', account: bob },
]

let get

function mountSection(userId) {
	return mount(ProfilePageIntegration, {
		props: { userId },
		global: { stubs: { TimelineEntry: TimelineEntryStub } },
	})
}

describe('ProfilePageIntegration', () => {
	beforeEach(() => {
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({ data: url.endsWith('/statuses') ? statuses : bob }))
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('requests the account and its posts for the profile owner, URL-encoding the id', () => {
		mountSection('bob@remote.example')
		expect(get).toHaveBeenCalledTimes(2)
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/global/account/info?account=bob%40remote.example')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example/statuses')
	})

	it('renders the section heading and one entry per post', async () => {
		const wrapper = mountSection('bob')
		expect(wrapper.find('h2').text()).toBe('Social')
		expect(wrapper.findAllComponents(TimelineEntryStub)).toHaveLength(0)

		await flushPromises()
		expect(wrapper.findAllComponents(TimelineEntryStub).map((entry) => entry.props('item'))).toEqual(statuses)
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
		expect(wrapper.findAllComponents(TimelineEntryStub)).toHaveLength(0)
	})
})
