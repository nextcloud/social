/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import AnnouncementsSection from '../../../../src/components/admin/AnnouncementsSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const ANNOUNCEMENTS = '/index.php/apps/social/admin/announcements'

/**
 * One announcement, as the administration route sends it.
 *
 * @param {object} overrides what this one differs in
 * @return {object} the row
 */
function announcement(overrides = {}) {
	return {
		id: 3,
		text: 'Maintenance on Sunday',
		starts_at: '2026-09-20T08:00:00Z',
		ends_at: '2026-09-21T08:00:00Z',
		all_day: false,
		active: true,
		...overrides,
	}
}

/**
 * @param {object[]} announcements what the route answers with
 * @return {Promise<object>} the mounted section
 */
async function mountAnnouncements(announcements = [announcement()]) {
	axios.get.mockResolvedValue({ data: { announcements } })
	const wrapper = mount(AnnouncementsSection)
	await flushPromises()

	return wrapper
}

describe('the announcements section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('reads the list from the route it also writes through', async () => {
		const wrapper = await mountAnnouncements()

		expect(axios.get).toHaveBeenCalledWith(ANNOUNCEMENTS)
		expect(wrapper.text()).toContain('Maintenance on Sunday')
		expect(wrapper.text()).toContain('2026-09-20 08:00')
		expect(wrapper.text()).toContain('Shown now')
	})

	it('shows a whole-day window as days, without the time of day', async () => {
		const wrapper = await mountAnnouncements([announcement({ all_day: true })])

		expect(wrapper.text()).toContain('2026-09-20')
		expect(wrapper.text()).not.toContain('2026-09-20 08:00')
	})

	it('shows an unbounded announcement as one that has no bounds', async () => {
		const wrapper = await mountAnnouncements([announcement({ starts_at: null, ends_at: null })])

		expect(wrapper.text()).toContain('—')
	})

	it('says so rather than showing an empty table', async () => {
		const wrapper = await mountAnnouncements([])

		expect(wrapper.text()).toContain('No announcements.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('posts the window as the wall-clock time that was picked', async () => {
		const wrapper = await mountAnnouncements([])
		axios.post.mockResolvedValue({ data: { announcements: [announcement()] } })

		wrapper.vm.text = 'Down for maintenance'
		wrapper.vm.startsAt = new Date(2026, 8, 20, 8, 5)
		wrapper.vm.endsAt = new Date(2026, 8, 21, 17, 30)
		wrapper.vm.allDay = true
		await wrapper.vm.add()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ANNOUNCEMENTS, {
			text: 'Down for maintenance',
			starts_at: '2026-09-20T08:05',
			ends_at: '2026-09-21T17:30',
			all_day: true,
		})
		// redrawn from what is stored, because the server decides the window
		// an all-day announcement ends up with
		expect(wrapper.text()).toContain('Maintenance on Sunday')
		expect(wrapper.vm.text).toBe('')
		expect(wrapper.vm.startsAt).toBeNull()
	})

	it('posts nothing at all without a text', async () => {
		const wrapper = await mountAnnouncements([])

		wrapper.vm.text = '   '
		await wrapper.vm.add()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('asks before removing, because everybody stops seeing it', async () => {
		const wrapper = await mountAnnouncements()
		axios.delete.mockResolvedValue({ data: { announcements: [] } })

		await wrapper.find('tbody td:last-child button').trigger('click')
		expect(axios.delete).not.toHaveBeenCalled()

		await wrapper.vm.remove(wrapper.vm.pending)
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(ANNOUNCEMENTS + '/3')
		expect(wrapper.text()).toContain('No announcements.')
	})
})
