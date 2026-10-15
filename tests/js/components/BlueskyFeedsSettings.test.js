/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { fetchSavedFeeds, fetchSuggestedFeeds, saveFeed } from '../../../src/services/blueskyFeeds.js'
import BlueskyFeedsSettings from '../../../src/components/BlueskyFeedsSettings.vue'

vi.mock('../../../src/services/blueskyFeeds.js', () => ({
	fetchSavedFeeds: vi.fn(),
	fetchSuggestedFeeds: vi.fn(),
	forgetFeed: vi.fn(),
	saveFeed: vi.fn(),
	routeFor: (uri) => ({ name: 'bluesky-feed', params: { uri } }),
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), error: vi.fn() } }))
vi.mock('../../../src/services/eventBus.js', () => ({ default: { emit: vi.fn() }, BLUESKY_FEEDS_CHANGED: 'bluesky-feeds-changed' }))

const kept = { uri: 'at://did:plc:a/app.bsky.feed.generator/kept', name: 'Kept', creator: 'alice.test', type: 'feed' }
const science = {
	uri: 'at://did:plc:b/app.bsky.feed.generator/science',
	name: 'Science',
	description: 'A long description of everything this feed collects, long enough to take most of the row on a narrow screen',
}
const art = { uri: 'at://did:plc:c/app.bsky.feed.generator/art', name: 'Art', description: '' }

/**
 * @param {object[]} feeds what the reader keeps
 * @param {object[]} suggestions what Bluesky suggests
 * @return {Promise<object>} the mounted section
 */
async function mountFeeds(feeds = [kept], suggestions = [science, art]) {
	fetchSavedFeeds.mockResolvedValue(feeds)
	fetchSuggestedFeeds.mockResolvedValue(suggestions)
	const wrapper = mount(BlueskyFeedsSettings, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
	await flushPromises()

	return wrapper
}

const suggestionRows = (wrapper) => wrapper.findAll('.bluesky-feeds__list')[1].findAll('.bluesky-feeds__item')

describe('BlueskyFeedsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/** A text label shares the row with the description and would be cut short on long ones. */
	it('adds a suggested feed with an icon button that names the feed, the same on every row', async () => {
		const wrapper = await mountFeeds()

		const buttons = suggestionRows(wrapper).map((row) => row.findComponent({ name: 'NcButton' }))
		expect(buttons.map((button) => button.attributes('aria-label'))).toEqual(['Add Science', 'Add Art'])
		expect(buttons.map((button) => button.attributes('title'))).toEqual(['Add Science', 'Add Art'])
		expect(buttons.map((button) => button.text())).toEqual(['', ''])
		expect(buttons.every((button) => button.classes('bluesky-feeds__action'))).toBe(true)
	})

	it('moves an added suggestion into the kept feeds', async () => {
		saveFeed.mockResolvedValue({ ...science, creator: 'bob.test', type: 'feed' })
		const wrapper = await mountFeeds()

		await suggestionRows(wrapper)[0].findComponent({ name: 'NcButton' }).trigger('click')
		await flushPromises()

		expect(saveFeed).toHaveBeenCalledWith(science.uri)
		expect(wrapper.findAll('.bluesky-feeds__list')[0].findAll('.bluesky-feeds__name').map((name) => name.text())).toEqual(['Science', 'Kept'])
		expect(suggestionRows(wrapper).map((row) => row.find('.bluesky-feeds__name').text())).toEqual(['Art'])
	})

	it('keeps the remove button of a kept feed from shrinking too', async () => {
		const wrapper = await mountFeeds()

		const remove = wrapper.findAll('.bluesky-feeds__list')[0].findComponent({ name: 'NcButton' })
		expect(remove.attributes('aria-label')).toBe('Stop keeping Kept')
		expect(remove.classes('bluesky-feeds__action')).toBe(true)
	})
})
