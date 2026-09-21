/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import TimelineSkeleton from '../../../src/components/TimelineSkeleton.vue'

describe('the loading timeline', () => {
	it('draws three posts unless it is told otherwise', () => {
		expect(mount(TimelineSkeleton).findAll('.skeleton__post')).toHaveLength(3)
	})

	it('draws as many as it is asked for', () => {
		expect(mount(TimelineSkeleton, { props: { count: 8 } }).findAll('.skeleton__post'))
			.toHaveLength(8)
	})

	/**
	 * It stands in for text that has not arrived. A screen reader announcing
	 * the shape of it announces nothing, repeatedly, over whatever the reader
	 * was listening to.
	 */
	it('is not announced', () => {
		expect(mount(TimelineSkeleton).find('.skeleton').attributes('aria-hidden')).toBe('true')
	})

	/**
	 * The point of it over a spinner: the posts land where the placeholders
	 * were, so each one has a header, lines and a row of actions.
	 */
	it('has the shape of the post that will replace it', () => {
		const post = mount(TimelineSkeleton, { props: { count: 1 } }).find('.skeleton__post')

		expect(post.find('.skeleton__avatar').exists()).toBe(true)
		expect(post.find('.skeleton__name').exists()).toBe(true)
		expect(post.findAll('.skeleton__line')).toHaveLength(2)
		expect(post.findAll('.skeleton__action')).toHaveLength(3)
	})

	it('draws nothing when there is nothing to wait for', () => {
		expect(mount(TimelineSkeleton, { props: { count: 0 } }).findAll('.skeleton__post'))
			.toHaveLength(0)
	})
})
