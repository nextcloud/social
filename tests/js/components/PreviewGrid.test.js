/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PreviewGrid from '../../../src/components/Composer/PreviewGrid.vue'
import PreviewGridItem from '../../../src/components/Composer/PreviewGridItem.vue'

function media(id) {
	return {
		id,
		type: 'image',
		url: `https://cloud.example.org/media/${id}.png`,
		preview_url: `https://cloud.example.org/media/${id}-small.png`,
		description: '',
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { small: { width: 4, height: 3 } },
	}
}

const file = (name) => new File(['x'], name, { type: 'image/png' })

function mountGrid(miniatures, upload = {}) {
	return mount(PreviewGrid, {
		props: { uploading: false, uploadProgress: 0, ...upload, miniatures },
	})
}

describe('PreviewGrid', () => {
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

	it('renders nothing without attachments', () => {
		const wrapper = mountGrid({})
		expect(wrapper.findAllComponents(PreviewGridItem)).toHaveLength(0)
		expect(wrapper.find('.preview-grid').exists()).toBe(true)
	})

	it('renders one item per attachment, keyed by the local preview URL', () => {
		const miniatures = {
			'blob:one': { file: file('one.png'), data: media('m1') },
			'blob:two': { file: file('two.png'), data: media('m2') },
		}
		const wrapper = mountGrid(miniatures)

		const items = wrapper.findAllComponents(PreviewGridItem)
		expect(items).toHaveLength(2)
		expect(items[0].props()).toEqual({ preview: miniatures['blob:one'], randomKey: 'blob:one' })
		expect(items[1].props()).toEqual({ preview: miniatures['blob:two'], randomKey: 'blob:two' })
	})

	it('shows finished uploads as images and pending ones as a spinner', () => {
		const wrapper = mountGrid({
			'blob:done': { file: file('done.png'), data: media('m1') },
			'blob:pending': { file: file('pending.png'), data: null },
		})

		const [done, pending] = wrapper.findAllComponents(PreviewGridItem)
		expect(done.find('img').attributes('src')).toBe('https://cloud.example.org/media/m1-small.png')
		expect(pending.find('img').exists()).toBe(false)
		expect(pending.find('.loading-icon').exists()).toBe(true)
	})

	it('shows how far an upload has got while one is running', () => {
		// the progress block sat behind v-if="false", and the fraction it
		// would have shown was the hard-coded 0.4 the composer passed
		expect(mountGrid({}).find('.upload-progress').exists()).toBe(false)

		const wrapper = mountGrid({}, { uploading: true, uploadProgress: 0.25 })
		expect(wrapper.find('.upload-progress').exists()).toBe(true)
		expect(wrapper.find('.upload-progress__tracker').attributes('style')).toBe('width: 25%;')
	})

	it('names what the bar is working on, and says how far it has got out loud', () => {
		// attaching a file the reader already has is not an upload, and a
		// moving bar tells a screen reader nothing on its own
		const wrapper = mountGrid({}, { uploading: true, uploadProgress: 0.5, progressLabel: 'Attaching from Files…' })

		const bar = wrapper.find('[role="progressbar"]')
		expect(bar.text()).toContain('Attaching from Files…')
		expect(bar.attributes('aria-valuenow')).toBe('50')
		expect(bar.attributes('aria-label')).toBe('Attaching from Files…')
	})

	it('falls back to the upload wording when no label was given', () => {
		const wrapper = mountGrid({}, { uploading: true, uploadProgress: 0 })
		expect(wrapper.find('[role="progressbar"]').text()).toContain('Uploading…')
	})

	it('gives a single picture the room a post built around it needs', async () => {
		const wrapper = mountGrid({ 'blob:one': { file: file('one.png'), data: media('m1') } })
		expect(wrapper.find('.preview-grid').classes()).toContain('preview-grid--single')

		await wrapper.setProps({
			miniatures: {
				'blob:one': { file: file('one.png'), data: media('m1') },
				'blob:two': { file: file('two.png'), data: media('m2') },
			},
		})
		expect(wrapper.find('.preview-grid').classes()).not.toContain('preview-grid--single')
	})

	it('passes a description on to be saved when an item commits one', async () => {
		const wrapper = mountGrid({ 'blob:one': { file: file('one.png'), data: media('m1') } })

		const field = wrapper.find('.preview-item__description')
		field.element.value = 'a beach'
		await field.trigger('change')

		expect(wrapper.emitted('commitDescription')).toEqual([[{ key: 'blob:one', description: 'a beach' }]])
	})

	it('re-emits an item removal as "deleted" with the attachment key', async () => {
		const wrapper = mountGrid({
			'blob:one': { file: file('one.png'), data: media('m1') },
			'blob:two': { file: file('two.png'), data: media('m2') },
		})

		await wrapper.findAllComponents(PreviewGridItem)[1].find('button').trigger('click')

		expect(wrapper.emitted('deleted')).toEqual([['blob:two']])
	})

	it('drops the item once the parent removed the attachment', async () => {
		const wrapper = mountGrid({
			'blob:one': { file: file('one.png'), data: media('m1') },
			'blob:two': { file: file('two.png'), data: media('m2') },
		})
		await wrapper.setProps({ miniatures: { 'blob:two': { file: file('two.png'), data: media('m2') } } })

		const items = wrapper.findAllComponents(PreviewGridItem)
		expect(items).toHaveLength(1)
		expect(items[0].props('randomKey')).toBe('blob:two')
	})
})
