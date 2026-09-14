/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ScheduledPosts from '../../../src/components/ScheduledPosts.vue'
import eventBus from '../../../src/services/eventBus.js'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const LIST = '/index.php/apps/social/api/v1/scheduled_statuses'

const scheduled = {
	id: '17',
	scheduled_at: '2026-10-01T09:30:00.000Z',
	params: { text: 'The release notes, once the release exists', visibility: 'private' },
	media_attachments: [],
}

// every list left mounted keeps listening on the event bus, so each one is
// taken down again after its test
const mounted = []

function mountList(entries = [scheduled]) {
	axios.get.mockResolvedValue({ data: entries })
	const wrapper = mount(ScheduledPosts, { attachTo: document.body })
	mounted.push(wrapper)

	return wrapper
}

describe('ScheduledPosts', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	afterEach(() => {
		while (mounted.length > 0) {
			mounted.pop().unmount()
		}
		vi.restoreAllMocks()
	})

	it('asks the server what is waiting to go out', async () => {
		mountList()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(LIST, { params: { limit: 50 } })
	})

	it('shows each post with its time and what it says', async () => {
		const wrapper = mountList()
		await flushPromises()

		const item = wrapper.find('.scheduled-posts__item')
		expect(item.exists()).toBe(true)
		expect(item.find('.scheduled-posts__text').text())
			.toBe('The release notes, once the release exists')
		expect(item.find('time').attributes('datetime')).toBe(scheduled.scheduled_at)
		expect(item.find('time').text()).not.toBe('')
	})

	/** Mastodon's `private` is this app's `followers`; the icon knows the latter. */
	it('translates the audience into the one the icon knows', async () => {
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('.account-multiple-icon').exists()).toBe(true)
	})

	it('counts the pictures a scheduled post is carrying', async () => {
		const wrapper = mountList([{ ...scheduled, media_attachments: [{ id: '1' }, { id: '2' }] }])
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__meta').text()).toBe('2 attachments')
	})

	it('says where a scheduled post comes from when there are none', async () => {
		const wrapper = mountList([])
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__hint').text())
			.toBe('Nothing is waiting to be posted. The clock in the composer schedules a post for later.')
		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(false)
	})

	it('takes a post back and stops showing it', async () => {
		axios.delete.mockResolvedValue({ data: {} })
		const wrapper = mountList()
		await flushPromises()

		await wrapper.find('.scheduled-posts__cancel').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${LIST}/17`)
		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(false)
	})

	/** A cancellation that failed leaves the post scheduled, so it stays listed. */
	it('keeps the post listed when the server refuses to cancel it', async () => {
		axios.delete.mockRejectedValue(new Error('nope'))
		const wrapper = mountList()
		await flushPromises()

		await wrapper.find('.scheduled-posts__cancel').trigger('click')
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(true)
		expect(showError).toHaveBeenCalledWith('Could not cancel the scheduled post')
	})

	it('says so rather than showing an empty list when it cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(ScheduledPosts, { attachTo: document.body })
		mounted.push(wrapper)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load your scheduled posts')
		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(false)
	})

	/**
	 * The composer's dialog opens over this page, so a post scheduled from it
	 * belongs in the list without a reload.
	 */
	it('picks up a post scheduled while the page is open', async () => {
		const wrapper = mountList([])
		await flushPromises()
		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(false)

		axios.get.mockResolvedValue({ data: [scheduled] })
		eventBus.emit('post-scheduled', scheduled)
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__item').exists()).toBe(true)
	})

	it('stops listening once it is gone', async () => {
		const wrapper = mountList([])
		await flushPromises()
		mounted.pop()
		wrapper.unmount()
		axios.get.mockClear()

		eventBus.emit('post-scheduled', scheduled)
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})
})
