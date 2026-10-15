/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BlueskyFiguresSection from '../../../../src/components/admin/BlueskyFiguresSection.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

const NOW = 1760000000

function figures(overrides = {}) {
	return {
		enabled: true,
		computed: NOW,
		accounts: { identities: 12, off: 2 },
		posts: { day: 7, week: 40 },
		repositories: { count: 12, records: 5000, blob_bytes: 3 * 1048576 },
		reader: { watched: 31, jetstream: true, jetstream_lag: 7200 },
		firehose: { running: true, subscribers: 3, head_seq: 77, head_time: NOW - 300, events_kept: 1500 },
		...overrides,
	}
}

async function mounted(data) {
	get.mockResolvedValue({ data })
	const wrapper = mount(BlueskyFiguresSection)
	await flushPromises()

	return wrapper
}

describe('the Bluesky figures', () => {
	beforeEach(() => {
		get.mockReset()
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(NOW * 1000)
	})

	it('reads its own route', async () => {
		await mounted(figures())

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/admin/bluesky/figures'))
	})

	it('shows the posts, the accounts and the switched-off ones', async () => {
		const text = (await mounted(figures())).text()

		expect(text).toContain('posts in the last day')
		expect(text).toContain('40')
		expect(text).toContain('Accounts with a Bluesky identity')
		expect(text).toContain('2 switched off by their owners')
	})

	it('shows the repositories, with their media as a size', async () => {
		const text = (await mounted(figures())).text()

		expect(text).toContain('5,000')
		expect(text).toContain('3.0 MiB')
	})

	it('shows how far behind the Jetstream cursor is', async () => {
		const text = (await mounted(figures())).text()

		expect(text).toContain('31')
		expect(text).toContain('2 hours behind')
	})

	it('leaves the cursor out where no Jetstream is configured', async () => {
		const text = (await mounted(figures({ reader: { watched: 0, jetstream: false, jetstream_lag: null } }))).text()

		expect(text).not.toContain('Jetstream cursor')
	})

	it('shows the firehose subscribers and its latest event', async () => {
		const text = (await mounted(figures())).text()

		expect(text).toContain('Firehose subscribers')
		expect(text).toContain('Number 77')
		expect(text).toContain('1,500')
	})

	/** The count lives in the daemon; without it there is none to show. */
	it('says the daemon is not running rather than showing no subscribers', async () => {
		const wrapper = await mounted(figures({ firehose: { running: false, subscribers: null, head_seq: 0, head_time: 0, events_kept: 0 } }))

		expect(wrapper.text()).toContain('Not running')
		expect(wrapper.find('.bluesky-figures__down').exists()).toBe(true)
		expect(wrapper.text()).toContain('none yet')
	})

	/** Zeros would read as a server nobody uses; it is a server not on Bluesky. */
	it('says Bluesky is off instead of drawing zeros', async () => {
		const wrapper = await mounted({ enabled: false })

		expect(wrapper.text()).toContain('Bluesky is switched off on this server')
		expect(wrapper.find('.bluesky-figures').exists()).toBe(false)
	})

	it('says so when the figures cannot be read', async () => {
		get.mockRejectedValue(new Error('nope'))
		const wrapper = mount(BlueskyFiguresSection)
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Could not read the Bluesky figures')
	})
})
