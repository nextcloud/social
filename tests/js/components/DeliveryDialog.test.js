/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import DeliveryDialog from '../../../src/components/DeliveryDialog.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const ROUTE = '/index.php/apps/social/api/v1/statuses/7/delivery'

const NcDialog = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: '<div class="dialog"><slot /></div>',
}

/**
 * @param {object} overrides what the queue says about the post
 * @return {object} the queue's answer
 */
function record(overrides = {}) {
	return {
		total: 0,
		delivered: 0,
		sending: 0,
		waiting: 0,
		failing: 0,
		abandoned: 0,
		retention: 7 * 86400,
		instances: [],
		...overrides,
	}
}

/**
 * @param {object|Error} answer what the queue answers with, or throws
 * @param {boolean} open whether the dialog starts open
 * @return {Promise<object>} the mounted dialog, once the read has settled
 */
async function mountDialog(answer = record(), open = true) {
	axios.get.mockImplementation(() => (answer instanceof Error
		? Promise.reject(answer)
		: Promise.resolve({ data: answer })))

	const wrapper = mount(DeliveryDialog, {
		props: { open, statusId: '7' },
		global: { stubs: { NcDialog } },
	})
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.delivery-list__row')

describe('where a post got to', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/** Opened from the author's own menu, so it asks nothing until it opens. */
	it('asks the queue nothing while it is closed', async () => {
		await mountDialog(record(), false)

		expect(axios.get).not.toHaveBeenCalled()
	})

	/** A delivery that was failing a minute ago may have gone through since. */
	it('asks again every time it is opened', async () => {
		const wrapper = await mountDialog(record(), false)

		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(ROUTE)

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('says it is asking while it asks', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(DeliveryDialog, {
			props: { open: true, statusId: '7' },
			global: { stubs: { NcDialog } },
		})
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.delivery-hint').text()).toContain('Asking the delivery queue')
	})

	describe('the line at the top', () => {
		/** The author reads this and, most of the time, needs nothing under it. */
		it('counts each state it found, and leaves out the ones at zero', async () => {
			const wrapper = await mountDialog(record({
				total: 5,
				delivered: 2,
				waiting: 1,
				abandoned: 2,
			}))

			expect(wrapper.find('.delivery-hint').text())
				.toBe('Of 5 deliveries: delivered to 2 servers, waiting for 1 server, given up on 2 servers.')
		})

		it('says nothing at all when there were no deliveries', async () => {
			expect((await mountDialog(record())).find('.delivery-hint').text()).toBe('')
		})
	})

	describe('the list under it', () => {
		it('names each server and what happened to it', async () => {
			const wrapper = await mountDialog(record({
				total: 2,
				delivered: 1,
				failing: 1,
				instances: [
					{ host: 'fine.example', state: 'delivered', tries: 1, last: 1 },
					{ host: 'slow.example', state: 'failing', tries: 3, last: 2 },
				],
			}))

			expect(rows(wrapper).map((row) => row.find('.delivery-list__host').text()))
				.toEqual(['fine.example', 'slow.example'])
			expect(rows(wrapper).map((row) => row.find('.delivery-list__state').text()))
				.toEqual(['Delivered', 'Failing (3 attempts)'])
		})

		/** The dot carries the state as well as the word, so the list reads at a glance. */
		it('marks each row with the state it is in', async () => {
			const states = ['delivered', 'sending', 'waiting', 'failing', 'abandoned']
			const wrapper = await mountDialog(record({
				total: 5,
				instances: states.map((state, index) => ({ host: `${state}.example`, state, tries: index, last: index })),
			}))

			expect(rows(wrapper).map((row) => row.classes().find((c) => c.includes('--'))))
				.toEqual(states.map((state) => `delivery-list__row--${state}`))
			expect(wrapper.findAll('.delivery-list__dot')
				.every((dot) => dot.attributes('aria-hidden') === 'true')).toBe(true)
		})

		/** A state this app has no wording for is still worth showing as it is. */
		it('shows a state it does not recognise rather than nothing', async () => {
			const wrapper = await mountDialog(record({
				total: 1,
				instances: [{ host: 'odd.example', state: 'pondering', tries: 0, last: 0 }],
			}))

			expect(rows(wrapper)[0].find('.delivery-list__state').text()).toBe('pondering')
		})
	})

	describe('when there is nothing on record', () => {
		/** The two reasons for an empty list, both of which mean nothing is wrong. */
		it('explains that a post may be too old, or may never have left', async () => {
			const hint = (await mountDialog(record())).find('.delivery-hint--muted').text()

			expect(hint).toContain('kept for 7 days')
			expect(hint).toContain('never left this server')
		})

		it('says how long the queue on this server actually keeps them', async () => {
			expect((await mountDialog(record({ retention: 30 * 86400 })))
				.find('.delivery-hint--muted').text()).toContain('kept for 30 days')
		})
	})

	it('says so when the queue could not be reached', async () => {
		const wrapper = await mountDialog(new Error('offline'))

		expect(wrapper.find('.delivery-hint--error').text())
			.toBe('Could not read the delivery status of this post.')
		expect(wrapper.find('.delivery-list').exists()).toBe(false)
	})

	/** Nothing to act on, so there is one button and it closes. */
	it('closes when it is dismissed', async () => {
		const wrapper = await mountDialog()
		const [close] = wrapper.findComponent(NcDialog).props('buttons')

		expect(close.label).toBe('Close')
		close.callback()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
