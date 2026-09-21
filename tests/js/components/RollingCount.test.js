/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import RollingCount from '../../../src/components/RollingCount.vue'

/**
 * @param {boolean} reduce what the reader asked for
 */
function prefersReducedMotion(reduce) {
	window.matchMedia = vi.fn().mockReturnValue({ matches: reduce })
}

describe('RollingCount', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		prefersReducedMotion(false)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/**
	 * The zero case lives in the component and not in the caller: a counter
	 * only rendered above zero cannot animate its own first "1" if the parent
	 * decides whether it exists at all.
	 */
	it('draws nothing at zero', () => {
		const wrapper = mount(RollingCount, { props: { count: 0 } })

		expect(wrapper.find('.rolling-count').exists()).toBe(false)
	})

	it('shows the number it is given', () => {
		const wrapper = mount(RollingCount, { props: { count: 7 } })

		expect(wrapper.find('.rolling-count__value--current').text()).toBe('7')
	})

	it('rolls up when the number grows and down when it shrinks', async () => {
		const wrapper = mount(RollingCount, { props: { count: 1 } })

		await wrapper.setProps({ count: 2 })
		expect(wrapper.find('.rolling-count').classes()).toContain('rolling-count--up')

		await wrapper.setProps({ count: 1 })
		expect(wrapper.find('.rolling-count').classes()).toContain('rolling-count--down')
	})

	/** The digit on its way out is decoration, so it is never read aloud. */
	it('sends the old digit away without announcing it', async () => {
		const wrapper = mount(RollingCount, { props: { count: 1 } })
		await wrapper.setProps({ count: 2 })

		const leaving = wrapper.find('.rolling-count__value--leaving')
		expect(leaving.text()).toBe('1')
		expect(leaving.attributes('aria-hidden')).toBe('true')
	})

	/** The first one has no predecessor to send away, so it rolls in alone. */
	it('has nothing to send away on the very first number', async () => {
		const wrapper = mount(RollingCount, { props: { count: 0 } })

		await wrapper.setProps({ count: 1 })

		expect(wrapper.find('.rolling-count__value--leaving').exists()).toBe(false)
	})

	it('comes back to rest once the roll is over', async () => {
		const wrapper = mount(RollingCount, { props: { count: 1 } })
		await wrapper.setProps({ count: 2 })

		vi.advanceTimersByTime(300)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.rolling-count').classes()).not.toContain('rolling-count--up')
		expect(wrapper.find('.rolling-count__value--leaving').exists()).toBe(false)
	})

	/** Nothing is rendered at zero, so a count that empties has nowhere to go. */
	it('does not try to roll to nothing', async () => {
		const wrapper = mount(RollingCount, { props: { count: 1 } })

		await wrapper.setProps({ count: 0 })

		expect(wrapper.find('.rolling-count').exists()).toBe(false)
	})

	it('does not animate for a reader who asked it not to', async () => {
		prefersReducedMotion(true)
		const wrapper = mount(RollingCount, { props: { count: 1 } })

		await wrapper.setProps({ count: 2 })

		expect(wrapper.find('.rolling-count').classes()).not.toContain('rolling-count--up')
		expect(wrapper.find('.rolling-count__value--leaving').exists()).toBe(false)
		// and it still says the right number
		expect(wrapper.find('.rolling-count__value--current').text()).toBe('2')
	})

	/**
	 * A timer left running past the component logs into a worker that is
	 * already closing, which fails the whole run naming no test.
	 */
	it('drops its timer when it goes', async () => {
		const wrapper = mount(RollingCount, { props: { count: 1 } })
		await wrapper.setProps({ count: 2 })

		wrapper.unmount()

		expect(() => vi.advanceTimersByTime(300)).not.toThrow()
	})
})
