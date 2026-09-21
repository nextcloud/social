/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import VerifiedCheck from '../../../src/components/VerifiedCheck.vue'

/**
 * @param {string} verifiedAt when the link was last proved
 * @return {object} the mounted mark
 */
function mountCheck(verifiedAt = '') {
	return mount(VerifiedCheck, { props: { verifiedAt } })
}

describe('the verified mark', () => {
	/**
	 * The colour is the whole of what the tick says visually, and a green tick
	 * is not readable. The label is the meaning.
	 */
	it('says in words what the tick means', () => {
		const wrapper = mountCheck()

		expect(wrapper.find('svg').attributes('aria-label'))
			.toBe('Ownership of this link was verified')
		expect(wrapper.find('svg').attributes('role')).toBe('img')
	})

	/** A verification is a check made once, and how long ago says what it is worth. */
	it('names the day it was proved, when it knows it', () => {
		const label = mountCheck('2026-09-18T10:30:00+00:00').find('svg').attributes('aria-label')

		expect(label).toContain('2026')
		expect(label).toMatch(/September/)
	})

	it('shows the same words on hover as it gives a screen reader', () => {
		const wrapper = mountCheck('2026-09-18T10:30:00+00:00')

		expect(wrapper.find('.verified-check').attributes('title'))
			.toBe(wrapper.find('svg').attributes('aria-label'))
	})

	/**
	 * A row whose date did not come through, or came through in a shape the
	 * browser cannot read, is still verified — and saying "verified on" with
	 * nothing after it reads as though the date were the part that failed.
	 */
	it('still says it is verified when the date is unusable', () => {
		expect(mountCheck('not a date').find('svg').attributes('aria-label'))
			.toBe('Ownership of this link was verified')
	})

	it('draws one path and no sprite', () => {
		const wrapper = mountCheck()

		expect(wrapper.findAll('.verified-check__tick')).toHaveLength(1)
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('use').exists()).toBe(false)
	})

	/** It sits inline beside a name, so it must not carry its own colour. */
	it('draws in the colour of the text it sits in', () => {
		expect(mountCheck().find('.verified-check__tick').attributes('stroke')).toBe('currentColor')
	})
})
