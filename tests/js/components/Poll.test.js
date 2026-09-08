/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import Poll from '../../../src/components/Poll.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const makePoll = (extra = {}) => ({
	id: '42',
	expires_at: new Date(Date.now() + 3600 * 1000).toISOString(),
	expired: false,
	multiple: false,
	votes_count: 10,
	voters_count: 10,
	voted: false,
	own_votes: [],
	options: [
		{ title: 'Cats', votes_count: 6 },
		{ title: 'Dogs', votes_count: 4 },
	],
	...extra,
})

const mountPoll = (poll = makePoll()) => mount(Poll, { props: { poll } })

describe('Poll', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('offers the options with a vote button while open and unvoted', () => {
		const wrapper = mountPoll()

		const inputs = wrapper.findAll('input')
		expect(inputs).toHaveLength(2)
		expect(inputs[0].attributes('type')).toBe('radio')
		expect(wrapper.text()).toContain('Cats')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
	})

	it('uses checkboxes for multiple-choice polls', () => {
		const wrapper = mountPoll(makePoll({ multiple: true }))

		expect(wrapper.find('input').attributes('type')).toBe('checkbox')
	})

	it('shows results once voted, marking the own choice', () => {
		const wrapper = mountPoll(makePoll({ voted: true, own_votes: [0] }))

		expect(wrapper.findAll('input')).toHaveLength(0)
		expect(wrapper.text()).toContain('60%')
		expect(wrapper.text()).toContain('40%')
	})

	it('shows results and the closed marker when expired', () => {
		const wrapper = mountPoll(makePoll({ expired: true }))

		expect(wrapper.findAll('input')).toHaveLength(0)
		expect(wrapper.text()).toContain('Closed')
	})

	it('votes and emits the refreshed poll', async () => {
		const refreshed = makePoll({ voted: true, own_votes: [1], votes_count: 11 })
		axios.post.mockResolvedValueOnce({ data: refreshed })
		const wrapper = mountPoll()

		await wrapper.findAll('input')[1].setValue()
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/polls/42/votes', { choices: [1] })
		expect(wrapper.emitted('update:poll')[0][0]).toEqual(refreshed)
	})

	it('reports a failed vote and keeps the options', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'this poll has ended' } } })
		const wrapper = mountPoll()

		await wrapper.findAll('input')[0].setValue()
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('this poll has ended')
		expect(wrapper.findAll('input')).toHaveLength(2)
	})
})
