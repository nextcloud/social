/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import HeldPosts from '../../../src/components/HeldPosts.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const REVIEW = '/index.php/apps/social/api/v1/review'

const HELD = {
	id: '7',
	account_id: 'https://cloud.example/@alice',
	reason: 'first_post',
	text: 'hello everybody',
	spoiler_text: '',
	visibility: 'public',
	media_count: 2,
	created_at: '2026-09-15T10:00:00.000Z',
}

describe('the held posts of the reader', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { held: [HELD], reasons: [] } })
		axios.delete.mockResolvedValue({ data: {} })
	})

	/**
	 * The composer says a post was held at the moment it happens; somebody who
	 * closed the tab has to be able to find their writing again.
	 */
	it('shows what is waiting, with the words in it', async () => {
		const wrapper = mount(HeldPosts)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(REVIEW)
		expect(wrapper.text()).toContain('hello everybody')
		expect(wrapper.text()).toContain('Your first post here')
		expect(wrapper.text()).toContain('2 attachments')
	})

	it('says so when nothing of the reader is waiting', async () => {
		axios.get.mockResolvedValue({ data: { held: [] } })
		const wrapper = mount(HeldPosts)
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing of yours is waiting.')
	})

	it('takes one back', async () => {
		const wrapper = mount(HeldPosts)
		await flushPromises()

		await wrapper.find('.held-posts__withdraw').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${REVIEW}/7`)
		expect(wrapper.text()).toContain('Nothing of yours is waiting.')
	})

	it('says what went wrong rather than drawing an empty list', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const { showError } = await import('../../../src/services/toast.js')
		mount(HeldPosts)
		await flushPromises()

		expect(showError).toHaveBeenCalled()
	})
})
