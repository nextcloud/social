/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import SetupChecks from '../../../src/components/SetupChecks.vue'

function mountChecks(checks, addresses = { configured: '', expected: '' }, clientApi = []) {
	return mount(SetupChecks, { props: { checks, addresses, clientApi } })
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

	it('tells an administrator that Mastodon apps cannot connect, and what to paste', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true, clientApi: false })

		const text = wrapper.text()
		expect(text).toContain('Mastodon apps cannot connect to this server')
		// the rule is useless without the [P]: a plain rewrite answers 404
		expect(text).toContain('[P,QSA,L]')
		expect(text).toContain('ProxyPreserveHost On')
		expect(wrapper.find('pre').exists()).toBe(true)
	})

	it('shows the address an app would actually build, not a placeholder', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true, clientApi: false })

		expect(wrapper.text()).toContain('https://' + window.location.host + '/api/v1/instance')
	})

	it('links the documentation for nginx and the rest', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true, clientApi: false })

		const href = wrapper.findAll('a').at(-1).attributes('href')
		expect(href).toContain('Admin.md#mastodon-apps-cannot-connect')
	})

	it('says nothing when the client API answers', () => {
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true, clientApi: true })

		expect(wrapper.findAll('h3')).toHaveLength(0)
	})

	it('stays quiet about a client API check an older server never sent', () => {
		// absent, not false: same rule as the address check above
		const wrapper = mountChecks({ wellknown: true, cloudAddress: true })

		expect(wrapper.findAll('h3')).toHaveLength(0)
	})

	it('says nothing about WebFinger when there was no account to probe with', () => {
		// null, not false: an instance nobody has an account on yet cannot be
		// asked about one, and an untested check must not read as a broken one
		const wrapper = mountChecks({ wellknown: null, cloudAddress: true, clientApi: true })

		expect(wrapper.findAll('h3')).toHaveLength(0)
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

	describe('when Mastodon apps cannot connect', () => {
		const failing = { wellknown: true, cloudAddress: true, clientApi: false }

		it('says what the probe got, not only that it failed', () => {
			const wrapper = mountChecks(failing, undefined, [
				{ base: 'https://cloud.example', status: 404, reason: 'status' },
			])

			expect(wrapper.text()).toContain('https://cloud.example/api/v1/instance and got 404')
		})

		/**
		 * The case this exists for: rules pasted correctly, proxying to a port
		 * that serves a different document root. Identical 404 to having no
		 * rules at all, and fixed somewhere else entirely.
		 */
		it('warns that the proxy target has to serve this Nextcloud', () => {
			const wrapper = mountChecks(failing, undefined, [
				{ base: 'https://cloud.example', status: 404, reason: 'status' },
			])

			expect(wrapper.text()).toContain('has to serve this Nextcloud itself')
			expect(wrapper.text()).toContain('names the port it came from')
		})

		it('tells a wrong answer apart from no answer', () => {
			expect(mountChecks(failing, undefined, [
				{ base: 'https://cloud.example', status: 200, reason: 'not-social' },
			]).text()).toContain('not this app')

			expect(mountChecks(failing, undefined, [
				{ base: 'https://cloud.example', status: 0, reason: 'unreachable' },
			]).text()).toContain('could not reach https://cloud.example at all')
		})

		/**
		 * The server tries the address Social is configured for, then the host
		 * the request came in on, then its own base URL. The first is the one
		 * an administrator is thinking about; the rest are fallbacks.
		 */
		it('reports the first base tried, the others being fallbacks', () => {
			const wrapper = mountChecks(failing, undefined, [
				{ base: 'https://first.example', status: 404, reason: 'status' },
				{ base: 'https://fallback.example', status: 503, reason: 'status' },
			])

			expect(wrapper.text()).toContain('https://first.example/api/v1/instance and got 404')
			expect(wrapper.text()).not.toContain('fallback.example')
		})

		it('still explains the feature when there is no diagnosis to add', () => {
			const wrapper = mountChecks(failing, undefined, [])

			expect(wrapper.text()).toContain('Mastodon apps cannot connect to this server')
			expect(wrapper.find('.setup-checks__finding').exists()).toBe(false)
		})
	})
})
