/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ReviewSection from '../../../../src/components/admin/ReviewSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const REVIEW = '/index.php/apps/social/moderation/review'

const HELD = {
	id: '7',
	account_id: 'https://cloud.example/@alice',
	username: 'alice',
	reason: 'first_post',
	text: 'hello everybody',
	spoiler_text: '',
	visibility: 'public',
	media_count: 0,
	created_at: '2026-09-15T10:00:00.000Z',
}

/**
 * @param {object} [props] what the page provides
 * @return {object} the mounted section
 */
function mountSection(props = {}) {
	return mount(ReviewSection, {
		props: {
			queue: [HELD],
			total: 1,
			reviewFirstPost: true,
			autospam: true,
			...props,
		},
	})
}

describe('the review section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	/**
	 * A row that only said "a post by @alice was held" would send a moderator
	 * looking for a post that is deliberately nowhere to be found.
	 */
	it('shows what was written and why it is waiting', () => {
		const wrapper = mountSection()

		expect(wrapper.text()).toContain('hello everybody')
		expect(wrapper.text()).toContain('The first post of a new account')
	})

	/**
	 * A column of actor URLs is the same forty characters over and over with
	 * the name buried at the end — and an unbreakable one takes the table's
	 * width with it.
	 */
	it('names the account by its handle, with the id behind it', () => {
		const wrapper = mountSection()
		const cell = wrapper.find('.review__account')

		expect(cell.text()).toBe('alice')
		expect(cell.attributes('title')).toBe('https://cloud.example/@alice')
	})

	it('says so when nothing is waiting', () => {
		const wrapper = mountSection({ queue: [], total: 0 })

		expect(wrapper.text()).toContain('Nothing is waiting.')
	})

	it('publishes a post and takes it out of the table', async () => {
		const wrapper = mountSection()

		await wrapper.findAll('button').find((button) => button.text() === 'Publish').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${REVIEW}/7/approve`)
		expect(wrapper.text()).toContain('Nothing is waiting.')
	})

	/**
	 * Refusing deletes somebody's writing and cannot be undone, so it asks
	 * first — and nothing is sent until the dialog is confirmed.
	 */
	it('asks before refusing', async () => {
		const wrapper = mountSection()

		await wrapper.findAll('button').find((button) => button.text() === 'Refuse').trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()

		await wrapper.vm.reject()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${REVIEW}/7/reject`)
	})

	it('saves both switches together, because the endpoint takes both', async () => {
		axios.post.mockResolvedValue({ data: { reviewFirstPost: true, autospam: false } })
		const wrapper = mountSection()

		await wrapper.vm.saveSettings(true, false)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${REVIEW}/settings`, {
			reviewFirstPost: true,
			autospam: false,
		})
		expect(wrapper.vm.autospamOn).toBe(false)
	})
})
