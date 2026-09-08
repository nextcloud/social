/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import FollowRequests from '../../../src/views/FollowRequests.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', avatar: 'https://remote.tld/bob.png' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', avatar: 'https://remote.tld/carol.png' }

const mountView = async (requests = [bob, carol]) => {
	axios.get.mockResolvedValueOnce({ data: requests })
	const wrapper = mount(FollowRequests, {
		global: {
			stubs: {
				NcAvatar: true,
				NcEmptyContent: { template: '<div class="empty-content"><slot /></div>' },
				RouterLink: RouterLinkStub,
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('FollowRequests', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('lists the accounts waiting for approval', async () => {
		const wrapper = await mountView()

		expect(axios.get).toHaveBeenCalledWith(API + '/follow_requests')
		const entries = wrapper.findAll('.follow-request')
		expect(entries).toHaveLength(2)
		expect(entries[0].text()).toContain('Bob')
		expect(entries[0].text()).toContain('bob@remote.tld')
	})

	it('shows the empty state when nothing is pending', async () => {
		const wrapper = await mountView([])

		expect(wrapper.find('.empty-content').exists()).toBe(true)
		expect(wrapper.findAll('.follow-request')).toHaveLength(0)
	})

	it('accepts a request and removes it from the list', async () => {
		const wrapper = await mountView()
		axios.post.mockResolvedValueOnce({ data: {} })

		await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/follow_requests/22/authorize')
		expect(wrapper.findAll('.follow-request')).toHaveLength(1)
		expect(wrapper.find('.follow-request').text()).toContain('Carol')
	})

	it('rejects a request and removes it from the list', async () => {
		const wrapper = await mountView()
		axios.post.mockResolvedValueOnce({ data: {} })

		await wrapper.findAll('.follow-request__actions button')[1].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/follow_requests/22/reject')
		expect(wrapper.findAll('.follow-request')).toHaveLength(1)
	})

	it('keeps the entry and reports the error when the decision fails', async () => {
		const wrapper = await mountView()
		axios.post.mockRejectedValueOnce(new Error('nope'))

		await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.findAll('.follow-request')).toHaveLength(2)
	})
})
