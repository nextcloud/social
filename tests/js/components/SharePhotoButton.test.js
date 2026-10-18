/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import SharePhotoButton from '../../../src/components/SharePhotoButton.vue'
import eventBus, { COMPOSE_MEDIA } from '../../../src/services/eventBus.js'

/**
 * @param {object} wrapper the button
 * @param {File[]} files what the picker hands back
 */
async function pick(wrapper, files) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: files, configurable: true })
	await input.trigger('change')
}

describe('SharePhotoButton', () => {
	afterEach(() => {
		eventBus.all.clear()
	})

	it('says what it does', () => {
		expect(mount(SharePhotoButton).text()).toContain('Share a photo')
	})

	// the picker can only be opened in answer to the press itself
	it('opens the device\'s picker from its own press, for pictures', async () => {
		const wrapper = mount(SharePhotoButton)
		const input = wrapper.find('input[type="file"]')
		const click = vi.spyOn(input.element, 'click')

		await wrapper.find('button').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(input.attributes('accept')).toBe('image/*')
		expect(input.attributes('multiple')).toBeDefined()
	})

	it('asks for the composer with what was picked', async () => {
		const listener = vi.fn()
		eventBus.on(COMPOSE_MEDIA, listener)
		const beach = new File(['x'], 'beach.png', { type: 'image/png' })

		await pick(mount(SharePhotoButton), [beach])

		expect(listener).toHaveBeenCalledWith({ files: [beach] })
	})

	it('asks for nothing when the picker was closed without a choice', async () => {
		const listener = vi.fn()
		eventBus.on(COMPOSE_MEDIA, listener)

		await pick(mount(SharePhotoButton), [])

		expect(listener).not.toHaveBeenCalled()
	})

	it('keeps the picker itself out of the keyboard\'s way and the accessibility tree', () => {
		const input = mount(SharePhotoButton).find('input[type="file"]')

		expect(input.attributes('tabindex')).toBe('-1')
		expect(input.attributes('aria-hidden')).toBe('true')
	})
})
