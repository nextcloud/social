/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ReactionPicker from '../../../src/components/ReactionPicker.vue'
import eventBus, { REACTION_PICK } from '../../../src/services/eventBus.js'

const stubs = {
	NcEmojiPicker: { name: 'NcEmojiPicker', template: '<div class="picker-stub"><slot /></div>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
}

const mountPicker = () => mount(ReactionPicker, { global: { stubs } })

describe('ReactionPicker', () => {
	afterEach(() => {
		eventBus.all.clear()
	})

	it('shows nothing until a card asks', () => {
		const wrapper = mountPicker()

		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
	})

	it('opens when a card asks', async () => {
		const wrapper = mountPicker()

		eventBus.emit(REACTION_PICK, { react: vi.fn() })
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.reaction-picker').exists()).toBe(true)
	})

	it('gives the chosen emoji back to whoever asked', async () => {
		const react = vi.fn()
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react })
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'NcEmojiPicker' }).vm.$emit('select', '🎉')
		await wrapper.vm.$nextTick()

		expect(react).toHaveBeenCalledWith('🎉')
		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
	})

	// older @nextcloud/vue passed an object carrying the emoji as `native`
	it('takes the emoji whichever shape the picker sends', async () => {
		const react = vi.fn()
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react })
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'NcEmojiPicker' }).vm.$emit('select', { native: '👍' })
		await wrapper.vm.$nextTick()

		expect(react).toHaveBeenCalledWith('👍')
	})

	it('tells nobody anything when it is closed without a choice', async () => {
		const react = vi.fn()
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react })
		await wrapper.vm.$nextTick()

		await wrapper.find('[aria-label="Close"]').trigger('click')

		expect(react).not.toHaveBeenCalled()
		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
	})

	it('ignores an emoji that came back empty', async () => {
		const react = vi.fn()
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react })
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'NcEmojiPicker' }).vm.$emit('select', '')
		await wrapper.vm.$nextTick()

		expect(react).not.toHaveBeenCalled()
	})

	it('ignores a request with nothing to call back', async () => {
		const wrapper = mountPicker()

		eventBus.emit(REACTION_PICK, {})
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
	})

	it('stops listening once it is gone', async () => {
		const wrapper = mountPicker()
		wrapper.unmount()

		expect(() => eventBus.emit(REACTION_PICK, { react: vi.fn() })).not.toThrow()
	})
	// a backdrop that only answers a mouse is invisible to the keyboard
	it('closes on Escape', async () => {
		const react = vi.fn()
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react })
		await wrapper.vm.$nextTick()

		await wrapper.find('.reaction-picker').trigger('keydown.esc')

		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
		expect(react).not.toHaveBeenCalled()
	})

	it('closes when the backdrop is pressed', async () => {
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react: vi.fn() })
		await wrapper.vm.$nextTick()

		await wrapper.find('.reaction-picker').trigger('click')

		expect(wrapper.find('.reaction-picker').exists()).toBe(false)
	})

	it('announces itself as a dialog', async () => {
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react: vi.fn() })
		await wrapper.vm.$nextTick()

		const backdrop = wrapper.find('.reaction-picker')

		expect(backdrop.attributes('role')).toBe('dialog')
		expect(backdrop.attributes('aria-modal')).toBe('true')
		expect(backdrop.attributes('aria-label')).toBe('Choose a reaction')
	})

	it('portals the reaction popover outside its flex panel', async () => {
		const wrapper = mountPicker()
		eventBus.emit(REACTION_PICK, { react: vi.fn() })
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'NcEmojiPicker' }).attributes('container')).toBe('body')
	})
})
