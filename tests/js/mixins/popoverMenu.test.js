/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'

import popoverMenu from '../../../src/mixins/popoverMenu.js'

const Probe = {
	mixins: [popoverMenu],
	render() {
		return h('div', { class: { open: this.menuOpened } })
	},
}

describe('popoverMenu mixin', () => {
	let wrapper

	afterEach(() => {
		wrapper?.unmount()
	})

	it('starts closed', () => {
		wrapper = mount(Probe)

		expect(wrapper.vm.menuOpened).toBe(false)
		expect(wrapper.classes()).not.toContain('open')
	})

	it('togglePopoverMenu flips the state each time', async () => {
		wrapper = mount(Probe)

		wrapper.vm.togglePopoverMenu()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.menuOpened).toBe(true)
		expect(wrapper.classes()).toContain('open')

		wrapper.vm.togglePopoverMenu()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.menuOpened).toBe(false)
	})

	it('hidePopoverMenu closes an open menu and keeps a closed one closed', () => {
		wrapper = mount(Probe)

		wrapper.vm.togglePopoverMenu()
		wrapper.vm.hidePopoverMenu()
		expect(wrapper.vm.menuOpened).toBe(false)

		wrapper.vm.hidePopoverMenu()
		expect(wrapper.vm.menuOpened).toBe(false)
	})

	it('keeps the state per component instance', () => {
		wrapper = mount(Probe)
		const other = mount(Probe)

		wrapper.vm.togglePopoverMenu()

		expect(wrapper.vm.menuOpened).toBe(true)
		expect(other.vm.menuOpened).toBe(false)
		other.unmount()
	})
})
