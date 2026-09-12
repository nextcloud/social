/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GalleryCarousel from '../../../src/components/GalleryCarousel.vue'
import GalleryMedia from '../../../src/components/GalleryMedia.vue'

function photo(index) {
	return {
		id: `a${index}`,
		type: 'image',
		url: `https://cloud.example.org/media/${index}.jpg`,
		preview_url: `https://cloud.example.org/media/${index}-small.jpg`,
		description: `Picture ${index}`,
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { original: { width: 1600, height: 1200 }, small: { width: 4, height: 3 } },
	}
}

const photos = (count) => Array.from({ length: count }, (_, index) => photo(index + 1))

const mountCarousel = (attachments) => mount(GalleryCarousel, { props: { attachments } })

const slides = (wrapper) => wrapper.findAll('.gallery__slide')
const current = (wrapper) => slides(wrapper).findIndex((slide) => slide.attributes('inert') === undefined)

describe('GalleryCarousel', () => {
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

	it('keeps every picture of the set mounted, one of them on stage', () => {
		const wrapper = mountCarousel(photos(8))

		expect(wrapper.findAllComponents(GalleryMedia)).toHaveLength(8)
		expect(slides(wrapper).filter((slide) => slide.attributes('aria-hidden') === 'false')).toHaveLength(1)
		expect(current(wrapper)).toBe(0)
	})

	it('takes the pictures off the stage out of reach of the keyboard', () => {
		const wrapper = mountCarousel(photos(3))
		const offStage = slides(wrapper).slice(1)

		expect(offStage.every((slide) => slide.attributes('inert') !== undefined)).toBe(true)
		expect(offStage.every((slide) => slide.attributes('aria-hidden') === 'true')).toBe(true)
	})

	it('gives every slide one shape, so the timeline does not move while paging', () => {
		const mixed = [
			{ ...photo(1), meta: { original: { width: 1000, height: 1000 } } },
			{ ...photo(2), meta: { original: { width: 3000, height: 1000 } } },
		]
		const wrapper = mountCarousel(mixed)

		expect(wrapper.find('.gallery__stage').attributes('style')).toContain('aspect-ratio: 1')
		expect(wrapper.findAllComponents(GalleryMedia).map((media) => media.props('ratio'))).toEqual([1, 1])
		// a picture of another shape is fitted into the stage, never cropped to it
		expect(wrapper.findAllComponents(GalleryMedia).every((media) => media.props('fit') === 'contain')).toBe(true)
	})

	describe('paging with a pointer', () => {
		it('steps forwards and backwards through the set', async () => {
			const wrapper = mountCarousel(photos(4))

			await wrapper.find('.gallery__step--next').trigger('click')
			expect(current(wrapper)).toBe(1)

			await wrapper.find('.gallery__step--previous').trigger('click')
			expect(current(wrapper)).toBe(0)
		})

		it('wraps around rather than disabling the control the reader is on', async () => {
			const wrapper = mountCarousel(photos(3))

			await wrapper.find('.gallery__step--previous').trigger('click')
			expect(current(wrapper)).toBe(2)
			expect(wrapper.find('.gallery__step--previous').attributes('disabled')).toBeUndefined()

			await wrapper.find('.gallery__step--next').trigger('click')
			expect(current(wrapper)).toBe(0)
		})

		it('jumps straight to a picture from its dot', async () => {
			const wrapper = mountCarousel(photos(5))
			const dots = wrapper.findAll('.gallery__dot')

			expect(dots).toHaveLength(5)
			expect(dots[3].attributes('aria-label')).toBe('Show image 4')

			await dots[3].trigger('click')

			expect(current(wrapper)).toBe(3)
			expect(wrapper.findAll('.gallery__dot')[3].attributes('aria-current')).toBe('true')
			// the dot that is current is a longer bar as well as another colour
			expect(wrapper.findAll('.gallery__dot')[3].classes()).toContain('gallery__dot--current')
			expect(wrapper.findAll('.gallery__dot')[0].attributes('aria-current')).toBeUndefined()
		})

		it('opens the viewer on the picture that is on stage', async () => {
			const wrapper = mountCarousel(photos(3))
			await wrapper.find('.gallery__step--next').trigger('click')

			await wrapper.findAll('button.photo__open')[1].trigger('click')

			expect(wrapper.emitted('open')).toEqual([[1]])
		})
	})

	describe('paging with the keyboard', () => {
		it('pages with the arrow keys without leaving the gallery', async () => {
			const wrapper = mountCarousel(photos(4))
			const gallery = wrapper.find('.gallery')

			expect(gallery.attributes('tabindex')).toBe('0')

			await gallery.trigger('keydown', { key: 'ArrowRight' })
			expect(current(wrapper)).toBe(1)

			await gallery.trigger('keydown', { key: 'ArrowRight' })
			expect(current(wrapper)).toBe(2)

			await gallery.trigger('keydown', { key: 'ArrowLeft' })
			expect(current(wrapper)).toBe(1)
		})

		it('goes to the first and the last picture with Home and End', async () => {
			const wrapper = mountCarousel(photos(6))
			const gallery = wrapper.find('.gallery')

			await gallery.trigger('keydown', { key: 'End' })
			expect(current(wrapper)).toBe(5)

			await gallery.trigger('keydown', { key: 'Home' })
			expect(current(wrapper)).toBe(0)
		})

		it('wraps at both ends, so the keys never stop working', async () => {
			const wrapper = mountCarousel(photos(3))
			const gallery = wrapper.find('.gallery')

			await gallery.trigger('keydown', { key: 'ArrowLeft' })
			expect(current(wrapper)).toBe(2)

			await gallery.trigger('keydown', { key: 'ArrowRight' })
			expect(current(wrapper)).toBe(0)
		})

		it('leaves keys it does not page with to the page', async () => {
			const wrapper = mountCarousel(photos(3))

			await wrapper.find('.gallery').trigger('keydown', { key: 'j' })

			expect(current(wrapper)).toBe(0)
		})
	})

	describe('saying where the reader is', () => {
		it('spells out the position instead of leaving it to the colour of a dot', async () => {
			const wrapper = mountCarousel(photos(8))
			const counter = wrapper.find('.gallery__counter')

			expect(counter.attributes('aria-live')).toBe('polite')
			expect(counter.text()).toBe('1 of 8')

			await wrapper.find('.gallery__step--next').trigger('click')

			expect(wrapper.find('.gallery__counter').text()).toBe('2 of 8')
		})

		it('names the whole gallery and the controls that move it', () => {
			const wrapper = mountCarousel(photos(4))

			expect(wrapper.find('.gallery').attributes('aria-label')).toBe('Gallery of 4 images')
			expect(wrapper.find('.gallery__step--previous').attributes('aria-label')).toBe('Previous image')
			expect(wrapper.find('.gallery__step--next').attributes('aria-label')).toBe('Next image')
		})

		it('starts over when the post is given another set of pictures', async () => {
			const wrapper = mountCarousel(photos(4))
			await wrapper.find('.gallery__step--next').trigger('click')

			await wrapper.setProps({ attachments: photos(3) })

			expect(current(wrapper)).toBe(0)
		})
	})
})
