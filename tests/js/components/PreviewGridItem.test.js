/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PreviewGridItem from '../../../src/components/Composer/PreviewGridItem.vue'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'

const media = {
	id: 'media-1',
	type: 'image',
	url: 'https://cloud.example.org/media/original.png',
	preview_url: 'https://cloud.example.org/media/small.png',
	description: 'Screenshot',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

const file = new File(['x'], 'screenshot.png', { type: 'image/png' })

const mountItem = (preview) => mount(PreviewGridItem, {
	props: { preview, randomKey: 'blob:preview-1' },
})

describe('PreviewGridItem', () => {
	let getContext

	beforeEach(() => {
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			createImageData: (width, height) => ({ data: new Uint8ClampedArray(width * height * 4) }),
			putImageData: () => {},
		})
	})

	afterEach(() => {
		getContext.mockRestore()
	})

	it('shows the uploaded media through MediaAttachment', () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.findComponent(MediaAttachment).props('attachment')).toEqual(media)
		expect(wrapper.find('img.attachment__preview').attributes('src')).toBe(media.preview_url)
	})

	it('shows a pending state while the upload has not finished', () => {
		const wrapper = mountItem({ file, data: null })
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.find('.loading-icon').exists()).toBe(true)
	})

	it('offers a delete action', () => {
		const wrapper = mountItem({ file, data: media })
		expect(wrapper.find('.preview-item__actions button').text()).toBe('Delete')
	})

	it('emits delete with its key when the delete action is used', async () => {
		const wrapper = mountItem({ file, data: media })
		await wrapper.find('.preview-item__actions button').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['blob:preview-1']])
	})

	it('can be removed while the upload is still pending', async () => {
		const wrapper = mountItem({ file, data: null })
		await wrapper.find('.preview-item__actions button').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['blob:preview-1']])
	})
})
