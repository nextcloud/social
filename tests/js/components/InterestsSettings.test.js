/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InterestsSettings from '../../../src/components/InterestsSettings.vue'
import {
	addInterest,
	fetchInterests,
	resetInterests,
	saveInterestSettings,
} from '../../../src/services/interests.js'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/interests.js', () => ({
	addInterest: vi.fn(),
	fetchInterests: vi.fn(),
	moveInterest: vi.fn(),
	pinInterest: vi.fn(),
	removeInterest: vi.fn(),
	resetInterests: vi.fn(),
	saveInterestSettings: vi.fn(),
	unpinInterest: vi.fn(),
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showUndo: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
// the search is debounced; here it asks at once
vi.mock('debounce', () => ({ default: (fn) => fn }))

/**
 * @param {object} [overrides] parts of the state to change
 * @return {object} the state, as every interests endpoint answers it
 */
function state(overrides = {}) {
	return {
		settings: { enabled: true, learning: true, paused: false, languages: [], noticeAcknowledged: true, ...(overrides.settings ?? {}) },
		interests: [
			{ tag: 'photography', rank: 0, source: 'learned', pinned: false, score: 12.3, trend: 'up' },
			{ tag: 'analog', rank: 1, source: 'manual', pinned: true, score: 3, trend: null },
			{ tag: 'nextcloud', rank: 2, source: 'followed', pinned: false, score: 3, trend: null },
		],
		candidates: [{ tag: 'film', score: 2.1 }, { tag: 'darkroom', score: 1.8 }],
		thin: false,
		cap: 30,
		...overrides,
		...(overrides.settings ? { settings: { enabled: true, learning: true, paused: false, languages: [], noticeAcknowledged: true, ...overrides.settings } } : {}),
	}
}

const NcDialogStub = {
	name: 'NcDialog',
	props: { open: Boolean, name: String, buttons: Array },
	emits: ['update:open'],
	template: '<div v-if="open" class="dialog-stub"><slot /></div>',
}

/**
 * @param {object} [initial] what the first read answers
 * @return {Promise<object>} the mounted section, once it has loaded
 */
async function mountSection(initial = state()) {
	fetchInterests.mockResolvedValue(initial)
	const wrapper = mount(InterestsSettings, {
		global: {
			stubs: {
				NcDialog: NcDialogStub,
				NcPopover: { template: '<div><slot name="trigger" :attrs="{}" /></div>' },
				RouterLink: true,
			},
		},
		attachTo: document.body,
	})
	await flushPromises()

	return wrapper
}

const switchNamed = (wrapper, label) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((box) => box.text().includes(label))
const cloudTags = (wrapper) => wrapper.findAll('.interest-cloud__tag').map((tag) => tag.attributes('data-tag'))

describe('InterestsSettings', () => {
	let wrapper

	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = undefined
	})

	it('reads the interests and draws them as the cloud', async () => {
		wrapper = await mountSection()

		expect(fetchInterests).toHaveBeenCalled()
		expect(cloudTags(wrapper)).toEqual(['photography', 'analog', 'nextcloud'])
	})

	it('says so when the interests cannot be read', async () => {
		fetchInterests.mockRejectedValue(new Error('down'))
		wrapper = mount(InterestsSettings, { global: { stubs: { NcDialog: NcDialogStub, RouterLink: true } } })
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load your interests')
	})

	describe('the switches', () => {
		it('turns learning off, and draws what the server answered', async () => {
			saveInterestSettings.mockResolvedValue(state({ settings: { learning: false } }))
			wrapper = await mountSection()

			switchNamed(wrapper, 'Learn from my browsing').vm.$emit('update:modelValue', false)
			await flushPromises()

			expect(saveInterestSettings).toHaveBeenCalledWith({ learning: false })
			expect(switchNamed(wrapper, 'Learn from my browsing').props('modelValue')).toBe(false)
		})

		it('pauses learning', async () => {
			saveInterestSettings.mockResolvedValue(state({ settings: { paused: true } }))
			wrapper = await mountSection()

			switchNamed(wrapper, 'Pause learning').vm.$emit('update:modelValue', true)
			await flushPromises()

			expect(saveInterestSettings).toHaveBeenCalledWith({ paused: true })
			expect(switchNamed(wrapper, 'Pause learning').props('modelValue')).toBe(true)
		})

		it('cannot pause learning that is off', async () => {
			wrapper = await mountSection(state({ settings: { learning: false } }))

			expect(switchNamed(wrapper, 'Pause learning').props('disabled')).toBe(true)
		})

		it('moves a switch back when it could not be saved', async () => {
			saveInterestSettings.mockRejectedValue(new Error('down'))
			wrapper = await mountSection()

			switchNamed(wrapper, 'Learn from my browsing').vm.$emit('update:modelValue', false)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not save that setting')
			expect(switchNamed(wrapper, 'Learn from my browsing').props('modelValue')).toBe(true)
		})
	})

	describe('the states', () => {
		it('draws a frozen cloud, still editable, while learning is off', async () => {
			wrapper = await mountSection(state({ settings: { learning: false } }))

			expect(wrapper.find('.interest-cloud').classes()).toContain('interest-cloud--frozen')
			expect(wrapper.text()).toContain('Learning is off, so this list is frozen')
			expect(wrapper.find('.interest-cloud__tag').attributes('disabled')).toBeUndefined()
		})

		it('says it is still learning while learning is thin', async () => {
			wrapper = await mountSection(state({ thin: true }))

			expect(wrapper.text()).toContain('Still learning what you like.')
		})

		it('draws the empty cloud with its Add pill', async () => {
			wrapper = await mountSection(state({ interests: [], candidates: [] }))

			expect(wrapper.text()).toContain('Your interests appear here as you read')
			expect(wrapper.find('.interests-settings__add-pill').exists()).toBe(true)
		})
	})

	describe('adding', () => {
		it('turns the Add pill into a box, and adds what is typed as a manual interest', async () => {
			const added = state()
			added.interests.push({ tag: 'ceramics', rank: 3, source: 'manual', pinned: false, score: 3, trend: null })
			addInterest.mockResolvedValue(added)
			wrapper = await mountSection()

			await wrapper.find('.interests-settings__add-pill').trigger('click')
			const input = wrapper.find('.interests-settings__add-input')
			expect(document.activeElement).toBe(input.element)

			await input.setValue('#Ceramics')
			await wrapper.find('.interests-settings__add').trigger('submit')
			await flushPromises()

			expect(addInterest).toHaveBeenCalledWith('ceramics')
			expect(cloudTags(wrapper)).toContain('ceramics')
			// the box stays open for the next one
			expect(wrapper.find('.interests-settings__add-input').element.value).toBe('')
		})

		it('refuses something that is not a hashtag before asking', async () => {
			wrapper = await mountSection()

			await wrapper.find('.interests-settings__add-pill').trigger('click')
			await wrapper.find('.interests-settings__add-input').setValue('two words')
			await wrapper.find('.interests-settings__add').trigger('submit')

			expect(addInterest).not.toHaveBeenCalled()
			expect(wrapper.text()).toContain('A hashtag is letters, numbers and underscores')
		})

		it('suggests known hashtags from the same search the composer uses', async () => {
			axios.get.mockResolvedValue({
				data: { result: { exact: { hashtag: 'cer' }, tags: [{ hashtag: 'ceramics' }, { hashtag: 'photography' }] } },
			})
			addInterest.mockResolvedValue(state())
			wrapper = await mountSection()

			await wrapper.find('.interests-settings__add-pill').trigger('click')
			const input = wrapper.find('.interests-settings__add-input')
			await input.setValue('cer')
			await input.trigger('input')
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/global/tags/search', { params: { search: 'cer' } })
			// what is already an interest is not suggested again
			const options = wrapper.findAll('[role="option"]').map((option) => option.text())
			expect(options).toEqual(['#cer', '#ceramics'])

			await input.trigger('keydown', { key: 'ArrowDown' })
			await input.trigger('keydown', { key: 'ArrowDown' })
			expect(input.attributes('aria-activedescendant')).toBe('interests-settings-suggestions-1')
			await wrapper.find('.interests-settings__add').trigger('submit')
			await flushPromises()
			expect(addInterest).toHaveBeenCalledWith('ceramics')
		})

		it('closes the box on Escape', async () => {
			wrapper = await mountSection()

			await wrapper.find('.interests-settings__add-pill').trigger('click')
			await wrapper.find('.interests-settings__add-input').trigger('keydown', { key: 'Escape' })

			expect(wrapper.find('.interests-settings__add-input').exists()).toBe(false)
			expect(wrapper.find('.interests-settings__add-pill').exists()).toBe(true)
		})

		it('shows the server\'s reason when a tag is refused', async () => {
			addInterest.mockRejectedValue({ response: { data: { error: 'That is not a hashtag' } } })
			wrapper = await mountSection()

			await wrapper.find('.interests-settings__add-pill').trigger('click')
			await wrapper.find('.interests-settings__add-input').setValue('ok')
			await wrapper.find('.interests-settings__add').trigger('submit')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('That is not a hashtag')
		})
	})

	describe('the candidates', () => {
		it('are behind a disclosure, and adding one makes it a manual interest', async () => {
			addInterest.mockResolvedValue(state())
			wrapper = await mountSection()
			const disclosure = wrapper.find('.interests-settings__disclosure')

			expect(disclosure.text()).toBe('Show 2 more candidates')
			expect(disclosure.attributes('aria-expanded')).toBe('false')
			await disclosure.trigger('click')
			expect(disclosure.attributes('aria-expanded')).toBe('true')

			const chips = wrapper.findAll('.interests-settings__chip')
			expect(chips.map((chip) => chip.text())).toEqual(['#film', '#darkroom'])
			await chips[0].trigger('click')
			await flushPromises()

			expect(addInterest).toHaveBeenCalledWith('film')
		})

		it('are not offered when there are none', async () => {
			wrapper = await mountSection(state({ candidates: [] }))

			expect(wrapper.find('.interests-settings__disclosure').exists()).toBe(false)
		})
	})

	describe('the languages', () => {
		it('offers the languages the composer does, and saves the codes chosen', async () => {
			saveInterestSettings.mockResolvedValue(state({ settings: { languages: ['de', 'en'] } }))
			wrapper = await mountSection()
			const select = wrapper.findComponent({ name: 'NcSelect' })

			expect(select.props('options').map((option) => option.code)).toEqual(expect.arrayContaining(['de', 'en', 'fr']))
			expect(select.props('modelValue')).toEqual([])

			select.vm.$emit('update:modelValue', [{ code: 'de', name: 'German' }, { code: 'en', name: 'English' }])
			await flushPromises()

			expect(saveInterestSettings).toHaveBeenCalledWith({ languages: ['de', 'en'] })
			expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue').map((option) => option.code)).toEqual(['de', 'en'])
		})
	})

	describe('resetting', () => {
		it('asks first, in the page', async () => {
			wrapper = await mountSection()

			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
			await wrapper.findAllComponents({ name: 'NcButton' }).find((button) => button.text() === 'Reset all interests').trigger('click')

			expect(wrapper.find('.dialog-stub').exists()).toBe(true)
			expect(resetInterests).not.toHaveBeenCalled()
		})

		it('does nothing when the question is cancelled', async () => {
			wrapper = await mountSection()
			await wrapper.findAllComponents({ name: 'NcButton' }).find((button) => button.text() === 'Reset all interests').trigger('click')

			wrapper.findComponent(NcDialogStub).props('buttons')[0].callback()
			await flushPromises()

			expect(resetInterests).not.toHaveBeenCalled()
			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		})

		it('resets when it is confirmed, and draws the empty state it answers', async () => {
			resetInterests.mockResolvedValue(state({ interests: [], candidates: [] }))
			wrapper = await mountSection()
			await wrapper.findAllComponents({ name: 'NcButton' }).find((button) => button.text() === 'Reset all interests').trigger('click')

			await wrapper.findComponent(NcDialogStub).props('buttons')[1].callback()
			await flushPromises()

			expect(resetInterests).toHaveBeenCalled()
			expect(cloudTags(wrapper)).toEqual([])
			expect(showSuccess).toHaveBeenCalledWith('Your interests were reset')
			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		})
	})

	it('replaces its state with whatever the cloud hands up', async () => {
		wrapper = await mountSection()

		wrapper.findComponent({ name: 'InterestCloud' }).vm.$emit('update', state({ interests: [] }))
		await flushPromises()

		expect(cloudTags(wrapper)).toEqual([])
	})
})
