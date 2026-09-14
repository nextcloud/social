/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ZoomableImage from '../../../src/components/ZoomableImage.vue'

/**
 * jsdom lays nothing out, so a frame has no size of its own and clamping would
 * have nothing to clamp against. The frame is given one, the way the modal
 * gives it one in a browser.
 *
 * @param {object} wrapper the mounted component
 * @param {number} width the frame's width
 * @param {number} height its height
 */
function giveFrameASize(wrapper, width = 800, height = 600) {
	const frame = wrapper.vm.$refs.frame
	Object.defineProperty(frame, 'clientWidth', { value: width, configurable: true })
	Object.defineProperty(frame, 'clientHeight', { value: height, configurable: true })
}

/**
 * Sends a real pointer event at the frame.
 *
 * `trigger()` cannot be used for these: it builds the event and then assigns
 * the extra properties, and `clientX` on a MouseEvent has only a getter. The
 * coordinates have to go through the constructor, and `pointerId` — which is
 * not part of MouseEventInit — is defined on the event afterwards.
 *
 * @param {object} wrapper the mounted component
 * @param {string} type the event name
 * @param {object} init clientX, clientY and pointerId
 * @return {Promise<void>} once Vue has caught up
 */
async function send(wrapper, type, { clientX = 0, clientY = 0, pointerId = 1 } = {}) {
	const event = new MouseEvent(type, { clientX, clientY, bubbles: true })
	Object.defineProperty(event, 'pointerId', { value: pointerId })

	wrapper.vm.$refs.frame.dispatchEvent(event)
	await wrapper.vm.$nextTick()
}

/**
 * @return {object} a mounted viewer showing one picture
 */
function mountImage() {
	return mount(ZoomableImage, {
		props: { src: 'https://example.invalid/a.png', alt: 'a picture' },
	})
}

describe('ZoomableImage', () => {
	it('shows the picture at its own size to begin with', () => {
		const wrapper = mountImage()

		expect(wrapper.vm.scale).toBe(1)
		expect(wrapper.find('.zoomable__image').attributes('style')).toContain('scale(1)')
	})

	it('zooms in and out by the controls', async () => {
		const wrapper = mountImage()

		await wrapper.find('[aria-label="Zoom in"]').trigger('click')
		expect(wrapper.vm.scale).toBeGreaterThan(1)

		await wrapper.find('[aria-label="Zoom out"]').trigger('click')
		expect(wrapper.vm.scale).toBe(1)
	})

	it('never zooms further out than the picture itself', () => {
		const wrapper = mountImage()

		wrapper.vm.zoomBy(0.01)

		expect(wrapper.vm.scale).toBe(1)
	})

	it('has a ceiling, so a picture cannot be zoomed into nothing', () => {
		const wrapper = mountImage()

		for (let i = 0; i < 50; i++) {
			wrapper.vm.zoomBy(2)
		}

		expect(wrapper.vm.scale).toBe(5)
	})

	it('goes back to the start on a second double press', async () => {
		const wrapper = mountImage()

		await wrapper.trigger('dblclick')
		expect(wrapper.vm.scale).toBeGreaterThan(1)

		await wrapper.trigger('dblclick')
		expect(wrapper.vm.scale).toBe(1)
	})

	it('forgets the zoom when another picture is shown', async () => {
		const wrapper = mountImage()
		wrapper.vm.setScale(3)

		await wrapper.setProps({ src: 'https://example.invalid/b.png' })

		expect(wrapper.vm.scale).toBe(1)
		expect(wrapper.vm.offsetX).toBe(0)
	})

	describe('swiping', () => {
		it('pages back on a swipe to the right', async () => {
			const wrapper = mountImage()

			await send(wrapper, 'pointerdown', { clientX: 200 })
			await send(wrapper, 'pointerup', { clientX: 300 })

			expect(wrapper.emitted('previous')).toHaveLength(1)
			expect(wrapper.emitted('next')).toBeUndefined()
		})

		it('pages on with a swipe to the left', async () => {
			const wrapper = mountImage()

			await send(wrapper, 'pointerdown', { clientX: 300 })
			await send(wrapper, 'pointerup', { clientX: 200 })

			expect(wrapper.emitted('next')).toHaveLength(1)
		})

		// a press that wandered a few pixels is a press, not a swipe
		it('ignores a short drag', async () => {
			const wrapper = mountImage()

			await send(wrapper, 'pointerdown', { clientX: 200 })
			await send(wrapper, 'pointerup', { clientX: 220 })

			expect(wrapper.emitted('next')).toBeUndefined()
			expect(wrapper.emitted('previous')).toBeUndefined()
		})

		// a vertical drag on a picture is a page scroll that started here
		it('ignores a drag that went mostly down', async () => {
			const wrapper = mountImage()

			await send(wrapper, 'pointerdown', { clientX: 200, clientY: 100 })
			await send(wrapper, 'pointerup', { clientX: 270, clientY: 400 })

			expect(wrapper.emitted('next')).toBeUndefined()
		})

		// once zoomed, a drag moves the picture: swiping away from it would
		// take the reader off the thing they just zoomed into
		it('does not page a zoomed picture', async () => {
			const wrapper = mountImage()
			giveFrameASize(wrapper)
			wrapper.vm.setScale(3)

			await send(wrapper, 'pointerdown', { clientX: 300 })
			await send(wrapper, 'pointerup', { clientX: 100 })

			expect(wrapper.emitted('next')).toBeUndefined()
		})
	})

	describe('panning', () => {
		it('moves a zoomed picture with the pointer', async () => {
			const wrapper = mountImage()
			giveFrameASize(wrapper)
			wrapper.vm.setScale(2)

			await send(wrapper, 'pointerdown', { clientX: 400, clientY: 300 })
			await send(wrapper, 'pointermove', { clientX: 450, clientY: 330 })

			expect(wrapper.vm.offsetX).toBe(50)
			expect(wrapper.vm.offsetY).toBe(30)
		})

		it('does not move a picture that is not zoomed', async () => {
			const wrapper = mountImage()
			giveFrameASize(wrapper)

			await send(wrapper, 'pointerdown', { clientX: 400, clientY: 300 })
			await send(wrapper, 'pointermove', { clientX: 450, clientY: 330 })

			expect(wrapper.vm.offsetX).toBe(0)
		})

		// without this the picture can be pushed off the screen entirely and
		// the reader is left with an empty lightbox
		it('keeps the picture over its frame', async () => {
			const wrapper = mountImage()
			giveFrameASize(wrapper, 800, 600)
			wrapper.vm.setScale(2)

			await send(wrapper, 'pointerdown', { clientX: 0, clientY: 0 })
			await send(wrapper, 'pointermove', { clientX: 99999, clientY: 99999 })

			// half of what the zoom added, in each direction
			expect(wrapper.vm.offsetX).toBe(400)
			expect(wrapper.vm.offsetY).toBe(300)
		})

		it('recentres when the zoom comes back to the start', () => {
			const wrapper = mountImage()
			giveFrameASize(wrapper)
			wrapper.vm.setScale(3)
			wrapper.vm.offsetX = 120

			wrapper.vm.setScale(1)

			expect(wrapper.vm.offsetX).toBe(0)
			expect(wrapper.vm.offsetY).toBe(0)
		})
	})
})
