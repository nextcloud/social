/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import AccessSection from '../../../../src/components/admin/AccessSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const FEDIVERSE = '/index.php/apps/social/moderation/fediverse'

/**
 * @param {object} props the list and which way round it is read
 * @return {object} the mounted section
 */
function mountAccess(props = {}) {
	return mount(AccessSection, {
		props: { accessType: 'all_but', addresses: ['noisy.example'], ...props },
	})
}

describe('the fediverse access section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows the list the server is keeping', () => {
		expect(mountAccess().text()).toContain('noisy.example')
	})

	it('says so when nothing is on the list', () => {
		expect(mountAccess({ addresses: [] }).text()).toContain('No instance is on the list.')
	})

	it('starts on the mode the instance is in', () => {
		expect(mountAccess({ accessType: 'none_but' }).vm.mode.id).toBe('none_but')
	})

	it('switches between an allow list and a block list', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountAccess()

		await wrapper.vm.saveMode({ id: 'none_but' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/access', { type: 'none_but' })
	})

	it('adds an instance and redraws from what the route answered', async () => {
		axios.post.mockResolvedValue({ data: { list: ['noisy.example', 'worse.example'] } })
		const wrapper = mountAccess()

		wrapper.vm.address = '  worse.example '
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/add', { address: 'worse.example' })
		expect(wrapper.text()).toContain('worse.example')
		expect(wrapper.vm.address).toBe('')
	})

	it('adds nothing at all for an empty field', async () => {
		const wrapper = mountAccess()

		wrapper.vm.address = '   '
		await wrapper.vm.add()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('removes an instance from the button in its row', async () => {
		axios.post.mockResolvedValue({ data: { list: [] } })
		const wrapper = mountAccess()

		await wrapper.find('.access__item button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(FEDIVERSE + '/remove', { address: 'noisy.example' })
		expect(wrapper.text()).toContain('No instance is on the list.')
	})
})
