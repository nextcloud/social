/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import OAuth2Authorize from '../../../src/views/OAuth2Authorize.vue'

function setState(key, value) {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

describe('OAuth2Authorize', () => {
	let wrapper

	beforeEach(() => {
		setState('appName', 'Tusky')
		wrapper = mount(OAuth2Authorize)
	})

	it('names the third party application asking for access', () => {
		expect(wrapper.find('h1').text()).toBe('Authorization required')
		const text = wrapper.find('p').text()
		expect(text).toContain('Tusky would like permission to access your account. It is a third party application.')
		expect(wrapper.find('p b').text()).toBe('If you do not trust it, then you should not authorize it.')
	})

	it('posts the decision back to the authorize endpoint that rendered the page', () => {
		const form = wrapper.find('form.guest-box')
		expect(form.attributes('method')).toBe('post')
		expect(form.attributes('action')).toBeUndefined()
	})

	it('sends the CSRF request token as a hidden field', () => {
		const token = wrapper.find('form input[name="requesttoken"]')
		expect(token.attributes('type')).toBe('hidden')
		expect(token.element.value).toBe('test-request-token')
	})

	it('submits the form through the authorize button', () => {
		const submit = wrapper.find('form button[type="submit"]')
		expect(submit.text()).toBe('Authorize')
		expect(wrapper.findAll('form button[type="submit"]')).toHaveLength(1)
	})

	it('lets the user deny by leaving for the app home page', () => {
		const deny = wrapper.find('form a')
		expect(deny.text()).toBe('Deny')
		expect(deny.attributes('href')).toBe('/index.php/apps/social/')
	})

	it('picks up a different app name from the initial state', () => {
		setState('appName', 'Ivory')
		expect(mount(OAuth2Authorize).find('p').text()).toContain('Ivory would like permission')
	})
})
