/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Portfolio from '../../../src/views/Portfolio.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const alice = {
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	avatar: 'https://cloud.example.org/avatar/alice/64',
	url: 'https://cloud.example.org/@alice',
}

function work(id, overrides = {}) {
	return {
		id,
		url: `https://cloud.example.org/@alice/${id}`,
		content: '<p>A stairwell</p>',
		created_at: '2024-06-01T10:00:00Z',
		place: { id: '3', name: 'Lisbon', country: 'Portugal' },
		media_attachments: [{
			id: `m${id}`,
			type: 'image',
			url: `https://cloud.example.org/${id}.jpg`,
			preview_url: `https://cloud.example.org/${id}-small.jpg`,
			description: 'Concrete steps',
		}],
		...overrides,
	}
}

function page(overrides = {}) {
	return {
		id: '1',
		active: true,
		title: 'Selected work',
		intro: 'Buildings, mostly.',
		layout: 'grid',
		source: 'recent',
		show_captions: true,
		show_places: true,
		show_dates: false,
		show_avatar: true,
		account: alice,
		posts: [work('1'), work('2')],
		...overrides,
	}
}

function mountPage(account = 'alice') {
	return mount(Portfolio, {
		global: { mocks: { $route: { name: 'profile.portfolio', params: { account } } } },
	})
}

describe('Portfolio', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: page() })
	})

	it('asks the public route, which needs nobody signed in', async () => {
		mountPage('alice')
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1.1/portfolio/alice'))
	})

	it('draws the title, the sentence and one frame per picture', async () => {
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__title').text()).toBe('Selected work')
		expect(wrapper.text()).toContain('Buildings, mostly.')
		expect(wrapper.findAll('.portfolio__work')).toHaveLength(2)
	})

	/** A page its owner has not published is the same answer as one that is not there. */
	it('says there is none when the server refuses', async () => {
		get.mockRejectedValue({ response: { status: 404 } })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.text()).toContain('No portfolio here')
		expect(wrapper.findAll('.portfolio__work')).toHaveLength(0)
	})

	it('leaves out a post with no picture on it', async () => {
		get.mockResolvedValue({ data: page({ posts: [work('1'), { id: '9', content: '<p>words</p>' }] }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.findAll('.portfolio__work')).toHaveLength(1)
	})

	it('honours the switches its owner set', async () => {
		get.mockResolvedValue({
			data: page({ show_captions: false, show_places: false, show_avatar: false }),
		})

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__caption').exists()).toBe(false)
		expect(wrapper.find('.portfolio__meta').exists()).toBe(false)
		expect(wrapper.find('.portfolio__avatar').exists()).toBe(false)
	})

	it('puts the year beside the place when it was asked for', async () => {
		get.mockResolvedValue({ data: page({ show_dates: true }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__meta').text()).toContain('Lisbon, Portugal')
		expect(wrapper.find('.portfolio__meta').text()).toContain('2024')
	})

	it('links a picture to the page its own server names', async () => {
		get.mockResolvedValue({ data: page({ posts: [work('1', {
			url: 'https://remote.example/@bob/1',
			uri: 'https://remote.example/users/bob/statuses/1',
		})] }) })
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__frame').attributes('href')).toBe('https://remote.example/@bob/1')
	})

	/**
	 * Every address on this page was chosen by whichever server the post came
	 * from, and the page is served to anonymous readers.
	 */
	it('refuses to link an address that would run something', async () => {
		get.mockResolvedValue({ data: page({ posts: [work('1', {
			url: 'javascript:alert(1)',
			uri: 'javascript:alert(2)',
		})] }) })
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__frame').attributes('href')).toBeUndefined()
	})

	it('falls back to the id when that is the only address there is', async () => {
		get.mockResolvedValue({ data: page({ posts: [work('1', {
			url: '',
			uri: 'https://remote.example/users/bob/statuses/1',
		})] }) })
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__frame').attributes('href'))
			.toBe('https://remote.example/users/bob/statuses/1')
	})

	it('lays the page out the way its owner chose', async () => {
		get.mockResolvedValue({ data: page({ layout: 'rows' }) })

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.portfolio__works').classes()).toContain('portfolio__works--rows')
	})
})
