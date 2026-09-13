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
		rates: {
			total: 233,
			per_post: 5.4,
			applause: 4.3,
			amplification: 1,
			conversation: 0.1,
			per_follower: 20.84,
			median: 7,
			best: 14,
			silent: 16,
			silent_share: 37.2,
		},
		by_weekday: [
			{ day: 0, posts: 1, engagement: 0, average: 0 },
			{ day: 1, posts: 7, engagement: 65, average: 9.3 },
			{ day: 2, posts: 4, engagement: 0, average: 0 },
			{ day: 3, posts: 4, engagement: 24, average: 6 },
			{ day: 4, posts: 5, engagement: 37, average: 7.4 },
			{ day: 5, posts: 6, engagement: 48, average: 8 },
			{ day: 6, posts: 16, engagement: 59, average: 3.7 },
		],
		best_hour: { hour: 8, average: 9.3, posts: 3 },
		content: [
			{ key: 'text_only', posts: 39, engagement: 199, average: 5.1 },
			{ key: 'with_media', posts: 4, engagement: 35, average: 8.8 },
			{ key: 'original', posts: 34, engagement: 180, average: 5.3 },
		],
		hashtag_performance: [
			{ name: 'nextcloud', posts: 6, average: 9.3 },
			{ name: 'accessibility', posts: 4, average: 3 },
		],
		audience: {
			by_month: { '2026-08': 4, '2026-09': 22 },
			instances: [{ host: 'remote.example', count: 20 }, { host: 'cloud.example', count: 6 }],
			local_share: 23.1,
			counted: 26,
			capped: false,
		},
		engagement_by_month: { '2026-08': 180, '2026-09': 53 },
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

		const months = wrapper.findAll('.stats__bars--posts .stats__bar')
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

	it('shows the rates an agency reports on, not only the totals', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const shown = text(wrapper)
		expect(shown).toContain('5.4engagement per post')
		expect(shown).toContain('median 7')
		// one decimal on screen, whatever the server sent
		expect(shown).toContain('20.8%engagement rate')
		expect(shown).toContain('37.2%got no answer')
		// the best post against the middle one, which is the number a mean hides
		expect(shown).toContain('2× the median')
	})

	it('sorts what works by what it averages, best first', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const labels = wrapper.findAll('.stats__card')
			.find((card) => card.text().includes('What works'))
			.findAll('.stats__row dt')
			.map((dt) => dt.text())

		expect(labels[0]).toBe('With a picture or a video')
		expect(labels).toContain('Text only')
	})

	it('names the hour that works, and says nothing when there is too little to go on', async () => {
		axios.get.mockResolvedValue({ data: answer() })
		const wrapper = mountPage()
		await flushPromises()
		expect(text(wrapper)).toContain('8:00 UTC')

		axios.get.mockResolvedValue({ data: answer({ best_hour: { hour: null, average: 0, posts: 0 } }) })
		const quiet = mountPage()
		await flushPromises()
		expect(text(quiet)).toContain('Not enough posts at any one hour')
	})

	it('names the weekdays in the reader\'s own calendar, Sunday first as the server counts them', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const days = wrapper.findAll('.stats__card')
			.find((card) => card.text().includes('When your posts do best'))
			.findAll('.stats__row dt')
			.map((dt) => dt.text())

		expect(days).toHaveLength(7)
		expect(days[0]).toBe(new Date(Date.UTC(2024, 0, 7)).toLocaleDateString(undefined, { weekday: 'long' }))
	})

	it('says where the audience is and how much of it is on this server', async () => {
		axios.get.mockResolvedValue({ data: answer() })

		const wrapper = mountPage()
		await flushPromises()

		const shown = text(wrapper)
		expect(shown).toContain('remote.example')
		expect(shown).toContain('23.1% of your followers are on this server')
		expect(wrapper.findAll('.stats__bars--followers .stats__bar')).toHaveLength(2)
	})

	it('leaves out the sections the answer has nothing for', async () => {
		axios.get.mockResolvedValue({ data: answer({ best: [], hashtags: [] }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.stats__best').exists()).toBe(false)
		expect(wrapper.find('.stats__tags').exists()).toBe(false)

		axios.get.mockResolvedValue({ data: answer({ audience: undefined, content: [], by_weekday: [] }) })
		const bare = mountPage()
		await flushPromises()

		expect(bare.text()).not.toContain('Who is listening')
		expect(bare.text()).not.toContain('What works')
		expect(bare.text()).not.toContain('When your posts do best')
	})
})
