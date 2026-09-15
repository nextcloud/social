/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import MediaBlocksSection from '../../../../src/components/admin/MediaBlocksSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const BLOCKS = '/index.php/apps/social/moderation/media/blocks'
const HASH = 'a'.repeat(64)

describe('the refused pictures section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { blocks: [] } })
		axios.post.mockResolvedValue({ data: { blocks: [] } })
		axios.delete.mockResolvedValue({ data: { blocks: [] } })
	})

	it('says so when nothing is refused', async () => {
		const wrapper = mount(MediaBlocksSection)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(BLOCKS)
		expect(wrapper.text()).toContain('Nothing is refused.')
	})

	/** "Turned away 41 times" and "never" are different decisions to review. */
	it('shows the reason and how often the file has been turned away', async () => {
		axios.get.mockResolvedValue({
			data: {
				blocks: [{
					hash: HASH,
					reason: 'the same image for the third time',
					moderator: 'alice',
					blocked: 41,
					creation: '2026-09-15 10:00:00',
				}],
			},
		})
		const wrapper = mount(MediaBlocksSection)
		await flushPromises()

		expect(wrapper.text()).toContain('the same image for the third time')
		expect(wrapper.text()).toContain('41')
		expect(wrapper.text()).toContain('alice')
	})

	it('sends the checksum and the reason, and clears the boxes', async () => {
		const wrapper = mount(MediaBlocksSection)
		await flushPromises()

		wrapper.vm.hash = HASH
		wrapper.vm.reason = 'posted again by the next account'
		await wrapper.vm.add()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(BLOCKS, {
			hash: HASH,
			reason: 'posted again by the next account',
		})
		expect(wrapper.vm.hash).toBe('')
	})

	it('shows the reason the server gave for refusing a bad checksum', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not a sha256 hash' } } })
		const wrapper = mount(MediaBlocksSection)
		await flushPromises()

		wrapper.vm.hash = 'nonsense'
		await wrapper.vm.add()
		await flushPromises()

		const { showError } = await import('../../../../src/services/toast.js')
		expect(showError).toHaveBeenCalledWith('that is not a sha256 hash')
	})
})
