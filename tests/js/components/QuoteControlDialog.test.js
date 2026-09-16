/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import QuoteControlDialog from '../../../src/components/QuoteControlDialog.vue'

const { get, post, put } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, put } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['name', 'open', 'size'],
	template: '<div class="dialog-stub"><slot /></div>',
}

function mountDialog(approval = { automatic: ['public'], manual: [], current_user: 'automatic' }) {
	return mount(QuoteControlDialog, {
		props: { nid: 7, approval },
		global: { stubs: { NcDialog: NcDialogStub } },
	})
}

function quote(overrides = {}) {
	return {
		id: '99',
		nid: 9,
		account: { acct: 'carol@remote.example' },
		content: '<p>look at this</p>',
		...overrides,
	}
}

const rows = (wrapper) => wrapper.findAll('.quotes__item')
function buttonByText(scope, text) {
	return scope.findAll('button').find((button) => button.text() === text)
}

describe('the quote controls', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
		post.mockReset().mockResolvedValue({ data: {} })
		put.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('reads who has quoted the post', async () => {
		mountDialog()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/statuses/7/quotes'))
	})

	it('starts on the policy the post carries', async () => {
		const wrapper = mountDialog({ automatic: ['followers'], manual: [], current_user: 'denied' })
		await flushPromises()

		expect(wrapper.vm.policy).toBe('followers')
	})

	/**
	 * A quote somebody has already posted and other people have read is not
	 * undone by a switch being flipped, and the dialog says so before it is.
	 */
	it('says the policy does not take back what is already posted', () => {
		const wrapper = mountDialog()

		expect(wrapper.text()).toContain('does not take back a quote somebody has already posted')
	})

	it('writes a new policy through the interaction_policy route', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.setPolicy('nobody')

		expect(put).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/statuses/7/interaction_policy'),
			{ quote_approval_policy: 'nobody' },
		)
	})

	/** A radio showing a choice that was not saved is worse than one that never moved. */
	it('puts the choice back when the server refuses', async () => {
		put.mockRejectedValue(new Error('nope'))
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.setPolicy('nobody')

		expect(wrapper.vm.policy).toBe('public')
		expect(showError).toHaveBeenCalled()
	})

	it('says so when nobody has quoted the post', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.text()).toContain('Nobody has quoted this post.')
	})

	it('lists who has quoted it, with the first words', async () => {
		get.mockResolvedValue({ data: [quote()] })

		const wrapper = mountDialog()
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(wrapper.text()).toContain('carol@remote.example')
		expect(wrapper.text()).toContain('look at this')
	})

	it('detaches one quote and takes the row away', async () => {
		get.mockResolvedValue({ data: [quote()] })

		const wrapper = mountDialog()
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Detach this quote').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/statuses/7/quotes/9/revoke'))
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('keeps the row when detaching fails', async () => {
		get.mockResolvedValue({ data: [quote()] })
		post.mockRejectedValue(new Error('nope'))

		const wrapper = mountDialog()
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Detach this quote').trigger('click')
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(showError).toHaveBeenCalled()
	})

	it('survives an answer that is not a list', async () => {
		get.mockResolvedValue({ data: { error: 'nope' } })

		const wrapper = mountDialog()
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(0)
	})
})
