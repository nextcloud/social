/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ActivityLearningSettings from '../../../src/components/ActivityLearningSettings.vue'

const { get, put, post } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put, post } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))

const state = { revision: 7, generation: 3, learning_mode: 'off' }
const PREFERENCES_URL = '/index.php/apps/social/api/v1/recommendations/preferences'
const LEARNING_URL = '/index.php/apps/social/api/v1/recommendations/learning'
const RESET_URL = '/index.php/apps/social/api/v1/recommendations/reset'

function mountSettings() {
	return mount(ActivityLearningSettings)
}

describe('For You activity learning controls', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: state })
		put.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('loads only activity settings and contains no topic selection', async () => {
		const wrapper = mountSettings()
		await flushPromises()

		expect(get).toHaveBeenCalledExactlyOnceWith(PREFERENCES_URL)
		expect(wrapper.find('#activity-learning-mode').element.value).toBe('off')
		expect(wrapper.text()).toContain('Activity learning')
		expect(wrapper.text()).not.toContain('Choose topics')
		expect(wrapper.text()).not.toContain('Topic choices')
	})

	it('saves the separate activity learning consent', async () => {
		put.mockResolvedValue({ data: { ...state, revision: 8, learning_mode: 'on' } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.find('#activity-learning-mode').setValue('on')
		await wrapper.vm.saveLearningMode()
		await flushPromises()

		expect(put).toHaveBeenCalledExactlyOnceWith(LEARNING_URL, { if_revision: 7, mode: 'on' })
		expect(wrapper.find('#activity-learning-mode').element.value).toBe('on')
	})

	it('resets only the optional activity profile', async () => {
		post.mockResolvedValue({ data: { ...state, revision: 8, generation: 4 } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.vm.resetLearning()
		await flushPromises()

		expect(post).toHaveBeenCalledExactlyOnceWith(RESET_URL, { if_revision: 7, scope: 'learning' })
		expect(wrapper.text()).not.toContain('Reset topic choices')
	})
})
