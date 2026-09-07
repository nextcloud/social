/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import EmptyContent from '../../../src/components/EmptyContent.vue'

const mountEmpty = (item) => mount(EmptyContent, { props: { item } })

describe('EmptyContent', () => {
	it('renders the description', () => {
		const wrapper = mountEmpty({ title: 'No posts', description: 'Follow somebody to fill your timeline', image: 'img/undraw/posts.svg' })
		expect(wrapper.find('.empty-content__description').text()).toBe('Follow somebody to fill your timeline')
	})

	it('resolves the illustration relative to the app root', () => {
		const img = mountEmpty({ description: 'x', image: 'img/undraw/posts.svg' }).find('img.empty-content__image')
		expect(img.attributes('src')).toBe('/apps/social/img/undraw/posts.svg')
		expect(img.attributes('alt')).toBe('')
		expect(img.element.closest('.empty-content__icon')).not.toBeNull()
	})

	it('omits the icon area when the item has no image', () => {
		const wrapper = mountEmpty({ description: 'Nothing here' })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.empty-content__icon').exists()).toBe(false)
		expect(wrapper.find('.empty-content__description').text()).toBe('Nothing here')
	})

	it('omits the description paragraph when there is none', () => {
		const wrapper = mountEmpty({ image: 'img/x.svg' })
		expect(wrapper.find('.empty-content__description').exists()).toBe(false)
	})
})
