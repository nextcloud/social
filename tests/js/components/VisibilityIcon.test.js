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
