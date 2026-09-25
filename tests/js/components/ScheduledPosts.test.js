/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ScheduledPosts from '../../../src/components/ScheduledPosts.vue'
import eventBus from '../../../src/services/eventBus.js'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

// the composer's date picker, reduced to handing a time in and taking one back
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({
	__esModule: true,
	default: {
		name: 'NcDateTimePicker',
		props: ['modelValue', 'min', 'type', 'minuteStep', 'clearable', 'ariaLabel'],
		emits: ['update:modelValue'],
		template: '<div class="date-picker-stub" />',
	},
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

	/**
	 * A full page of waiting posts, so the list believes there is more behind it.
	 *
	 * @param {number} from the first id
	 * @return {object[]} fifty entries
	 */
	function page(from = 1) {
		return Array.from({ length: 50 }, (entry, index) => ({
			...scheduled,
			id: String(from + index),
		}))
	}

	// An account may hold 300 waiting posts — 25 a day over as many days as it
	// likes — and everything past the first fifty used to be invisible here,
	// and so impossible to cancel.
	it('offers the rest once the first page came back full', async () => {
		const wrapper = mountList(page())
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__more button').exists()).toBe(true)
	})

	it('offers nothing more when the page it got was a short one', async () => {
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('.scheduled-posts__more').exists()).toBe(false)
	})

	// `min_id`, not `max_id`: the list is drawn in the order the posts go out,
	// so the page after this one is the one scheduled later.
	it('asks for what is scheduled after the last one it holds', async () => {
		const wrapper = mountList(page())
		await flushPromises()

		axios.get.mockResolvedValue({ data: [{ ...scheduled, id: '51' }] })
		await wrapper.find('.scheduled-posts__more button').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(LIST, { params: { limit: 50, min_id: '50' } })
		expect(wrapper.findAll('.scheduled-posts__item')).toHaveLength(51)
		expect(wrapper.find('.scheduled-posts__more').exists()).toBe(false)
	})

	it('cancels a post that came in on a later page', async () => {
		const wrapper = mountList(page())
		await flushPromises()

		axios.get.mockResolvedValue({ data: [{ ...scheduled, id: '51' }] })
		await wrapper.find('.scheduled-posts__more button').trigger('click')
		await flushPromises()

		axios.delete.mockResolvedValue({ data: {} })
		await wrapper.findAll('.scheduled-posts__cancel')[50].trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(LIST + '/51')
		expect(wrapper.findAll('.scheduled-posts__item')).toHaveLength(50)
	})

	// A post moved to another time between the two requests can sit on both
	// sides of the cursor, and the same entry twice is two Cancel buttons for
	// one post.
	it('does not list the same post twice across a page boundary', async () => {
		const wrapper = mountList(page())
		await flushPromises()

		axios.get.mockResolvedValue({ data: [{ ...scheduled, id: '50' }, { ...scheduled, id: '51' }] })
		await wrapper.find('.scheduled-posts__more button').trigger('click')
		await flushPromises()

		expect(wrapper.findAll('.scheduled-posts__item')).toHaveLength(51)
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

	describe('changing the time', () => {
		const inDays = (days) => new Date(Date.now() + days * 24 * 60 * 60 * 1000)
		const picker = (wrapper) => wrapper.findComponent({ name: 'NcDateTimePicker' })
		const texts = (wrapper) => wrapper.findAll('.scheduled-posts__text').map((one) => one.text())

		/**
		 * Three posts a day apart, soonest first.
		 *
		 * @return {object[]}
		 */
		function three() {
			return [
				{ ...scheduled, id: '1', scheduled_at: inDays(1).toISOString(), params: { text: 'first' } },
				{ ...scheduled, id: '2', scheduled_at: inDays(2).toISOString(), params: { text: 'second' } },
				{ ...scheduled, id: '3', scheduled_at: inDays(3).toISOString(), params: { text: 'third' } },
			]
		}

		/**
		 * Opens the picker under one entry and picks a time in it.
		 *
		 * @param {object} wrapper the mounted list
		 * @param {number} index which entry
		 * @param {Date} when the time to pick
		 */
		async function pick(wrapper, index, when) {
			await wrapper.findAll('.scheduled-posts__reschedule')[index].trigger('click')
			await flushPromises()
			picker(wrapper).vm.$emit('update:modelValue', when)
			await flushPromises()
		}

		it('starts from the time the post already has', async () => {
			const wrapper = mountList()
			await flushPromises()

			await wrapper.find('.scheduled-posts__reschedule').trigger('click')
			await flushPromises()

			expect(picker(wrapper).props('modelValue').toISOString()).toBe(scheduled.scheduled_at)
			expect(picker(wrapper).props('minuteStep')).toBe(5)
			// nothing has changed yet, so there is nothing to save
			expect(wrapper.find('.scheduled-posts__save').attributes('disabled')).toBeDefined()
		})

		it('sends the new time to the post\'s own address', async () => {
			const wrapper = mountList(three())
			await flushPromises()
			const when = inDays(5)
			axios.put.mockResolvedValue({ data: { ...three()[0], scheduled_at: when.toISOString() } })

			await pick(wrapper, 0, when)
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(`${LIST}/1`, { scheduled_at: when.toISOString() })
			expect(showSuccess).toHaveBeenCalledWith(expect.stringContaining('Moved to'))
			expect(wrapper.find('.schedule-editor').exists()).toBe(false)
		})

		it('moves the entry to where its new time puts it', async () => {
			const wrapper = mountList(three())
			await flushPromises()
			const when = new Date(inDays(2).getTime() + 60 * 60 * 1000)
			axios.put.mockResolvedValue({ data: { ...three()[0], scheduled_at: when.toISOString() } })

			await pick(wrapper, 0, when)
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()

			expect(texts(wrapper)).toEqual(['second', 'first', 'third'])
			expect(wrapper.findAll('time')[1].attributes('datetime')).toBe(when.toISOString())
		})

		it('does not offer a time the server would refuse', async () => {
			const wrapper = mountList()
			await flushPromises()

			await pick(wrapper, 0, new Date(Date.now() + 60 * 1000))

			expect(wrapper.find('.schedule-editor__hint').text())
				.toBe('Pick a time at least five minutes from now.')
			expect(wrapper.find('.scheduled-posts__save').attributes('disabled')).toBeDefined()
		})

		/** The daily cap is only known to the server, so its word is shown. */
		it('leaves the entry as it was and says why when the server refuses', async () => {
			const entries = three()
			const wrapper = mountList(entries)
			await flushPromises()
			axios.put.mockRejectedValue({
				response: { status: 422, data: { error: 'this account already has 25 statuses scheduled for that day' } },
			})

			await pick(wrapper, 0, inDays(5))
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('this account already has 25 statuses scheduled for that day')
			expect(texts(wrapper)).toEqual(['first', 'second', 'third'])
			expect(wrapper.findAll('time')[0].attributes('datetime')).toBe(entries[0].scheduled_at)
			// the picker stays, so another time can be tried
			expect(wrapper.find('.schedule-editor').exists()).toBe(true)
		})

		it('says something even when the server gives no reason', async () => {
			const wrapper = mountList()
			await flushPromises()
			axios.put.mockRejectedValue(new Error('offline'))

			await pick(wrapper, 0, inDays(5))
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not change the time of the scheduled post')
		})

		it('still cancels a post that has been moved', async () => {
			const wrapper = mountList(three())
			await flushPromises()
			const when = inDays(5)
			axios.put.mockResolvedValue({ data: { ...three()[0], scheduled_at: when.toISOString() } })
			await pick(wrapper, 0, when)
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()
			expect(texts(wrapper)).toEqual(['second', 'third', 'first'])

			axios.delete.mockResolvedValue({ data: {} })
			await wrapper.findAll('.scheduled-posts__cancel')[2].trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${LIST}/1`)
			expect(texts(wrapper)).toEqual(['second', 'third'])
		})

		/**
		 * Left last, the moved entry would be the cursor for the next page,
		 * and that page would skip everything between its old time and its
		 * new one.
		 */
		it('lets a post moved past the end of a partial list go to the page it belongs on', async () => {
			const full = Array.from({ length: 50 }, (entry, index) => ({
				...scheduled,
				id: String(index + 1),
				scheduled_at: inDays(index + 1).toISOString(),
			}))
			const wrapper = mountList(full)
			await flushPromises()
			const when = inDays(80)
			axios.put.mockResolvedValue({ data: { ...full[0], scheduled_at: when.toISOString() } })

			await pick(wrapper, 0, when)
			await wrapper.find('.scheduled-posts__save').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('.scheduled-posts__item')).toHaveLength(49)

			axios.get.mockResolvedValue({ data: [] })
			await wrapper.find('.scheduled-posts__more button').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenLastCalledWith(LIST, { params: { limit: 50, min_id: '50' } })
		})
	})
})
