/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import TrendingHashtags from '../../../src/components/TrendingHashtags.vue'
import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

/**
 * @param {string} name the hashtag, without its '#'
 * @param {number} uses how often it was used in the window
 * @return {object} a Tag entity as the trends endpoint hands one over
 */
function tag(name, uses) {
	return {
		name,
		url: `https://cloud.example.org/tags/${name}`,
		history: [{ day: '1789000000', uses: String(uses), accounts: '0' }],
	}
}

/**
 * Answers the two requests the list makes.
 *
 * @param {object[]} tags what the trends endpoint returns
 * @param {object[]|Error} followed what the followed-tags endpoint returns
 */
function serve(tags, followed = []) {
	axios.get.mockImplementation(async (url) => {
		if (url.endsWith('/followed_tags')) {
			if (followed instanceof Error) {
				throw followed
			}

			return { data: followed }
		}

		return { data: tags }
	})
}

function mountTrends({ isPublic = false } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: isPublic, cloudAddress: 'https://cloud.example.org' })

	return mount(TrendingHashtags, {
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
	})
}

const names = (wrapper) => wrapper.findAll('.trending__name').map((el) => el.text())
/**
 * @param {object} wrapper the mounted list
 * @param {string} label the window's name, as the reader reads it
 * @return {object} the button that chooses it
 */
function periodButton(wrapper, label) {
	return wrapper.findAll('.trending__periods button').find((button) => button.text() === label)
}

describe('TrendingHashtags', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		serve([])
	})

	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks for today over the whole ranking the server will give', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/trends/tags`, { params: { limit: 20, period: '1d' } })
		expect(names(wrapper)).toEqual(['#nextcloud'])
	})

	it('says how often each tag was used', async () => {
		serve([tag('nextcloud', 4), tag('fediverse', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findAll('.trending__count').map((el) => el.text())).toEqual(['4 posts', '1 post'])
	})

	it('draws each tag against the busiest one, which is the only comparison the numbers support', async () => {
		serve([tag('nextcloud', 10), tag('fediverse', 5), tag('vue', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		const widths = wrapper.findAll('.trending__bar-fill').map((el) => el.attributes('style'))
		expect(widths[0]).toContain('width: 100%')
		expect(widths[1]).toContain('width: 50%')
		// a floor, so the quietest tag is a bar rather than an empty track
		expect(widths[2]).toContain('width: 10%')
	})

	it('never draws an empty track for a tag that was used', async () => {
		serve([tag('nextcloud', 100), tag('quiet', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findAll('.trending__bar-fill')[1].attributes('style')).toContain('width: 6%')
	})

	it('re-ranks over another window rather than relabelling this one', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()
		serve([tag('fediverse', 40)])

		await periodButton(wrapper, 'Last 10 days').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/trends/tags`, { params: { limit: 20, period: '10d' } })
		expect(names(wrapper)).toEqual(['#fediverse'])
	})

	it('does not ask again for the window already on screen', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()
		axios.get.mockClear()

		await periodButton(wrapper, 'Today').trigger('click')
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('ignores the answer to a window the reader has moved on from', async () => {
		let resolveSlow
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/followed_tags')) {
				return { data: [] }
			}

			if (url.includes('period')) {
				return { data: [] }
			}

			return new Promise((resolve) => {
				resolveSlow = resolve
			})
		})
		const wrapper = mountTrends()
		// the first window's answer is still in flight
		serve([tag('fediverse', 40)])
		await periodButton(wrapper, 'Last hour').trigger('click')
		await flushPromises()
		resolveSlow?.({ data: [tag('stale', 999)] })
		await flushPromises()

		expect(names(wrapper)).toEqual(['#fediverse'])
	})

	it('reads which tags are followed once for the page, not once per button', async () => {
		serve([tag('nextcloud', 4), tag('fediverse', 2)], [tag('fediverse', 2)])
		const wrapper = mountTrends()
		await flushPromises()

		const lookups = axios.get.mock.calls.filter(([url]) => /\/tags\/[^/]+$/.test(url))
		expect(lookups).toHaveLength(0)
		expect(axios.get).toHaveBeenCalledWith(`${API}/followed_tags`, { params: { limit: 200 } })

		const buttons = wrapper.findAllComponents(HashtagFollowButton)
		expect(buttons[0].text()).toBe('Follow')
		expect(buttons[1].text()).toBe('Following')
	})

	it('leaves each button to ask for itself when that list could not be read', async () => {
		serve([tag('nextcloud', 4)], new Error('nope'))
		mountTrends()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/tags/nextcloud`)
	})

	it('asks nobody about following on a page with no account behind it', async () => {
		serve([tag('nextcloud', 4)])
		mountTrends({ isPublic: true })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalledWith(`${API}/followed_tags`, expect.anything())
	})

	it('keeps a follow made here when the window changes', async () => {
		serve([tag('nextcloud', 4)], [])
		const wrapper = mountTrends()
		await flushPromises()

		wrapper.findComponent(HashtagFollowButton).vm.$emit('changed', { tag: 'nextcloud', following: true })
		await flushPromises()
		await periodButton(wrapper, 'Last 3 days').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent(HashtagFollowButton).text()).toBe('Following')
	})

	it('says a quiet window is quiet rather than that nobody uses hashtags', async () => {
		serve([])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.find('.empty-content__description').text())
			.toBe('Nothing was tagged in this stretch of time. Try a longer one.')

		serve([])
		await periodButton(wrapper, 'Last 10 days').trigger('click')
		await flushPromises()

		// over ten days, an empty list really is an instance with no hashtags
		expect(wrapper.find('.empty-content__description').text())
			.toBe('Hashtags people are using will appear here.')
	})

	it('offers the reader another go when the ranking could not be read', async () => {
		axios.get.mockRejectedValue(new Error('busy'))
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.find('.trending__error').text()).toContain('Could not load this')

		serve([tag('nextcloud', 4)])
		await wrapper.find('.trending__error button').trigger('click')
		await flushPromises()

		expect(names(wrapper)).toEqual(['#nextcloud'])
	})

	it('links each tag at its timeline', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findComponent(RouterLinkStub).props('to'))
			.toEqual({ name: 'tags', params: { tag: 'nextcloud' } })
	})
})
