/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SubmitStatusButton from '../../../src/components/Composer/SubmitStatusButton.vue'

const mountButton = (props) => mount(SubmitStatusButton, { props })

describe('SubmitStatusButton', () => {
	it.each([
		['public', 'Post', 'Post publicly'],
		['unlisted', 'Post', 'Post unlisted'],
		['followers', 'Post to followers', 'Post to followers'],
		['direct', 'Send message to mentioned users', 'Post to recipients'],
	])('for %s visibility reads "%s" and is labelled "%s"', (visibility, text, value) => {
		const button = mountButton({ visibility, disabled: false }).find('button')
		expect(button.text()).toBe(text)
		expect(button.attributes('value')).toBe(value)
		expect(button.find('.send-icon').exists()).toBe(true)
	})

	it('has no label for an unknown visibility', () => {
		const button = mountButton({ visibility: 'secret', disabled: false }).find('button')
		expect(button.text()).toBe('')
		expect(button.attributes('value')).toBeUndefined()
	})

	it('is disabled unless explicitly enabled', () => {
		expect(mountButton({ visibility: 'public' }).find('button').attributes('disabled')).toBeDefined()
		expect(mountButton({ visibility: 'public', disabled: false }).find('button').attributes('disabled')).toBeUndefined()
	})

	it('emits click when enabled', async () => {
		const wrapper = mountButton({ visibility: 'public', disabled: false })
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
	})

	it('ignores clicks while disabled', async () => {
		const wrapper = mountButton({ visibility: 'public', disabled: true })
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('updates the label when the visibility changes', async () => {
		const wrapper = mountButton({ visibility: 'public', disabled: false })
		await wrapper.setProps({ visibility: 'direct' })
		expect(wrapper.find('button').text()).toBe('Send message to mentioned users')
	})
})
