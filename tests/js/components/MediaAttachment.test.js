/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'

const attachment = {
	id: '7',
	type: 'image',
	url: 'https://cloud.example.org/media/original.jpg',
	preview_url: 'https://cloud.example.org/media/small.jpg',
	description: 'A cat on a sofa',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

describe('MediaAttachment', () => {
	let context
	let getContext

	beforeEach(() => {
		// jsdom has no 2D canvas: hand out a stub context and record what is drawn
		context = {
			createImageData: vi.fn((width, height) => ({ data: new Uint8ClampedArray(width * height * 4) })),
			putImageData: vi.fn(),
		}
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context)
	})

	afterEach(() => {
		getContext.mockRestore()
	})

	it('renders the small preview image', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		expect(wrapper.find('img.attachment__preview').attributes('src')).toBe(attachment.preview_url)
	})

	it('paints the blurhash placeholder at the small preview dimensions', () => {
		mount(MediaAttachment, { props: { attachment } })

		expect(getContext).toHaveBeenCalledWith('2d')
		expect(context.createImageData).toHaveBeenCalledWith(4, 3)
		const imageData = context.createImageData.mock.results[0].value
		expect(imageData.data).toHaveLength(4 * 3 * 4)
		expect(imageData.data.some((channel) => channel !== 0)).toBe(true)
		expect(context.putImageData).toHaveBeenCalledWith(imageData, 0, 0)
	})

	it('crossfades the image out of its own blurhash once it has loaded', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		expect(wrapper.find('canvas.attachment__blurhash').classes())
			.not.toContain('attachment__blurhash--hidden')
		expect(wrapper.find('img.attachment__preview').classes()).not.toContain('attachment__preview--shown')
		expect(wrapper.find('.loading-icon').exists()).toBe(true)

		await wrapper.find('img').trigger('load')

		// the blurhash stays mounted and fades under the image; removing it
		// in the same frame is what made the swap visible
		expect(wrapper.find('canvas.attachment__blurhash').classes()).toContain('attachment__blurhash--hidden')
		expect(wrapper.find('img.attachment__preview').classes()).toContain('attachment__preview--shown')
		expect(wrapper.find('.loading-icon').exists()).toBe(false)
	})

	it('shows only a spinner while the attachment is still uploading', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: null } })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.loading-icon').exists()).toBe(true)
		expect(getContext).not.toHaveBeenCalled()
	})

	it('skips the blurhash when the preview size is unknown', () => {
		mount(MediaAttachment, { props: { attachment: { ...attachment, meta: { small: {} } } } })
		expect(getContext).not.toHaveBeenCalled()
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	it('repaints when the attachment arrives after an upload', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: null } })
		expect(context.createImageData).not.toHaveBeenCalled()

		await wrapper.setProps({ attachment: { ...attachment, meta: { small: { width: 2, height: 5 } } } })

		expect(context.createImageData).toHaveBeenCalledWith(2, 5)
		expect(wrapper.find('img').attributes('src')).toBe(attachment.preview_url)
	})

	it('stops spinning and says so when the preview cannot be loaded', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, description: '' } } })
		expect(wrapper.find('.loading-icon').exists()).toBe(true)

		// no @error meant previewLoaded stayed false forever, so federated
		// media that has gone away spun for the life of the page
		await wrapper.find('img').trigger('error')

		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').attributes('aria-label'))
			.toBe('Attachment could not be loaded')
	})

	it('names the attachment in the failure when the author described it', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, description: 'a cat' } } })
		await wrapper.find('img').trigger('error')

		expect(wrapper.find('.attachment__failed').attributes('aria-label'))
			.toBe('Attachment could not be loaded: a cat')
	})

	it('starts over when a new attachment replaces one that failed', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		await wrapper.find('img').trigger('error')
		expect(wrapper.find('img').exists()).toBe(false)

		await wrapper.setProps({ attachment: { ...attachment, id: 'other' } })
		expect(wrapper.find('img').exists()).toBe(true)
	})

	it('draws nothing rather than throwing for an attachment with no blurhash', () => {
		// CacheDocumentService sets the copy sizes before it knows GD could
		// read the image, so an unreadable upload arrives sized with an empty
		// hash — and decode('') throws
		for (const blurhash of ['', undefined, null, 'abc']) {
			expect(() => mount(MediaAttachment, { props: { attachment: { ...attachment, blurhash } } })).not.toThrow()
		}
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	it('draws nothing rather than throwing for a hash that does not decode', () => {
		expect(() => mount(MediaAttachment, {
			props: { attachment: { ...attachment, blurhash: '!!!!!!!!!!!!' } },
		})).not.toThrow()
		expect(context.putImageData).not.toHaveBeenCalled()
	})

	it('emits click so a parent can open the viewer', async () => {
		const wrapper = mount(MediaAttachment, { props: { attachment } })
		await wrapper.find('.attachment').trigger('click')
		// the component's own emit carries no payload (the native event is recorded separately)
		expect(wrapper.emitted('click')).toContainEqual([])
	})
})
