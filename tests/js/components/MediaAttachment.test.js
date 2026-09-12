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

	// video: a federated one is streamed through this instance, and its still
	// is the only reason a page of them can be scrolled at all

	/**
	 * A PeerTube video's preview is a thumbnail mirrored here, so it is drawn
	 * before anything is played -- and with a frame already on screen there is
	 * nothing to fetch until somebody asks to watch.
	 */
	it('shows a federated video its own still and fetches nothing until asked', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					id: '9',
					type: 'video',
					url: 'https://cloud.example.org/media/stream/9',
					preview_url: 'https://cloud.example.org/media/thumb.jpg',
					description: 'A talk about federation',
				},
			},
		})

		const video = wrapper.find('video')
		expect(video.attributes('src')).toBe('https://cloud.example.org/media/stream/9')
		expect(video.attributes('poster')).toBe('https://cloud.example.org/media/thumb.jpg')
		expect(video.attributes('preload')).toBe('none')
	})

	/**
	 * An upload here has `preview_url` pointing at the file itself, which is no
	 * use as a poster: a browser handed a video for one downloads it to find a
	 * frame, which is the whole thing a poster avoids.
	 */
	it('gives a local video no poster, since its preview is the video', () => {
		const wrapper = mount(MediaAttachment, {
			props: {
				attachment: {
					id: '9',
					type: 'video',
					url: 'https://cloud.example.org/media/abc.mp4',
					preview_url: 'https://cloud.example.org/media/abc.mp4',
					description: '',
				},
			},
		})

		const video = wrapper.find('video')
		expect(video.attributes('poster')).toBeUndefined()
		expect(video.attributes('preload')).toBe('metadata')
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

	it('does not spin for an attachment the server has no preview for', async () => {
		// Vue drops a null `src`, so neither @load nor @error is guaranteed to
		// fire — on Firefox neither does — and the spinner stayed up for good
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, preview_url: null, description: '' } } })

		expect(wrapper.find('.loading-icon').exists()).toBe(false)
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.attachment__failed').attributes('aria-label')).toBe('No preview available')
	})

	it('names an undescribed-preview attachment by its description', () => {
		const wrapper = mount(MediaAttachment, { props: { attachment: { ...attachment, preview_url: '', description: 'a cat' } } })

		expect(wrapper.find('.attachment__failed').attributes('aria-label')).toBe('No preview available: a cat')
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
