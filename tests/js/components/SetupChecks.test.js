/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import SetupChecks from '../../../src/components/SetupChecks.vue'

function mountChecks(checks, addresses = { configured: '', expected: '' }) {
	return mount(SetupChecks, { props: { checks, addresses } })
}

describe('SetupChecks', () => {
	it('says nothing when everything passes', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true })

		expect(wrapper.findAll('h3')).toHaveLength(0)
	})

	it('explains a broken .well-known and links the documentation', () => {
		const wrapper = mountChecks({ wellknown: false, cloudAddress: true })

		expect(wrapper.text()).toContain('.well-known/webfinger isn\'t properly set up!')
		expect(wrapper.find('a').attributes('href')).toContain('admin-setup-well-known-URL')
	})

	it('names both addresses when the server has moved out from under the app', () => {
		const wrapper = mountChecks(
			{ wellknown: true, cloudAddress: false },
			{ configured: 'https://old.example/index.php', expected: 'https://new.example/index.php' },
		)

		const text = wrapper.text()
		expect(text).toContain('Social is set up for a different address than this server')
		// an administrator cannot act on this without being told which is which
		expect(text).toContain('https://old.example/index.php')
		expect(text).toContain('https://new.example/index.php')
	})

	it('says the address cannot simply be changed, and what it would cost', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: false })

		expect(wrapper.text()).toContain('occ social:reset')
	})

	it('reports both problems at once when both are wrong', () => {
		const wrapper = mountChecks({ wellknown: false, cloudAddress: false })

		expect(wrapper.findAll('h3')).toHaveLength(2)
	})

	it('stays quiet about an address check an older server never sent', () => {
		// the field is absent, not false: nothing is known, so nothing is claimed
		const wrapper = mountChecks({ wellknown: true })

		expect(wrapper.findAll('h3')).toHaveLength(0)
	})
})
