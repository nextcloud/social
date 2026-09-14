/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import GifPicker from '../../../src/components/Composer/GifPicker.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const stubs = {
	// $attrs already carries the parent's @click; emitting one as well would
	// fire every press twice
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

const library = [
	{ slug: 'happy-cat', title: 'A very happy cat', url: '/gif/happy-cat', media_type: 'image/gif' },
	{ slug: 'shipit', title: 'Ship it', url: '/gif/shipit', media_type: 'image/gif' },
]

/**
 * @param {Array} gifs what the server answers
 * @return {Promise<object>} the mounted picker, once its request has settled
 */
async function mountPicker(gifs = library) {
	axios.get.mockResolvedValue({ data: gifs })

	const wrapper = mount(GifPicker, { global: { stubs } })
	await flushPromises()

	return wrapper
}

describe('GifPicker', () => {
	beforeEach(() => {
		axios.get.mockReset()
		vi.useFakeTimers({ shouldAdvanceTime: true })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the library as a grid', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.findAll('.gif-picker__item')).toHaveLength(2)
		expect(wrapper.find('.gif-picker__image').attributes('src')).toBe('/gif/happy-cat')
	})

	it('gives every picture alt text', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.findAll('.gif-picker__image')[0].attributes('alt')).toBe('A very happy cat')
	})

	// the whole library is in the grid and most of it is below the fold; these
	// are animations
	it('leaves the pictures below the fold until they are reached', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.find('.gif-picker__image').attributes('loading')).toBe('lazy')
	})

	it('hands the chosen picture up rather than attaching it itself', async () => {
		const wrapper = await mountPicker()

		await wrapper.findAll('.gif-picker__item')[1].trigger('click')

		expect(wrapper.emitted('chosen')).toEqual([[library[1]]])
	})

	it('waits for a pause in the typing before it searches again', async () => {
		const wrapper = await mountPicker()
		axios.get.mockClear()

		await wrapper.find('input').setValue('cat')
		expect(axios.get).not.toHaveBeenCalled()

		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(expect.any(String), { params: { q: 'cat' } })
	})

	it('searches once for a run of keystrokes', async () => {
		const wrapper = await mountPicker()
		axios.get.mockClear()

		await wrapper.find('input').setValue('c')
		await wrapper.find('input').setValue('ca')
		await wrapper.find('input').setValue('cat')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	describe('when there is nothing to show', () => {
		// an empty library and a search that found nothing are different
		// things, and only one of them is something the reader can fix
		it('says the library is empty', async () => {
			const wrapper = await mountPicker([])

			expect(wrapper.find('.gif-picker__note').text())
				.toBe('Nobody has added any pictures to this instance yet.')
		})

		it('says a search found nothing', async () => {
			const wrapper = await mountPicker([])
			axios.get.mockResolvedValue({ data: [] })

			await wrapper.find('input').setValue('aardvark')
			vi.advanceTimersByTime(300)
			await flushPromises()

			expect(wrapper.find('.gif-picker__note').text()).toBe('Nothing here matches that.')
		})
	})

	it('shows an empty library rather than an error when the request fails', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(GifPicker, { global: { stubs } })
		await flushPromises()

		expect(wrapper.findAll('.gif-picker__item')).toHaveLength(0)
	})

	it('can be closed', async () => {
		const wrapper = await mountPicker()

		await wrapper.find('[aria-label="Close the picture library"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
