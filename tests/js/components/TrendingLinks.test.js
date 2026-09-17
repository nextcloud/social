/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import TrendingLinks from '../../../src/components/TrendingLinks.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

/**
 * @param {object} fields what to override on the card
 * @return {object} a Trends::Link entity as the endpoint hands one over
 */
function link(fields = {}) {
	return {
		url: 'https://paper.example/piece',
		title: 'A headline',
		description: 'What the piece is about',
		image: 'https://paper.example/piece.jpg',
		provider_name: 'The Paper',
		history: [{ day: '1789000000', uses: '4', accounts: '0' }],
		...fields,
	}
}

/** @param {object[]|Error} links what the trends endpoint answers with */
function serve(links) {
	axios.get.mockImplementation(async () => {
		if (links instanceof Error) {
			throw links
		}

		return { data: links }
	})
}

function mountLinks() {
	return mount(TrendingLinks, {
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
}

const titles = (wrapper) => wrapper.findAll('.links__title').map((el) => el.text())

describe('TrendingLinks', () => {
	beforeEach(() => {
		axios.get.mockReset()
		serve([])
	})

	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks for today over the whole ranking the server will give', async () => {
		serve([link()])
		const wrapper = mountLinks()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/trends/links`, { params: { limit: 20, period: '1d' } })
		expect(titles(wrapper)).toEqual(['A headline'])
	})

	it('leads with the headline and names who published it', async () => {
		serve([link()])
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.find('.links__provider').text()).toBe('The Paper')
		expect(wrapper.find('.links__article').attributes('href')).toBe('https://paper.example/piece')
	})

	/** A page that did not say who published it is still somewhere. */
	it('falls back to the host when the page named no publisher', async () => {
		serve([link({ provider_name: '' })])
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.find('.links__provider').text()).toBe('paper.example')
	})

	it('says how many posts carried each link', async () => {
		serve([link(), link({ url: 'https://other.example/x', history: [{ uses: '1' }] })])
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.findAll('.links__conversation').map((el) => el.text())).toEqual(['4 posts', '1 post'])
	})

	/**
	 * The thing this app can say about an article that a reader elsewhere
	 * cannot: who here is talking about it. The link rides in the query, which
	 * is what `/api/v1/timelines/link` takes.
	 */
	it('opens the conversation about a link rather than only the link', async () => {
		serve([link()])
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
			name: 'timeline',
			params: { type: 'link' },
			query: { url: 'https://paper.example/piece' },
		})
	})

	/** A row with no headline is a picture and a number. */
	it('drops a card the server could not read a title from', async () => {
		serve([link(), link({ url: 'https://other.example/x', title: '' })])
		const wrapper = mountLinks()
		await flushPromises()

		expect(titles(wrapper)).toEqual(['A headline'])
	})

	it('re-ranks over another window rather than relabelling this one', async () => {
		serve([link()])
		const wrapper = mountLinks()
		await flushPromises()
		serve([link({ url: 'https://other.example/x', title: 'Another headline' })])

		await wrapper.findAll('.links__periods button')
			.find((button) => button.text() === 'Last 10 days')
			.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/trends/links`, { params: { limit: 20, period: '10d' } })
		expect(titles(wrapper)).toEqual(['Another headline'])
	})

	/**
	 * An empty list means nothing was shared in *this* window, which is a
	 * different thing from an instance where nobody posts links at all.
	 */
	it('tells a quiet window apart from a quiet instance', async () => {
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.text()).toContain('Try a longer one')

		await wrapper.findAll('.links__periods button')
			.find((button) => button.text() === 'Last 10 days')
			.trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Articles people here are sharing will appear here.')
	})

	it('offers a retry rather than an empty list when the request fails', async () => {
		serve(new Error('nope'))
		const wrapper = mountLinks()
		await flushPromises()

		expect(wrapper.find('.links__error').exists()).toBe(true)
		expect(wrapper.text()).toContain('Try again')
	})

	/** A broken picture leaves a broken frame in the middle of the row. */
	it('falls back to the placeholder when a picture will not load', async () => {
		serve([link()])
		const wrapper = mountLinks()
		await flushPromises()

		await wrapper.find('img.links__image').trigger('error')

		expect(wrapper.find('img.links__image').exists()).toBe(false)
		expect(wrapper.find('.links__image--blank').exists()).toBe(true)
	})
})
