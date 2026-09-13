/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import Statistics from '../../../src/views/Statistics.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

function answer(overrides = {}) {
	return {
		account: {
			acct: 'alice',
			created_at: '2024-03-17T09:00:00.000Z',
			followers: 26,
			following: 24,
		},
		posts: {
			total: 43,
			originals: 34,
			replies: 9,
			boosts: 0,
			with_media: 4,
			sensitive: 1,
		},
		engagement: {
			likes: 185,
			boosts: 43,
			replies: 5,
			likes_per_post: 4.3,
			boosts_per_post: 1,
			replies_per_post: 0.1,
		},
		visibility: { public: 42, unlisted: 0, followers: 1, direct: 0 },
		by_month: { '2026-08': 39, '2026-09': 4 },
		by_hour: Array.from({ length: 24 }, (unused, hour) => (hour === 11 ? 5 : 0)),
		hashtags: [{ name: 'nextcloud', count: 6 }, { name: 'a11y', count: 1 }],
		best: [
			{ id: '7', url: 'https://cloud.example.org/@alice/7', published_at: '2026-09-01T10:00:00.000Z', excerpt: 'A good one', likes: 9, boosts: 4, replies: 2 },
		],
		window: { counted: 43, capped: false, max: 2000, first_at: '2026-08-03T21:30:34.000Z', last_at: '2026-09-12T23:20:02.000Z' },
		...overrides,
	}
}

function mountPage() {
	return mount(Statistics, {
		global: { stubs: { RouterLink: RouterLinkStub } },
		attachTo: document.body,
	})
}

const text = (wrapper) => wrapper.text().replace(/\s+/g, ' ')

describe('Statistics', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	it('asks the server once and shows what came back', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('/apps/social/api/v1/statistics')
		expect(text(wrapper)).toContain('@alice')
		expect(text(wrapper)).toContain('185')
		expect(text(wrapper)).toContain('likes received')
		expect(text(wrapper)).toContain('4.3 per post')
	})

	it('shows a spinner rather than an empty page while it counts', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__loading').exists()).toBe(true)
		expect(wrapper.find('.stats__card').exists()).toBe(false)
	})

	it('offers to try again rather than showing nothing when the count fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__error').exists()).toBe(true)

		axios.get.mockResolvedValue({ data: answer() })
		await wrapper.find('.stats__error button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.stats__error').exists()).toBe(false)
		expect(text(wrapper)).toContain('@alice')
	})

	it('scales the bars to the tallest column, so a quiet month is still visible', async () => {
		axios.get.mockResolvedValue({ data: answer({ by_month: { '2026-07': 0, '2026-08': 39, '2026-09': 4 } }) })

		const wrapper = mountPage()
		await flushPromises()

		const months = wrapper.findAll('.stats__bars--months .stats__bar')
		expect(months).toHaveLength(3)
		expect(months[1].attributes('style')).toContain('--height: 100%')
		expect(months[2].attributes('style')).toContain('--height: 10%')
		// nothing posted is nothing, not a bar of its own height
		expect(months[0].attributes('style')).toContain('--height: 0%')
	})

	it('says what every bar holds, for a reader who cannot see its height', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const hours = wrapper.findAll('.stats__bars--hours li')
		expect(hours).toHaveLength(24)
		expect(hours[11].attributes('title')).toContain('11:00')
		expect(hours[11].attributes('title')).toContain('5')
	})

	it('links a best post to the post, and a hashtag to its timeline', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const links = wrapper.findAllComponents(RouterLinkStub)
		expect(links[0].props('to')).toEqual({ name: 'single-post', params: { account: 'alice', id: '7' } })
		expect(links[1].props('to')).toEqual({ name: 'tags', params: { tag: 'nextcloud' } })
	})

	it('says how far back the numbers go, and says so differently when it stopped early', async () => {
		axios.get.mockResolvedValue({ data: answer() })
		const wrapper = mountPage()
		await flushPromises()
		expect(text(wrapper)).toContain('43')

		axios.get.mockResolvedValue({
			data: answer({ window: { counted: 2000, capped: true, max: 2000, first_at: '', last_at: '' } }),
		})
		const capped = mountPage()
		await flushPromises()
		expect(text(capped)).toContain('most recent posts')
	})

	it('leaves out the sections the answer has nothing for', async () => {
		axios.get.mockResolvedValue({ data: answer({ best: [], hashtags: [] }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__best').exists()).toBe(false)
		expect(wrapper.find('.stats__tags').exists()).toBe(false)
	})
})
