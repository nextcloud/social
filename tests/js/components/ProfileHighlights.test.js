/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ProfileHighlights from '../../../src/components/ProfileHighlights.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * @param {object} data what the server answers
 * @return {object} the mounted component, once its request has settled
 */
async function mountHighlights(data) {
	axios.get.mockResolvedValue({ data })

	const wrapper = mount(ProfileHighlights, {
		props: { accountId: '42' },
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()

	return wrapper
}

function available(overrides = {}) {
	return {
		available: true,
		since: 1600000000,
		weeks: [0, 0, 1, 4, 2, 0, 0, 8, 3, 1, 0, 2],
		week_starts: 1750000000,
		hashtags: [{ name: 'nextcloud', count: 12 }],
		...overrides,
	}
}

describe('ProfileHighlights', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('asks the account it was given for', async () => {
		await mountHighlights(available())

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/accounts/42/highlights'))
	})

	it('says when the account has been here', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.find('.profile-highlights__since').text()).toContain('Here since')
	})

	it('draws one bar per week', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.findAll('.profile-highlights__bar')).toHaveLength(12)
	})

	// the chart is decoration over a sentence: a row of bars says nothing to
	// anybody not looking at it
	it('writes the chart out in words as well', async () => {
		const wrapper = await mountHighlights(available())

		expect(wrapper.find('.profile-highlights__summary').text()).toBe('21 posts in the last twelve weeks')
		expect(wrapper.find('.profile-highlights__bars').attributes('aria-hidden')).toBe('true')
	})

	it('draws the busiest week at full height', async () => {
		const wrapper = await mountHighlights(available({ weeks: [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 4] }))
		const bars = wrapper.findAll('.profile-highlights__bar')

		expect(bars[11].attributes('style')).toContain('100%')
	})

	// a quiet week next to a very busy one would otherwise round to nothing
	// and read as silence
	it('keeps a week with one post taller than an empty one', async () => {
		const wrapper = await mountHighlights(available({ weeks: [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 40] }))
		const bars = wrapper.findAll('.profile-highlights__bar')

		expect(bars[0].attributes('style')).toContain('12%')
		expect(bars[0].classes()).not.toContain('profile-highlights__bar--empty')
		expect(bars[1].classes()).toContain('profile-highlights__bar--empty')
	})

	it('links each hashtag to its timeline, in the tag colour', async () => {
		const wrapper = await mountHighlights(available())
		const tag = wrapper.find('.profile-highlights__tag')

		expect(tag.text()).toBe('#nextcloud')
		expect(tag.attributes('style')).toContain('--tag-colour')
	})

	/**
	 * The server says so for a remote account: this instance holds only the
	 * part of their history that happened to arrive, and a chart of that would
	 * show a quiet year for an account that was busy.
	 */
	it('renders nothing at all when the server cannot chart the account', async () => {
		const wrapper = await mountHighlights({ available: false, since: 0, weeks: [], hashtags: [] })

		expect(wrapper.find('.profile-highlights').exists()).toBe(false)
	})

	it('renders nothing when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(ProfileHighlights, {
			props: { accountId: '42' },
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(wrapper.find('.profile-highlights').exists()).toBe(false)
	})

	it('asks for nothing until it knows whose profile it is on', async () => {
		mount(ProfileHighlights, {
			props: { accountId: '' },
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('asks again when the profile changes', async () => {
		const wrapper = await mountHighlights(available())

		await wrapper.setProps({ accountId: '43' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenLastCalledWith(expect.stringContaining('/accounts/43/highlights'))
	})
})
