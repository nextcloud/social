/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import AccountsSection, { PAGE } from '../../../../src/components/admin/AccountsSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const ACCOUNTS = '/index.php/apps/social/moderation/accounts'

/**
 * One account, as /moderation/accounts sends it.
 *
 * @param {object} overrides what this one differs in
 * @return {object} the row
 */
function account(overrides = {}) {
	return {
		actor_id: 'https://noisy.example/users/bob',
		handle: 'bob@noisy.example',
		username: 'bob',
		domain: 'noisy.example',
		local: false,
		level: '',
		strikes: 0,
		...overrides,
	}
}

/**
 * @param {object[]} accounts what the route answers with
 * @param {number[]} cursors what it pages by
 * @return {Promise<object>} the mounted section
 */
async function mountAccounts(accounts = [account()], cursors = [1]) {
	axios.get.mockResolvedValue({ data: { accounts, cursors } })
	const wrapper = mount(AccountsSection)
	await flushPromises()

	return wrapper
}

describe('the account browser', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('reads the accounts as soon as the page is open', async () => {
		const wrapper = await mountAccounts()

		expect(axios.get).toHaveBeenCalledWith(ACCOUNTS, {
			params: { query: '', origin: '', status: '' },
		})
		expect(wrapper.text()).toContain('bob@noisy.example')
		expect(wrapper.text()).toContain('noisy.example')
	})

	it('searches by what was typed and by both filters', async () => {
		const wrapper = await mountAccounts()

		wrapper.vm.query = '  bob@noisy.example '
		wrapper.vm.origin = { id: 'remote', label: 'Other instances' }
		wrapper.vm.status = { id: 'silenced', label: 'Silenced' }
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(ACCOUNTS, {
			params: { query: 'bob@noisy.example', origin: 'remote', status: 'silenced' },
		})
	})

	it('says so rather than showing an empty table', async () => {
		const wrapper = await mountAccounts([], [])

		expect(wrapper.text()).toContain('No account matches that.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('offers more only when the page it got was a full one', async () => {
		expect((await mountAccounts([account()])).vm.hasMore).toBe(false)

		const full = Array.from({ length: PAGE }, (entry, index) => account({
			actor_id: 'https://noisy.example/users/bob' + index,
		}))
		expect((await mountAccounts(full, [PAGE])).vm.hasMore).toBe(true)
	})

	it('continues from the last cursor rather than starting again', async () => {
		const wrapper = await mountAccounts([account()], [7, 9])

		await wrapper.vm.search(true)
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(ACCOUNTS, {
			params: { query: '', origin: '', status: '', maxId: '9' },
		})
		// appended, not redrawn: "show more" should not move the page under a
		// moderator's cursor
		expect(wrapper.vm.accounts).toHaveLength(2)
	})

	// The cursor belongs to the search that produced it. Typing in the form
	// without pressing Search must not send the next page of that search off
	// to a different one, and must not append the answer to rows it has
	// nothing to do with.
	it('continues the search the rows came from, not the one the form now says', async () => {
		const wrapper = await mountAccounts([account()], [7, 9])

		wrapper.vm.query = 'someone else'
		wrapper.vm.origin = { id: 'local', label: 'This instance' }
		wrapper.vm.status = { id: 'suspended', label: 'Suspended' }
		await wrapper.vm.search(true)
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(ACCOUNTS, {
			params: { query: '', origin: '', status: '', maxId: '9' },
		})
	})

	it('pages the new search once it has actually been run', async () => {
		const wrapper = await mountAccounts([account()], [7, 9])

		wrapper.vm.query = 'someone else'
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		await wrapper.vm.search(true)
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(ACCOUNTS, {
			params: { query: 'someone else', origin: '', status: '', maxId: '9' },
		})
	})

	// Two replacement searches can settle in whatever order the server felt
	// like; the older one must not draw itself over the newer.
	it('keeps the newest search on screen when an older one settles after it', async () => {
		const wrapper = await mountAccounts([account({ handle: 'first@noisy.example' })], [1])

		let releaseOld
		axios.get.mockImplementationOnce(() => new Promise((resolve) => {
			releaseOld = () => resolve({ data: { accounts: [account({ handle: 'old@noisy.example' })], cursors: [2] } })
		}))
		wrapper.vm.query = 'old'
		await wrapper.find('form').trigger('submit')

		axios.get.mockResolvedValueOnce({ data: { accounts: [account({ handle: 'new@noisy.example' })], cursors: [3] } })
		wrapper.vm.query = 'new'
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		releaseOld()
		await flushPromises()

		expect(wrapper.vm.accounts.map((entry) => entry.handle)).toEqual(['new@noisy.example'])
		expect(wrapper.vm.cursor).toBe(3)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('opens one account history under its row, and closes it again', async () => {
		const wrapper = await mountAccounts([account({ strikes: 2 })])
		axios.get.mockResolvedValue({
			data: {
				strikes: [{
					action: 'silence',
					text: 'spam',
					moderator: 'alice',
					creation: 1_700_000_000,
				}],
			},
		})

		await wrapper.find('tbody button').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(ACCOUNTS + '/history', {
			params: { actorId: 'https://noisy.example/users/bob' },
		})
		expect(wrapper.text()).toContain('2023-11-14 — Silenced — alice: spam')

		await wrapper.find('tbody button').trigger('click')
		await flushPromises()

		expect(wrapper.text()).not.toContain('2023-11-14 — Silenced — alice: spam')
	})

	it('shows a strike count of none as a number and not as a button', async () => {
		const wrapper = await mountAccounts([account({ strikes: 0 })])

		expect(wrapper.text()).toContain('None')
	})

	it('silences an account without asking first', async () => {
		const wrapper = await mountAccounts()

		await wrapper.vm.askModerate(wrapper.vm.accounts[0], 'silence')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ACCOUNTS, {
			actorId: 'https://noisy.example/users/bob',
			level: 'silence',
			comment: '',
		})
		expect(wrapper.text()).toContain('Silenced')
	})

	it('asks before suspending, because suspending deletes', async () => {
		const wrapper = await mountAccounts()

		wrapper.vm.askModerate(wrapper.vm.accounts[0], 'suspend')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.pending.message)
			.toContain('Suspending deletes every post this account has here')

		await wrapper.vm.moderate(wrapper.vm.pending.account, wrapper.vm.pending.level)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ACCOUNTS, {
			actorId: 'https://noisy.example/users/bob',
			level: 'suspend',
			comment: '',
		})
	})

	it('offers no decision that already stands', async () => {
		const wrapper = await mountAccounts([account({ level: 'silence' })])
		const decisions = wrapper.findAll('tbody tr:first-child td:last-child button')

		expect(decisions.map((button) => button.attributes('disabled') !== undefined))
			.toEqual([true, false, false])
	})

	it('offers no lift when nothing stands', async () => {
		const wrapper = await mountAccounts([account({ level: '' })])
		const decisions = wrapper.findAll('tbody tr:first-child td:last-child button')

		expect(decisions.at(2).attributes('disabled')).toBeDefined()
	})
})
