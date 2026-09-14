/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import RetentionSection from '../../../../src/components/admin/RetentionSection.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const RETENTION = '/index.php/apps/social/moderation/retention'

describe('the retention section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('shows the days the server is keeping remote posts for', () => {
		const wrapper = mount(RetentionSection, { props: { days: 90 } })

		expect(wrapper.find('input[type="number"]').element.value).toBe('90')
	})

	it('sends the day count to the moderation route', async () => {
		const wrapper = mount(RetentionSection, { props: { days: 90 } })

		await wrapper.find('input[type="number"]').setValue('30')
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(RETENTION, { days: 30 })
	})

	it('sends nothing at all for something that is not a day count', async () => {
		const wrapper = mount(RetentionSection, { props: { days: 90 } })

		await wrapper.find('input[type="number"]').setValue('-1')
		await wrapper.vm.save()
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})
})
