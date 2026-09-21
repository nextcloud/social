/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import FilterPicker from '../../../src/components/Composer/FilterPicker.vue'
import { availableFilters } from '../../../src/utils/imageFilters.js'

/**
 * @param {object} props the picture and the chosen filter
 * @return {object} the mounted strip
 */
function mountPicker(props = {}) {
	return mount(FilterPicker, { props: { modelValue: 'none', ...props } })
}

describe('the filter strip', () => {
	it('offers every filter there is', () => {
		const wrapper = mountPicker()

		expect(wrapper.findAll('.filter-picker__option')).toHaveLength(availableFilters().length)
		expect(wrapper.findAll('.filter-picker__name').map((name) => name.text()))
			.toEqual(availableFilters().map((filter) => filter.name))
	})

	/**
	 * Eight swatches are eight copies of the same `<img>` with a different
	 * `filter:` on each: one decode, no canvas work, and the writer sees their
	 * own photo rather than a stock gradient.
	 */
	it('draws the actual picture through each filter', () => {
		const wrapper = mountPicker({ preview: 'blob:photo' })

		const images = wrapper.findAll('.filter-picker__image')
		expect(images).toHaveLength(availableFilters().length)
		expect(images.every((image) => image.attributes('src') === 'blob:photo')).toBe(true)
		// jsdom serialises `filter` under its prefixed name, so match the value
		expect(images.map((image) => image.attributes('style') ?? ''))
			.toEqual(availableFilters().map((filter) => expect.stringContaining(filter.css)))
	})

	/** Before the file has been read there is nothing to draw through. */
	it('falls back to a swatch when there is no picture yet', () => {
		const wrapper = mountPicker()

		expect(wrapper.find('.filter-picker__image').exists()).toBe(false)
		expect(wrapper.findAll('.filter-picker__placeholder'))
			.toHaveLength(availableFilters().length)
	})

	it('chooses a filter when its swatch is pressed', async () => {
		const wrapper = mountPicker()
		const second = availableFilters()[1]

		await wrapper.findAll('.filter-picker__option')[1].trigger('click')

		expect(wrapper.emitted('update:modelValue')).toEqual([[second.id]])
	})

	describe('what it tells a screen reader', () => {
		/**
		 * One of a set, exactly one chosen: a radio group. A row of buttons
		 * would be announced as eight unrelated things with no indication of
		 * which one the picture is wearing.
		 */
		it('is a radio group, with the chosen one checked', () => {
			const wrapper = mountPicker({ modelValue: availableFilters()[2].id })

			expect(wrapper.find('.filter-picker').attributes('role')).toBe('radiogroup')
			expect(wrapper.find('.filter-picker').attributes('aria-label')).toBe('Filter')

			const checked = wrapper.findAll('[role="radio"]')
				.map((option) => option.attributes('aria-checked'))
			expect(checked).toEqual(availableFilters().map((_, index) => String(index === 2)))
		})

		/** The swatch is the name drawn; the name is already there in text. */
		it('does not announce the swatches themselves', () => {
			const wrapper = mountPicker({ preview: 'blob:photo' })

			expect(wrapper.findAll('.filter-picker__image')
				.every((image) => image.attributes('aria-hidden') === 'true' && image.attributes('alt') === ''))
				.toBe(true)
		})
	})

	it('marks the chosen one so it can be seen as well as heard', async () => {
		const wrapper = mountPicker()

		expect(wrapper.findAll('.filter-picker__option--active')).toHaveLength(1)

		await wrapper.setProps({ modelValue: availableFilters()[3].id })

		const active = wrapper.findAll('.filter-picker__option')[3]
		expect(active.classes()).toContain('filter-picker__option--active')
		expect(wrapper.findAll('.filter-picker__option--active')).toHaveLength(1)
	})

	/**
	 * A filter that was taken out of the list, or a draft saved under an older
	 * one: nothing is marked, and the strip still works.
	 */
	it('marks nothing when the chosen filter is not one of these', () => {
		expect(mountPicker({ modelValue: 'daguerreotype' }).findAll('.filter-picker__option--active'))
			.toHaveLength(0)
	})
})
