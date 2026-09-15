/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ArchivedPosts from '../../../src/components/ArchivedPosts.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const ARCHIVE = '/index.php/apps/social/api/pixelfed/v1/archive'

const POST = {
	id: '17894',
	content: '<p>a pier at <b>low tide</b></p>',
	created_at: '2026-09-15T10:00:00.000Z',
	media_attachments: [{ id: '1' }],
	archived: true,
}

describe('the archived posts of the reader', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: [POST] })
		axios.post.mockResolvedValue({ data: {} })
	})

	it('lists what was put away, as words rather than as posts', async () => {
		const wrapper = mount(ArchivedPosts)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${ARCHIVE}/list`, {
			params: { limit: 50, max_id: 0 },
		})
		// the markup is taken out: this is a list to find something in
		expect(wrapper.text()).toContain('a pier at low tide')
		expect(wrapper.text()).not.toContain('<b>')
		expect(wrapper.text()).toContain('1 attachment')
	})

	it('puts one back and takes it out of the list', async () => {
		const wrapper = mount(ArchivedPosts)
		await flushPromises()

		await wrapper.findAll('button').find((b) => b.text() === 'Put back').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${ARCHIVE}/remove/17894`)
		expect(wrapper.text()).toContain('You have not archived anything.')
	})

	it('says so when there is nothing in it', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const wrapper = mount(ArchivedPosts)
		await flushPromises()

		expect(wrapper.text()).toContain('You have not archived anything.')
	})
})
