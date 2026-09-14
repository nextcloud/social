/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ReportsSection from '../../../../src/components/admin/ReportsSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const REPORTS = '/index.php/apps/social/moderation/reports'
const ACCOUNTS = '/index.php/apps/social/moderation/accounts'

/**
 * One report, as the route and the initial state both describe it.
 *
 * @param {object} overrides what this one differs in
 * @return {object} the row
 */
function report(overrides = {}) {
	return {
		id: 7,
		account_id: 'https://spam.example/users/spammer',
		account: 'spammer@spam.example',
		reporter: 'https://cloud.example/users/alice',
		local: true,
		category: 'spam',
		comment: 'endless crypto',
		status_ids: ['https://spam.example/notes/1'],
		creation: 1_700_000_000,
		resolved: false,
		level: '',
		...overrides,
	}
}

/**
 * A page of reports as /moderation/reports answers it.
 *
 * @param {object[]} reports the rows
 * @param {number} total how many there are in all
 * @return {object} the answer
 */
function page(reports, total) {
	return { data: { reports, total, page: 1, perPage: 50 } }
}

/**
 * @param {object} props what the page provided
 * @return {object} the mounted section
 */
function mountReports(props = {}) {
	return mount(ReportsSection, {
		props: {
			reports: [report()],
			openTotal: 1,
			resolvedTotal: 0,
			perPage: 50,
			...props,
		},
	})
}

describe('the reports section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('draws the first page it was given without asking for it', () => {
		const wrapper = mountReports()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('spammer@spam.example')
		expect(wrapper.text()).toContain('endless crypto')
		// the date the rows have always carried: UTC, to the minute
		expect(wrapper.text()).toContain('2023-11-14 22:13')
	})

	it('says so rather than showing an empty table when nothing is open', () => {
		const wrapper = mountReports({ reports: [], openTotal: 0 })

		expect(wrapper.text()).toContain('No open reports.')
		expect(wrapper.find('table').exists()).toBe(false)
		// the page this replaced said both at once: "No open reports." over a
		// line reading "0 open reports."
		expect(wrapper.text()).not.toContain('0 open reports.')
	})

	it('offers a further page only when there is one', () => {
		expect(mountReports({ openTotal: 50 }).text()).not.toContain('Show more')
		expect(mountReports({ openTotal: 51 }).text()).toContain('Show more')
	})

	it('appends the next page of the open reports', async () => {
		axios.get.mockResolvedValue(page([report({ id: 8, comment: 'more of it' })], 120))
		const wrapper = mountReports({ openTotal: 120 })

		await wrapper.vm.loadReports(false)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(REPORTS, { params: { resolved: '0', page: 2 } })
		expect(wrapper.text()).toContain('more of it')
		expect(wrapper.vm.open).toHaveLength(2)
	})

	it('asks for each page once, however fast the button is clicked', async () => {
		axios.get.mockResolvedValue(page([report({ id: 8 })], 300))
		const wrapper = mountReports({ openTotal: 300 })

		await Promise.all([wrapper.vm.loadReports(false), wrapper.vm.loadReports(false)])

		expect(axios.get.mock.calls.map(([, config]) => config.params.page)).toEqual([2, 3])
	})

	it('offers the page again when the request for it failed', async () => {
		axios.get.mockRejectedValue(new Error('no'))
		const wrapper = mountReports({ openTotal: 120 })

		await wrapper.vm.loadReports(false)
		await flushPromises()

		expect(wrapper.vm.openPage).toBe(1)
	})

	it('leaves the resolved reports folded away and unread until the fold opens', async () => {
		axios.get.mockResolvedValue(page([report({ id: 9, resolved: true, comment: 'dealt with' })], 1))
		const wrapper = mountReports({ resolvedTotal: 3 })

		expect(wrapper.text()).toContain('3 resolved reports')
		expect(axios.get).not.toHaveBeenCalled()

		const fold = wrapper.find('details')
		fold.element.open = true
		await fold.trigger('toggle')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(REPORTS, { params: { resolved: '1', page: 1 } })
		expect(wrapper.text()).toContain('dealt with')
	})

	it('reads the resolved reports once, however often the fold is worked', async () => {
		axios.get.mockResolvedValue(page([report({ id: 9, resolved: true })], 1))
		const wrapper = mountReports({ resolvedTotal: 3 })
		const fold = wrapper.find('details')

		fold.element.open = true
		await fold.trigger('toggle')
		await flushPromises()
		fold.element.open = false
		await fold.trigger('toggle')
		fold.element.open = true
		await fold.trigger('toggle')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('offers no fold at all on an instance that has resolved nothing', () => {
		expect(mountReports({ resolvedTotal: 0 }).find('details').exists()).toBe(false)
	})

	it('resolves a report and leaves the row where it is', async () => {
		const wrapper = mountReports()

		await wrapper.vm.toggleResolved(wrapper.vm.open[0])
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(REPORTS + '/7/resolve', { resolved: true })
		// moving it to the other table would renumber the pages under the
		// cursor of whoever is working through them
		expect(wrapper.vm.open).toHaveLength(1)
		expect(wrapper.text()).toContain('Reopen')
	})

	it('silences an account without asking first', async () => {
		const wrapper = mountReports()

		await wrapper.vm.askModerate({ report: wrapper.vm.open[0], level: 'silence' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ACCOUNTS, {
			actorId: 'https://spam.example/users/spammer',
			level: 'silence',
			comment: '',
		})
		expect(wrapper.text()).toContain('Silenced')
	})

	it('asks before suspending, and says what it will cost', async () => {
		const wrapper = mountReports()

		await wrapper.vm.askModerate({ report: wrapper.vm.open[0], level: 'suspend' })
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.pending.message)
			.toContain('Lifting the suspension later will not bring the posts back')

		await wrapper.vm.pending.go()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ACCOUNTS, {
			actorId: 'https://spam.example/users/spammer',
			level: 'suspend',
			comment: '',
		})
	})

	it('asks before taking a post down, and then says it is gone', async () => {
		const wrapper = mountReports()

		await wrapper.vm.askTakedown({
			report: wrapper.vm.open[0],
			statusId: 'https://spam.example/notes/1',
		})
		expect(axios.post).not.toHaveBeenCalled()

		await wrapper.vm.pending.go()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/moderation/statuses/remove', {
			streamId: 'https://spam.example/notes/1',
		})
		expect(wrapper.text()).toContain('Taken down')
	})
})
