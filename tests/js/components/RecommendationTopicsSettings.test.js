/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecommendationTopicsSettings from '../../../src/components/RecommendationTopicsSettings.vue'

const { get, put, post } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, put, post } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))

const catalog = {
	version: 1,
	topics: [
		{ id: 'technology', parentId: null, labels: { en: 'Technology', de: 'Technologie' } },
		{ id: 'tech.apple', parentId: 'technology', labels: { en: 'Apple', de: 'Apple' } },
	],
}
const state = { revision: 7, generation: 3, learning_mode: 'off', content_balance: 'balanced', onboarding_step: 5, onboarding_complete: true, topics: [{ topic_id: 'technology', mode: 'LESS' }] }
const PREFERENCES_URL = '/index.php/apps/social/api/v1/recommendations/preferences'
const RESET_URL = '/index.php/apps/social/api/v1/recommendations/reset'

function mountSettings() {
	return mount(RecommendationTopicsSettings)
}

describe('For You topic settings', () => {
	beforeEach(() => {
		get.mockReset().mockImplementation((url) => Promise.resolve({ data: url.includes('catalog') ? catalog : url.includes('/interests') ? { settings: { languages: [] } } : state }))
		put.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('loads the local catalog and the account-owned saved choices', async () => {
		const wrapper = mountSettings()
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(3)
		expect(wrapper.findAll('select')).toHaveLength(4)
		expect(wrapper.find('#recommendation-topic-technology').element.value).toBe('LESS')
		expect(wrapper.find('#recommendation-learning-mode').element.value).toBe('off')
		expect(wrapper.text()).toContain('Revision 7')
	})

	it('saves the whole preference set against the displayed revision', async () => {
		put.mockResolvedValue({ data: { ...state, revision: 8, generation: 4, topics: [{ topic_id: 'tech.apple', mode: 'PRIORITISE' }] } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.find('[id="recommendation-topic-tech.apple"]').setValue('PRIORITISE')
		await wrapper.findAllComponents({ name: 'NcButton' })[2].trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(PREFERENCES_URL, {
			if_revision: 7,
			topics: [
				{ topic_id: 'technology', mode: 'LESS' },
				{ topic_id: 'tech.apple', mode: 'PRIORITISE' },
			],
			content_balance: 'balanced',
			onboarding_step: 5,
			onboarding_complete: true,
		})
		expect(wrapper.text()).toContain('Revision 8')
	})

	it('resets topic choices and opens the resumable setup again', async () => {
		post.mockResolvedValue({ data: { revision: 8, generation: 4, content_balance: 'balanced', onboarding_step: 0, onboarding_complete: false, topics: [] } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.vm.reset()
		await flushPromises()

		expect(post).toHaveBeenCalledWith(RESET_URL, { if_revision: 7 })
		expect(wrapper.text()).toContain('Set up your For You feed')
	})

	it('saves progress and resumes at the next onboarding step', async () => {
		put.mockResolvedValue({ data: { ...state, revision: 8, onboarding_step: 2, onboarding_complete: false } })
		const wrapper = mount(RecommendationTopicsSettings)
		await flushPromises()
		await wrapper.setData({ onboardingComplete: false, onboardingStep: 0 })
		await wrapper.vm.advanceWizard()
		await flushPromises()

		expect(put).toHaveBeenCalledWith(PREFERENCES_URL, expect.objectContaining({
			if_revision: 7,
			onboarding_step: 2,
			onboarding_complete: false,
			content_balance: 'balanced',
		}))
		expect(wrapper.text()).toContain('Choose languages')
		expect(wrapper.text()).not.toContain('Refine with subtopics')
	})

	it('maps existing in-progress setup states past the removed subtopic step', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.setData({ onboardingComplete: false, onboardingStep: 1 })
		expect(wrapper.vm.wizardStep).toBe(1)
		expect(wrapper.text()).toContain('Choose languages')
		expect(wrapper.text()).not.toContain('Refine with subtopics')

		await wrapper.setData({ onboardingStep: 3 })
		expect(wrapper.vm.wizardStep).toBe(2)
		expect(wrapper.text()).toContain('Choose your content balance')

		await wrapper.setData({ onboardingStep: 4 })
		expect(wrapper.vm.wizardStep).toBe(3)
		expect(wrapper.text()).toContain('Review and start')
	})

	it('accepts additional language codes and saves the language step', async () => {
		put.mockResolvedValue({ data: { ...state, revision: 8, onboarding_step: 3, onboarding_complete: false } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.setData({ onboardingComplete: false, onboardingStep: 2 })
		await wrapper.find('#recommendation-language-code').setValue('fr-CA')
		await wrapper.vm.addLanguage()
		await wrapper.vm.advanceWizard()
		await flushPromises()

		expect(put).toHaveBeenCalledWith('/index.php/apps/social/api/v1/interests/settings', { languages: ['fr-CA'] })
		expect(put).toHaveBeenCalledWith(PREFERENCES_URL, expect.objectContaining({ onboarding_step: 3 }))
		expect(wrapper.vm.selectedLanguages).toEqual(['fr-CA'])
	})

	it('rejects malformed language codes and enforces the twenty-code limit', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.setData({ onboardingComplete: false, onboardingStep: 2 })
		await wrapper.find('#recommendation-language-code').setValue('english')
		await wrapper.vm.addLanguage()

		expect(wrapper.vm.selectedLanguages).toEqual([])
		expect(wrapper.text()).toContain('Enter a language code such as fr or pt-BR.')

		await wrapper.setData({ selectedLanguages: Array.from({ length: 20 }, (_, index) => `x${index}`) })
		await wrapper.setData({ languageInput: 'fr' })
		await wrapper.vm.addLanguage()

		expect(wrapper.vm.selectedLanguages).toHaveLength(20)
		expect(wrapper.vm.languageError).toContain('at most 20')
	})

	it('reloads the latest state after a revision conflict', async () => {
		put.mockRejectedValue({ response: { status: 409, data: { current: { ...state, revision: 8, generation: 4, topics: [] } } } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.find('#recommendation-topic-technology').setValue('HIDE')
		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.text()).toContain('Revision 8')
		expect(showError).toHaveBeenCalled()
	})

	it('requires an explicit save to turn behavioural learning on', async () => {
		put.mockResolvedValue({ data: { ...state, revision: 8, learning_mode: 'on' } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.find('#recommendation-learning-mode').setValue('on')
		await wrapper.findAllComponents({ name: 'NcButton' })[0].trigger('click')
		await flushPromises()

		expect(put).toHaveBeenCalledWith('/index.php/apps/social/api/v1/recommendations/learning', { if_revision: 7, mode: 'on' })
		expect(wrapper.find('#recommendation-learning-mode').element.value).toBe('on')
	})

	it('resets learned signals separately from explicit topic choices', async () => {
		post.mockResolvedValue({ data: { ...state, revision: 8, generation: 4 } })
		const wrapper = mountSettings()
		await flushPromises()
		await wrapper.vm.resetLearning()
		await flushPromises()

		expect(post).toHaveBeenCalledWith(RESET_URL, { if_revision: 7, scope: 'learning' })
		expect(wrapper.find('#recommendation-topic-technology').element.value).toBe('LESS')
	})
})
