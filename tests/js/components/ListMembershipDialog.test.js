/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import ListMembershipDialog from '../../../src/components/ListMembershipDialog.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ACCOUNT = { id: '42', acct: 'jens@chaos.social' }
const LISTS = '/index.php/apps/social/api/v1/lists'

const NcDialog = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: '<div><slot /></div>',
}

/**
 * @param {object} answers what the two reads answer with
 * @param {Array} [answers.lists] every list the reader has
 * @param {Array} [answers.memberOf] the ones this account is already in
 * @param {boolean} [answers.open] whether the dialog starts open
 * @return {Promise<object>} the mounted dialog, once both reads have settled
 */
async function mountDialog({ lists = [], memberOf = [], open = true } = {}) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/lists') && url.includes('/accounts/') ? memberOf : lists,
	}))

	const wrapper = mount(ListMembershipDialog, {
		props: { open, account: ACCOUNT },
		global: { stubs: { NcDialog } },
	})
	await flushPromises()

	return wrapper
}

const boxes = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })

describe('putting somebody in a list', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
		axios.delete.mockResolvedValue({ data: {} })
	})

	it('names the account it is about', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.findComponent(NcDialog).props('name'))
			.toBe('Lists with @jens@chaos.social')
	})

	it('falls back to the username for an account with no handle', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const wrapper = mount(ListMembershipDialog, {
			props: { open: true, account: { id: '1', username: 'jens' } },
			global: { stubs: { NcDialog } },
		})
		await flushPromises()

		expect(wrapper.findComponent(NcDialog).props('name')).toBe('Lists with @jens')
	})

	it('offers a box per list, ticked where the account is already in one', async () => {
		const wrapper = await mountDialog({
			lists: [{ id: 1, title: 'Birds' }, { id: 2, title: 'Berlin' }],
			memberOf: [{ id: 2 }],
		})

		expect(boxes(wrapper).map((box) => box.text())).toEqual(['Birds', 'Berlin'])
		expect(boxes(wrapper).map((box) => box.props('modelValue'))).toEqual([false, true])
	})

	/**
	 * A group list's members are the group's. Offering a box the server would
	 * refuse is worse than offering no box.
	 */
	it('leaves out the lists a Nextcloud group fills', async () => {
		const wrapper = await mountDialog({
			lists: [{ id: 1, title: 'Birds' }, { id: 2, title: 'Marketing', nextcloud_group: 'marketing' }],
		})

		expect(boxes(wrapper).map((box) => box.text())).toEqual(['Birds'])
		expect(wrapper.text()).toContain('Lists your Nextcloud groups give you are not here')
	})

	it('sends somebody to make a list when they have none', async () => {
		const wrapper = await mountDialog({ lists: [] })

		expect(wrapper.find('.list-membership__hint').text())
			.toContain('You have no lists yet')
		expect(boxes(wrapper)).toHaveLength(0)
	})

	/** Opened from a profile, so it reads nothing until it is actually opened. */
	it('asks for nothing while it is closed', async () => {
		await mountDialog({ open: false })

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the lists when it is opened', async () => {
		const wrapper = await mountDialog({ open: false })

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(LISTS)
		expect(axios.get).toHaveBeenCalledWith(`${LISTS.replace('/lists', '')}/accounts/42/lists`)
	})

	describe('ticking a box', () => {
		/** Nothing to save: the tick is the write, and the one button closes. */
		it('writes straight away', async () => {
			const wrapper = await mountDialog({ lists: [{ id: 7, title: 'Birds' }] })

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(`${LISTS}/7/accounts`, { account_ids: ['42'] })
			expect(boxes(wrapper)[0].props('modelValue')).toBe(true)
		})

		it('takes them out again when it is unticked', async () => {
			const wrapper = await mountDialog({
				lists: [{ id: 7, title: 'Birds' }],
				memberOf: [{ id: 7 }],
			})

			boxes(wrapper)[0].vm.$emit('update:modelValue', false)
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${LISTS}/7/accounts`, {
				params: { account_ids: ['42'] },
			})
			expect(boxes(wrapper)[0].props('modelValue')).toBe(false)
		})

		/** A double click must not send the same write twice. */
		it('cannot be pressed again while its write is in flight', async () => {
			const wrapper = await mountDialog({ lists: [{ id: 7, title: 'Birds' }] })
			axios.post.mockReturnValue(new Promise(() => {}))

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await wrapper.vm.$nextTick()
			expect(boxes(wrapper)[0].props('disabled')).toBe(true)

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await flushPromises()
			expect(axios.post).toHaveBeenCalledTimes(1)
		})

		/** A box that stayed ticked after a write that failed is a lie. */
		it('stays where it was when the write fails', async () => {
			const wrapper = await mountDialog({ lists: [{ id: 7, title: 'Birds' }] })
			axios.post.mockRejectedValue({ response: { status: 500, data: {} } })

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(boxes(wrapper)[0].props('modelValue')).toBe(false)
			expect(boxes(wrapper)[0].props('disabled')).toBe(false)
			expect(showError).toHaveBeenCalledWith('Could not change the list')
		})

		/**
		 * A list holds people you follow, and the server answers 404 for anyone
		 * else — which on its own reads as "that list is gone".
		 */
		it('explains the one refusal that needs explaining', async () => {
			const wrapper = await mountDialog({ lists: [{ id: 7, title: 'Birds' }] })
			axios.post.mockRejectedValue({ response: { status: 404, data: {} } })

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('You can only add people you follow to a list')
		})

		it('passes on what the server said went wrong', async () => {
			const wrapper = await mountDialog({ lists: [{ id: 7, title: 'Birds' }] })
			axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'That list is full' } } })

			boxes(wrapper)[0].vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('That list is full')
		})
	})

	it('says so when the lists could not be read at all', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(ListMembershipDialog, {
			props: { open: true, account: ACCOUNT },
			global: { stubs: { NcDialog } },
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load your lists')
		expect(wrapper.find('.list-membership__hint').text()).toContain('You have no lists yet')
	})

	it('closes when Done is pressed', async () => {
		const wrapper = await mountDialog()

		wrapper.findComponent(NcDialog).props('buttons')[0].callback()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
