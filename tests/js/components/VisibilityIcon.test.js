/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VisibilityIcon from '../../../src/components/Visibility/VisibilityIcon.vue'

describe('VisibilityIcon', () => {
	it.each([
		['public', 'earth-icon'],
		['unlisted', 'lock-open-icon'],
		['followers', 'account-multiple-icon'],
		['direct', 'at-icon'],
	])('renders the %s visibility as a single %s', (visibility, iconClass) => {
		const wrapper = mount(VisibilityIcon, { props: { visibility } })
		const icons = wrapper.findAll('.material-design-icon')
		expect(icons).toHaveLength(1)
		expect(icons[0].classes()).toContain(iconClass)
	})

	// the rendered <svg width> is what "how big is the globe" means
	const iconSize = (wrapper) => wrapper.find('svg').attributes('width')

	it('draws the icon at the size it is asked for', () => {
		expect(iconSize(mount(VisibilityIcon, { props: { visibility: 'public', size: 16 } }))).toBe('16')
	})

	/**
	 * The size used to be hard-coded, so every caller that passed one was
	 * quietly ignored — `VisibilitySelect` had been asking for 20 and getting
	 * 22 for as long as it had been asking.
	 */
	it('honours a size from every visibility, not just the public one', () => {
		for (const visibility of ['public', 'unlisted', 'followers', 'direct', 'secret']) {
			expect(iconSize(mount(VisibilityIcon, { props: { visibility, size: 14 } }))).toBe('14')
		}
	})

	it('falls back to a size of its own when asked for none', () => {
		expect(iconSize(mount(VisibilityIcon, { props: { visibility: 'public' } }))).toBe('22')
	})

	it('says it does not recognise an unknown visibility', () => {
		const wrapper = mount(VisibilityIcon, { props: { visibility: 'secret' } })

		// showing nothing reads as "public", which for an unknown audience may
		// be exactly the wrong thing to imply
		expect(wrapper.find('.help-circle-outline-icon').exists()).toBe(true)
	})

	it('switches the icon when the visibility changes', async () => {
		const wrapper = mount(VisibilityIcon, { props: { visibility: 'public' } })
		expect(wrapper.find('.earth-icon').exists()).toBe(true)
		await wrapper.setProps({ visibility: 'direct' })
		expect(wrapper.find('.earth-icon').exists()).toBe(false)
		expect(wrapper.find('.at-icon').exists()).toBe(true)
	})

	it('lets a title fall through to the rendered icon for tooltips', () => {
		const wrapper = mount(VisibilityIcon, { props: { visibility: 'public' }, attrs: { title: 'Public' } })
		expect(wrapper.find('.earth-icon').find('title').text()).toBe('Public')
	})
})
