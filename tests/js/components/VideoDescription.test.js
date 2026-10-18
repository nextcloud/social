/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import VideoDescription from '../../../src/components/VideoDescription.vue'

const CHAPTERS = [
	{ start: 0, title: 'Before' },
	{ start: 125, title: 'The stairwell' },
	{ start: 3725, title: 'After' },
]

/**
 * @param {object} [video] what the post said about the video
 * @param {object} [extra] the rest of the status
 * @return {object} a video post
 */
function status(video = {}, extra = {}) {
	return {
		id: '1',
		content: '<p>A film about <a href="/tags/stairs">#stairs</a></p>',
		created_at: '2026-10-13T12:00:00.000Z',
		spoiler_text: '',
		video: { title: 'Stairwells', ...video },
		video_card: { title: 'Stairwells', views: 1234, published: '2026-10-13T12:00:00.000Z' },
		...extra,
	}
}

/**
 * @param {object} theStatus the post
 * @return {object} the mounted box
 */
function mountBox(theStatus = status()) {
	return mount(VideoDescription, {
		props: { status: theStatus },
		global: {
			stubs: {
				MessageContent: { props: ['item'], template: '<div class="message-stub" v-html="item.content" />' },
				PostDetails: { template: '<p class="details-stub" />' },
				PostReactedBy: { template: '<p class="reacted-stub" />' },
			},
		},
	})
}

const toggle = (wrapper) => wrapper.find('.video-description__toggle')
const facts = (wrapper) => wrapper.findAll('.video-description__facts > *').map((part) => part.text())

/** The box under a video: closed to a few lines, everything once opened. */
describe('the description of a video', () => {
	beforeEach(() => {
		vi.useFakeTimers({ now: new Date('2026-10-16T12:00:00Z') })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	describe('closed', () => {
		it('says how many watched it and how long ago, as a video site does', () => {
			expect(facts(mountBox())).toEqual(['1.2K views', '·', '3 days ago'])
		})

		it('leaves the views out where nobody counted them', () => {
			const wrapper = mountBox(status({}, { video_card: { title: 'x', views: null, published: '2026-10-13T12:00:00.000Z' } }))

			expect(facts(wrapper)).toEqual(['3 days ago'])
		})

		it('shows the words, clamped, and nothing else yet', () => {
			const wrapper = mountBox(status({ category: 'Art', chapters: CHAPTERS, support: 'Support us' }))

			expect(wrapper.find('.video-description__text').text()).toBe('A film about #stairs')
			expect(wrapper.classes()).not.toContain('video-description--open')
			expect(wrapper.find('.video-description__chips').exists()).toBe(false)
			expect(wrapper.find('.video-description__chapters').exists()).toBe(false)
			expect(wrapper.find('.details-stub').exists()).toBe(false)
			expect(toggle(wrapper).text()).toBe('…more')
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
		})

		/** A content warning is said first, and what it warns about once the reader opens the box. */
		it('says the warning and keeps back what it warns about', async () => {
			const wrapper = mountBox(status({}, { spoiler_text: 'Heights' }))

			expect(wrapper.find('.video-description__warning').text()).toBe('Heights')
			expect(wrapper.find('.video-description__text').exists()).toBe(false)
			await toggle(wrapper).trigger('click')
			expect(wrapper.find('.video-description__text').exists()).toBe(true)
		})
	})

	describe('opened', () => {
		it('opens from its button and closes from it again', async () => {
			const wrapper = mountBox()

			await toggle(wrapper).trigger('click')
			expect(wrapper.classes()).toContain('video-description--open')
			expect(toggle(wrapper).attributes('aria-expanded')).toBe('true')
			expect(toggle(wrapper).attributes('aria-controls')).toBe(wrapper.find('.video-description__body').attributes('id'))
			expect(toggle(wrapper).text()).toBe('Show less')

			await toggle(wrapper).trigger('click')
			expect(wrapper.classes()).not.toContain('video-description--open')
		})

		it('opens from a press anywhere on it, but not from a link in it', async () => {
			const wrapper = mountBox()

			await wrapper.find('.video-description__text a').trigger('click')
			expect(wrapper.classes()).not.toContain('video-description--open')

			await wrapper.find('.video-description__facts').trigger('click')
			expect(wrapper.classes()).toContain('video-description--open')

			// open, a press on the box leaves it open
			await wrapper.find('.video-description__facts').trigger('click')
			expect(wrapper.classes()).toContain('video-description--open')
		})

		it('gives the exact count and the full date', async () => {
			const wrapper = mountBox()
			await toggle(wrapper).trigger('click')

			expect(facts(wrapper)[0]).toBe('1234 views')
			expect(wrapper.find('time').attributes('datetime')).toBe('2026-10-13T12:00:00.000Z')
		})

		it('shows the category, language and licence as chips, each named for a screen reader', async () => {
			const wrapper = mountBox(status({ category: 'Art', language: 'German', licence: 'CC BY-SA' }))
			await toggle(wrapper).trigger('click')

			expect(wrapper.findAll('.video-description__chip').map((chip) => chip.text()))
				.toEqual(['Category: Art', 'Language: German', 'Licence: CC BY-SA'])
			expect(wrapper.find('.video-description__chip .hidden-visually').text()).toBe('Category:')
		})

		it('lists the chapters, each a moment to press, which asks the player to go there', async () => {
			const wrapper = mountBox(status({ chapters: CHAPTERS }))
			await toggle(wrapper).trigger('click')

			const chapters = wrapper.findAll('.video-description__chapter')
			expect(chapters.map((chapter) => [
				chapter.find('.video-description__chapter-time').text(),
				chapter.find('.video-description__chapter-title').text(),
			])).toEqual([['0:00', 'Before'], ['2:05', 'The stairwell'], ['1:02:05', 'After']])

			await chapters[1].trigger('click')
			expect(wrapper.emitted('seek')).toEqual([[125]])
			expect(wrapper.classes()).toContain('video-description--open')
		})

		it('says nothing of chapters a video does not have', async () => {
			const wrapper = mountBox(status({ chapters: 'soon' }))
			await toggle(wrapper).trigger('click')

			expect(wrapper.find('.video-description__chapters').exists()).toBe(false)
		})

		it('carries the support line, the request not to download, and the fine print', async () => {
			const wrapper = mountBox(status({ support: 'Paid for by the people who watch it', download: false }))
			await toggle(wrapper).trigger('click')

			expect(wrapper.find('.video-description__support').text()).toBe('Paid for by the people who watch it')
			expect(wrapper.find('.video-description__note').text()).toContain('not be downloaded')
			expect(wrapper.find('.details-stub').exists()).toBe(true)
			expect(wrapper.find('.reacted-stub').exists()).toBe(true)
		})
	})

	it('starts closed again for the next video', async () => {
		const wrapper = mountBox()
		await toggle(wrapper).trigger('click')

		await wrapper.setProps({ status: status({}, { id: '2' }) })
		expect(wrapper.classes()).not.toContain('video-description--open')
	})
})
