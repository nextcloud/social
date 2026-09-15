/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CollectionPickerDialog from '../../../src/components/CollectionPickerDialog.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="nc-dialog"><slot /></div>',
}

function mountDialog() {
	return mount(CollectionPickerDialog, {
		props: { open: true, status: { id: '101' } },
		global: { stubs: { NcDialog: NcDialogStub } },
	})
}

describe('CollectionPickerDialog', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('lists the reader\'s own collections and puts the post into the one chosen', async () => {
		get.mockResolvedValue({ data: [{ id: '3', title: 'Coast', size: 4 }, { id: '4', title: 'Nights', size: 1 }] })
		post.mockResolvedValue({ data: { id: '3', title: 'Coast', size: 5 } })

		const wrapper = mountDialog()
		await flushPromises()

		const rows = wrapper.findAll('.collection-picker__row')
		expect(rows).toHaveLength(2)
		await rows[0].find('button').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/collections/3/items'), { status_id: '101' })
		expect(wrapper.findAll('.collection-picker__row')[0].text()).toContain('Added')
		expect(wrapper.findAll('.collection-picker__row')[0].text()).toContain('5')
		expect(wrapper.emitted('added')).toHaveLength(1)
	})

	it('makes a new collection and puts the post straight into it', async () => {
		get.mockResolvedValue({ data: [] })
		post.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/items') ? { id: '9', size: 1 } : { id: '9', title: 'Mountains', size: 0 },
		}))

		const wrapper = mountDialog()
		await flushPromises()
		expect(wrapper.find('.collection-picker__hint').exists()).toBe(true)

		await wrapper.find('.collection-picker__create input').setValue('Mountains')
		await wrapper.find('.collection-picker__create').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenNthCalledWith(1, expect.stringContaining('/apps/social/api/v1/collections'), { title: 'Mountains', visibility: 'public' })
		expect(post).toHaveBeenNthCalledWith(2, expect.stringContaining('/apps/social/api/v1/collections/9/items'), { status_id: '101' })
		expect(wrapper.find('.collection-picker__row').text()).toContain('Added')
	})

	it('says so when the add was refused', async () => {
		get.mockResolvedValue({ data: [{ id: '3', title: 'Coast', size: 4 }] })
		post.mockRejectedValue({ response: { data: { error: 'a collection may only hold your own posts' } } })

		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.find('.collection-picker__row button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('a collection may only hold your own posts')
		expect(wrapper.find('.collection-picker__row').text()).not.toContain('Added')
	})
})
