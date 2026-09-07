/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, nextTick, withDirectives } from 'vue'

import focusOnCreate from '../../../src/directives/focusOnCreate.js'

const AutoFocusInput = {
	render: () => withDirectives(h('input', { class: 'target' }), [[focusOnCreate]]),
}

const LateInput = {
	data: () => ({ show: false }),
	render() {
		return h('div', [
			h('input', { class: 'other' }),
			this.show ? withDirectives(h('input', { class: 'late' }), [[focusOnCreate]]) : null,
		])
	},
}

describe('focusOnCreate directive', () => {
	let wrapper

	afterEach(() => {
		wrapper?.unmount()
	})

	it('focuses the element on the tick after it is mounted', async () => {
		wrapper = mount(AutoFocusInput, { attachTo: document.body })
		const input = wrapper.get('input.target').element

		expect(document.activeElement).not.toBe(input)

		await nextTick()

		expect(document.activeElement).toBe(input)
	})

	it('focuses an element that is created later, without touching its siblings', async () => {
		wrapper = mount(LateInput, { attachTo: document.body })
		await flushPromises()
		expect(document.activeElement).toBe(document.body)

		wrapper.vm.show = true
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.get('input.late').element)
	})

	it('does not steal focus back after the user moves on', async () => {
		wrapper = mount(LateInput, { attachTo: document.body })
		wrapper.vm.show = true
		await flushPromises()

		const other = wrapper.get('input.other').element
		other.focus()
		wrapper.vm.$forceUpdate()
		await flushPromises()

		expect(document.activeElement).toBe(other)
	})
})
