/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ActivitySection from '../../../../src/components/admin/ActivitySection.vue'

describe('the activity section', () => {
	it('shows both figures and who wrote them', () => {
		const wrapper = mount(ActivitySection, {
			props: { activity: { day: { posts: 3, authors: 2 }, week: { posts: 11, authors: 4 } } },
		})

		expect(wrapper.text()).toContain('3')
		expect(wrapper.text()).toContain('posts in the last day')
		expect(wrapper.text()).toContain('by 2 accounts')
		expect(wrapper.text()).toContain('11')
		expect(wrapper.text()).toContain('by 4 accounts')
	})

	/**
	 * A server nobody posts from is a server nobody follows back, and it is
	 * the number worth saying something about rather than leaving as a zero.
	 */
	it('says something about a week with nothing in it', () => {
		const wrapper = mount(ActivitySection, {
			props: { activity: { day: { posts: 0, authors: 0 }, week: { posts: 0, authors: 0 } } },
		})

		expect(wrapper.text()).toContain('Nobody here has posted this week')
	})

	/** State from a server that did not send it must not break the page. */
	it('draws zeroes rather than failing when the numbers are missing', () => {
		const wrapper = mount(ActivitySection, { props: { activity: {} } })

		expect(wrapper.text()).toContain('posts in the last day')
	})
})
