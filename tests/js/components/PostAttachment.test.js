/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PostAttachment from '../../../src/components/PostAttachment.vue'
import MediaAttachment from '../../../src/components/MediaAttachment.vue'
import GalleryCarousel from '../../../src/components/GalleryCarousel.vue'
import GalleryMedia from '../../../src/components/GalleryMedia.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from '../../../src/store/settings.js'

function attachment(index) {
	return {
		id: `a${index}`,
		type: 'image',
		url: `https://cloud.example.org/media/${index}.jpg`,
		preview_url: `https://cloud.example.org/media/${index}-small.jpg`,
		description: `Picture ${index}`,
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { small: { width: 4, height: 3 } },
	}
}

const attachments = (count) => Array.from({ length: count }, (_, index) => attachment(index + 1))

// The real NcModal teleports and traps focus; this stand-in exposes the
// navigation contract PostAttachment relies on.
const NcModalStub = {
	name: 'NcModal',
	props: {
		hasPrevious: { type: Boolean, default: false },
		hasNext: { type: Boolean, default: false },
		size: { type: String, default: '' },
	},
	emits: ['close', 'previous', 'next'],
	template: `<div class="modal-stub" :data-size="size">
		<button class="modal-stub__previous" :disabled="!hasPrevious" @click="$emit('previous')">previous</button>
		<button class="modal-stub__next" :disabled="!hasNext" @click="$emit('next')">next</button>
		<button class="modal-stub__close" @click="$emit('close')">close</button>
		<slot />
	</div>`,
}

function mountAttachments(items, props = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false })

	return mount(PostAttachment, {
		props: { attachments: items, ...props },
		global: {
			plugins: [pinia],
			stubs: { NcModal: NcModalStub },
		},
	})
}

const mountMediaFirst = (items) => mountAttachments(items, { mediaFirst: true })

const viewerImage = (wrapper) => wrapper.find('.attachment__viewer img')

// MediaAttachment's own root is also class "attachment", so select the tiles only
const tiles = (wrapper) => wrapper.findAll('.attachments-container > .attachment')

describe('PostAttachment', () => {
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

	it('renders a tile per attachment for up to four attachments', () => {
		const items = attachments(4)
		const wrapper = mountAttachments(items)

		const tiles = wrapper.findAllComponents(MediaAttachment)
		expect(tiles).toHaveLength(4)
		expect(tiles.map((tile) => tile.props('attachment'))).toEqual(items)
		expect(wrapper.find('.more-attachments').exists()).toBe(false)
	})

	it('collapses five or more attachments into three tiles and a "+" tile', () => {
		const items = attachments(5)
		const wrapper = mountAttachments(items)

		const tiles = wrapper.findAllComponents(MediaAttachment)
		expect(tiles.map((tile) => tile.props('attachment'))).toEqual(items.slice(0, 3))
		expect(wrapper.find('.more-attachments').text()).toBe('+')
	})

	it('keeps the viewer closed until a tile is clicked', () => {
		const wrapper = mountAttachments(attachments(2))
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
	})

	it('opens the full-size viewer on the clicked attachment with its description as alt text', async () => {
		const items = attachments(3)
		const wrapper = mountAttachments(items)

		await tiles(wrapper)[1].trigger('click')

		expect(wrapper.find('.modal-stub').attributes('data-size')).toBe('full')
		expect(viewerImage(wrapper).attributes('src')).toBe(items[1].url)
		expect(viewerImage(wrapper).attributes('alt')).toBe('Picture 2')
	})

	it('opens the fourth attachment from the "+" tile', async () => {
		const items = attachments(6)
		const wrapper = mountAttachments(items)

		await wrapper.find('.more-attachments').trigger('click')

		expect(viewerImage(wrapper).attributes('src')).toBe(items[3].url)
	})

	it('steps through the attachments with next and previous', async () => {
		const items = attachments(3)
		const wrapper = mountAttachments(items)
		await tiles(wrapper)[0].trigger('click')

		expect(wrapper.find('.modal-stub__previous').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.modal-stub__next').attributes('disabled')).toBeUndefined()

		await wrapper.find('.modal-stub__next').trigger('click')
		expect(viewerImage(wrapper).attributes('src')).toBe(items[1].url)
		expect(wrapper.find('.modal-stub__previous').attributes('disabled')).toBeUndefined()

		await wrapper.find('.modal-stub__next').trigger('click')
		expect(viewerImage(wrapper).attributes('src')).toBe(items[2].url)
		expect(wrapper.find('.modal-stub__next').attributes('disabled')).toBeDefined()

		await wrapper.find('.modal-stub__previous').trigger('click')
		expect(viewerImage(wrapper).attributes('src')).toBe(items[1].url)
	})

	it('disables both directions for a single attachment', async () => {
		const wrapper = mountAttachments(attachments(1))
		await tiles(wrapper)[0].trigger('click')
		expect(wrapper.find('.modal-stub__previous').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.modal-stub__next').attributes('disabled')).toBeDefined()
	})

	it('closes the viewer and reopens on the newly clicked attachment', async () => {
		const items = attachments(2)
		const wrapper = mountAttachments(items)
		await tiles(wrapper)[0].trigger('click')

		await wrapper.find('.modal-stub__close').trigger('click')
		expect(wrapper.find('.modal-stub').exists()).toBe(false)

		await tiles(wrapper)[1].trigger('click')
		expect(viewerImage(wrapper).attributes('src')).toBe(items[1].url)
	})
	describe('the media-first layout', () => {
		it('gives a lone picture the width of the post, in its own shape', () => {
			const items = [{ ...attachment(1), meta: { original: { width: 1600, height: 1200 } } }]
			const wrapper = mountMediaFirst(items)

			expect(wrapper.find('.gallery-mosaic--1').exists()).toBe(true)
			expect(wrapper.findComponent(GalleryCarousel).exists()).toBe(false)

			const frames = wrapper.findAllComponents(GalleryMedia)
			expect(frames).toHaveLength(1)
			expect(frames[0].props('ratio')).toBe(1600 / 1200)
			expect(frames[0].props('attachment')).toEqual(items[0])
		})

		it('sets a pair side by side, squared off so they are the same height', () => {
			const wrapper = mountMediaFirst(attachments(2))

			expect(wrapper.find('.gallery-mosaic--2').exists()).toBe(true)
			expect(wrapper.findComponent(GalleryCarousel).exists()).toBe(false)

			const frames = wrapper.findAllComponents(GalleryMedia)
			expect(frames).toHaveLength(2)
			expect(frames.map((frame) => frame.props('ratio'))).toEqual([1, 1])
		})

		it.each([3, 4, 5, 8])('pages through a set of %i rather than shrinking it into tiles', (count) => {
			const items = attachments(count)
			const wrapper = mountMediaFirst(items)

			const carousel = wrapper.findComponent(GalleryCarousel)
			expect(carousel.exists()).toBe(true)
			expect(carousel.props('attachments')).toEqual(items)
			expect(wrapper.find('.gallery-mosaic').exists()).toBe(false)
			// nothing is dropped the way the thumbnail grid drops the fifth
			expect(wrapper.findAllComponents(GalleryMedia)).toHaveLength(count)
			expect(wrapper.find('.more-attachments').exists()).toBe(false)
		})

		it('leaves the thumbnail grid alone where the text comes first', () => {
			const wrapper = mountAttachments(attachments(3))

			expect(wrapper.find('.attachments-container').exists()).toBe(true)
			expect(wrapper.findComponent(GalleryCarousel).exists()).toBe(false)
			expect(wrapper.find('.gallery-mosaic').exists()).toBe(false)
		})

		it('opens the viewer on the picture that was pressed', async () => {
			const items = attachments(2)
			const wrapper = mountMediaFirst(items)

			await wrapper.findAll('button.photo__open')[1].trigger('click')

			expect(viewerImage(wrapper).attributes('src')).toBe(items[1].url)
		})

		it('opens the viewer on the picture the carousel has on stage', async () => {
			const items = attachments(4)
			const wrapper = mountMediaFirst(items)

			wrapper.findComponent(GalleryCarousel).vm.$emit('open', 2)
			await wrapper.vm.$nextTick()

			expect(viewerImage(wrapper).attributes('src')).toBe(items[2].url)
		})
	})

	describe('alt text in the viewer', () => {
		it('shows the description under the picture it belongs to', async () => {
			const wrapper = mountAttachments(attachments(2))

			await tiles(wrapper)[0].trigger('click')

			expect(wrapper.find('.attachment__viewer-description').text()).toBe('Picture 1')
		})

		it('still sets an alt attribute for a picture nobody described', async () => {
			// `description` is null on the wire for media without alt text, and
			// Vue drops a null attribute: the viewer image had no alt at all
			const items = [{ ...attachment(1), description: null }]
			const wrapper = mountAttachments(items)

			await tiles(wrapper)[0].trigger('click')

			expect(viewerImage(wrapper).attributes('alt')).toBe('')
			expect(wrapper.find('.attachment__viewer-description').exists()).toBe(false)
		})
	})
})
