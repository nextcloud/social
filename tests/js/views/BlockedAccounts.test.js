/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import BlockedAccounts from '../../../src/views/BlockedAccounts.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', avatar: 'https://remote.tld/bob.png' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', avatar: 'https://remote.tld/carol.png' }
const dave = { id: '44', acct: 'dave', username: 'dave', display_name: '', avatar: 'https://cloud.example.org/dave.png' }

/** Answers /blocks and /mutes in the order the view requests them. */
const serve = (blocked, muted) => {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/blocks')) return Promise.resolve({ data: blocked })
		if (url.endsWith('/mutes')) return Promise.resolve({ data: muted })
		return Promise.reject(new Error(`unexpected ${url}`))
	})
}

const mountView = async ({ blocked = [bob], muted = [carol], dispatch } = {}) => {
	serve(blocked, muted)
	const pinia = createPinia()
	setActivePinia(pinia)
	const accountStore = useAccountStore()
	const act = dispatch ?? vi.fn().mockResolvedValue({ id: '22' })
	vi.spyOn(accountStore, 'unblockAccount').mockImplementation(act)
	vi.spyOn(accountStore, 'unmuteAccount').mockImplementation(act)
	const wrapper = mount(BlockedAccounts, {
		global: {
			plugins: [pinia],
			stubs: {
				NcAvatar: true,
				NcEmptyContent: { props: ['name'], template: '<div class="empty-content">{{ name }}</div>' },
				RouterLink: RouterLinkStub,
			},
		},
	})
	await flushPromises()

	return { wrapper, accountStore }
}

const rows = (wrapper) => wrapper.findAll('.blocked-account')
const rowNames = (wrapper) => rows(wrapper).map((row) => row.find('.blocked-account__name').text())
const buttonByText = (root, text) => root.findAll('button').find((button) => button.text().includes(text))

describe('BlockedAccounts', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('loads both lists from the endpoints that already exist', async () => {
		const { wrapper } = await mountView()

		expect(axios.get).toHaveBeenCalledWith(`${API}/blocks`)
		expect(axios.get).toHaveBeenCalledWith(`${API}/mutes`)
		expect(rowNames(wrapper)).toEqual(['Bob', 'Carol'])
	})

	it('falls back to the username when an account has no display name', async () => {
		const { wrapper } = await mountView({ blocked: [dave], muted: [] })

		expect(rowNames(wrapper)).toEqual(['dave'])
	})

	it('links each account to its profile', async () => {
		const { wrapper } = await mountView({ blocked: [bob], muted: [] })

		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
			name: 'profile',
			params: { account: 'bob@remote.tld' },
		})
	})

	it('shows an empty state per list', async () => {
		const { wrapper } = await mountView({ blocked: [], muted: [] })
		const empty = wrapper.findAll('.empty-content').map((el) => el.text())

		expect(empty).toEqual(['No blocked accounts', 'No muted accounts'])
	})

	it('shows the muted empty state while blocked accounts exist', async () => {
		const { wrapper } = await mountView({ blocked: [bob], muted: [] })

		expect(wrapper.findAll('.empty-content').map((el) => el.text())).toEqual(['No muted accounts'])
		expect(rowNames(wrapper)).toEqual(['Bob'])
	})

	it('unblocks through the store and takes the row off the list', async () => {
		const dispatch = vi.fn().mockResolvedValue({ id: '22' })
		const { wrapper, accountStore } = await mountView({ blocked: [bob], muted: [], dispatch })

		await buttonByText(wrapper, 'Unblock').trigger('click')
		await flushPromises()

		// the store action is what keeps the profile page's relationship in step
		expect(accountStore.unblockAccount).toHaveBeenCalledWith({ id: '22' })
		expect(rowNames(wrapper)).toEqual([])
	})

	it('unmutes through the store and takes the row off the list', async () => {
		const dispatch = vi.fn().mockResolvedValue({ id: '33' })
		const { wrapper, accountStore } = await mountView({ blocked: [], muted: [carol], dispatch })

		await buttonByText(wrapper, 'Unmute').trigger('click')
		await flushPromises()

		expect(accountStore.unmuteAccount).toHaveBeenCalledWith({ id: '33' })
		expect(rowNames(wrapper)).toEqual([])
	})

	it('keeps the row when the server refuses, so nothing claims a success', async () => {
		// the store action reports its own error and resolves undefined
		const dispatch = vi.fn().mockResolvedValue(undefined)
		const { wrapper } = await mountView({ blocked: [bob], muted: [], dispatch })

		await buttonByText(wrapper, 'Unblock').trigger('click')
		await flushPromises()

		expect(rowNames(wrapper)).toEqual(['Bob'])
		expect(buttonByText(wrapper, 'Unblock').attributes('disabled')).toBeUndefined()
	})

	it('reports a failure to load and stops the spinner', async () => {
		axios.get.mockRejectedValue(new Error('boom'))
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(BlockedAccounts, {
			global: {
				plugins: [pinia],
				stubs: {
					NcAvatar: true,
					NcEmptyContent: { props: ['name'], template: '<div class="empty-content">{{ name }}</div>' },
					RouterLink: RouterLinkStub,
				},
			},
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to load the blocked and muted accounts')
		expect(wrapper.find('.loading-indicator').exists()).toBe(false)
	})

	it('survives an answer that is not a list', async () => {
		const { wrapper } = await mountView({ blocked: null, muted: { error: 'not a list' } })

		expect(rows(wrapper)).toHaveLength(0)
		expect(showError).not.toHaveBeenCalled()
	})

	describe('taking an account off a list', () => {
		it('renders both lists through a transition group, so a row can collapse out of it', async () => {
			// the rows were plain siblings: an unblocked account blinked away
			// and the ones below jumped into the space it left
			const { wrapper } = await mountView({ blocked: [bob, dave], muted: [carol] })
			const groups = wrapper.findAll('transition-group-stub')

			expect(groups).toHaveLength(2)
			expect(groups.map((group) => group.attributes('name'))).toEqual(['collapse', 'collapse'])
			expect(groups[0].findAll('.blocked-account')).toHaveLength(2)
			expect(groups[1].findAll('.blocked-account')).toHaveLength(1)
		})

		it('keys the rows on the account, so the rows that stay are the very same rows', async () => {
			// keyed on the index, Vue answers a removal by patching Bob's row
			// into Dave and dropping the last one: the wrong row collapses and
			// Dave's row is rebuilt underneath the reader
			const { wrapper } = await mountView({ blocked: [bob, dave], muted: [] })
			const before = rows(wrapper).map((row) => row.element)

			await buttonByText(rows(wrapper)[0], 'Unblock').trigger('click')
			await flushPromises()

			const after = rows(wrapper).map((row) => row.element)
			expect(after).toHaveLength(1)
			expect(after[0]).toBe(before[1])
			expect(rowNames(wrapper)).toEqual(['dave'])
		})

		it('brings the empty state in through a transition once the last one is gone', async () => {
			const { wrapper } = await mountView({ blocked: [bob], muted: [carol] })
			expect(wrapper.findAll('.empty-content')).toHaveLength(0)

			await buttonByText(rows(wrapper)[0], 'Unblock').trigger('click')
			await flushPromises()

			const empty = wrapper.findAll('transition-stub')
			expect(empty).toHaveLength(2)
			expect(empty[0].attributes('name')).toBe('empty')
			expect(empty[0].find('.empty-content').text()).toBe('No blocked accounts')
			// the muted list still has Carol, so its empty state stays away
			expect(empty[1].find('.empty-content').exists()).toBe(false)
		})
	})
})
