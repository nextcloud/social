/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ConfirmDialog from '../../../../src/components/admin/ConfirmDialog.vue'

/** A dialog that draws what it is given, so the slot can be asserted on. */
const NcDialog = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: '<div class="dialog"><slot /></div>',
}

/**
 * @param {object} props what the dialog is asked to say
 * @return {object} the mounted dialog
 */
function mountDialog(props = {}) {
	return mount(ConfirmDialog, {
		props: {
			open: true,
			name: 'Suspend this account?',
			message: 'Their posts stay, and nothing new arrives from them.',
			confirmLabel: 'Suspend',
			...props,
		},
		global: { stubs: { NcDialog } },
	})
}

/**
 * @param {object} wrapper the mounted dialog
 * @return {object[]} the buttons it hands the dialog
 */
function buttons(wrapper) {
	return wrapper.findComponent(NcDialog).props('buttons')
}

/**
 * The question asked before something that cannot be taken back. Each of these
 * deletes something for everybody, and each used to ask with `window.confirm`.
 */
describe('the confirmation dialog', () => {
	it('asks the question and says what it will cost', () => {
		const wrapper = mountDialog()

		expect(wrapper.findComponent(NcDialog).props('name')).toBe('Suspend this account?')
		expect(wrapper.find('.confirm-dialog__message').text())
			.toBe('Their posts stay, and nothing new arrives from them.')
	})

	/** "Cancel" and the verb itself, never "OK": a button says what it does. */
	it('names the action on the button that performs it', () => {
		const [cancel, confirm] = buttons(mountDialog())

		expect(cancel.label).toBe('Cancel')
		expect(confirm.label).toBe('Suspend')
	})

	/** The dangerous one looks dangerous. */
	it('marks the destructive button as destructive', () => {
		const [cancel, confirm] = buttons(mountDialog())

		expect(confirm.variant).toBe('error')
		expect(cancel.variant).toBeUndefined()
	})

	it('closes without doing anything when cancelled', () => {
		const wrapper = mountDialog()

		buttons(wrapper)[0].callback()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('closes and goes ahead when confirmed', () => {
		const wrapper = mountDialog()

		buttons(wrapper)[1].callback()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	/** Pressing escape, or the dialog's own close: the caller hears about it. */
	it('passes the dialog own close straight through', async () => {
		const wrapper = mountDialog()

		wrapper.findComponent(NcDialog).vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('is closed until it is asked for', () => {
		expect(mountDialog({ open: false }).findComponent(NcDialog).props('open')).toBe(false)
	})
})
