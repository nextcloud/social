/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PlacePicker from '../../../src/components/Composer/PlacePicker.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

async function type(wrapper, text) {
	const input = wrapper.find('.place-picker__search input')
	await input.setValue(text)
	vi.advanceTimersByTime(300)
	await flushPromises()
}

describe('PlacePicker', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		get.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('suggests the places people here have posted from, and picks one by its id', async () => {
		get.mockResolvedValue({ data: [{ id: '4', name: 'Berlin', country: 'Germany' }] })
		const wrapper = mount(PlacePicker, { props: { place: null } })

		await type(wrapper, 'Ber')

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/places/search'), { params: { q: 'Ber' } })
		const results = wrapper.findAll('.place-picker__result')
		expect(results[0].text()).toContain('Berlin, Germany')
		await results[0].trigger('click')
		expect(wrapper.emitted('update:place')[0]).toEqual([{ id: '4', name: 'Berlin', country: 'Germany' }])
	})

	it('lets the poster name a place nobody here has posted from, with a country if they like', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mount(PlacePicker, { props: { place: null } })

		await type(wrapper, 'Tiny Cove')
		await wrapper.find('.place-picker__country input').setValue('Ireland')
		await wrapper.find('.place-picker__result--new').trigger('click')

		expect(wrapper.emitted('update:place')[0]).toEqual([{ name: 'Tiny Cove', country: 'Ireland' }])
	})

	it('shows the chosen place as a chip that can be taken off again', async () => {
		const wrapper = mount(PlacePicker, { props: { place: { id: '4', name: 'Berlin', country: 'Germany' } } })

		expect(wrapper.find('.place-picker__chosen').text()).toContain('Berlin, Germany')
		expect(wrapper.find('.place-picker__search').exists()).toBe(false)
		await wrapper.find('.place-picker__chosen button').trigger('click')
		expect(wrapper.emitted('update:place')[0]).toEqual([null])
	})

	it('asks the server once the typing has paused, not on every key', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mount(PlacePicker, { props: { place: null } })

		const input = wrapper.find('.place-picker__search input')
		await input.setValue('B')
		await input.setValue('Be')
		await input.setValue('Ber')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
	})
})
