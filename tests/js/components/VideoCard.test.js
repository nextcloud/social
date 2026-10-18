/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'

import VideoCard, { PREVIEW_AFTER } from '../../../src/components/VideoCard.vue'
import { progressOf } from '../../../src/services/videoProgress.js'

vi.mock('../../../src/services/videoProgress.js', () => ({
	progressOf: vi.fn(() => null),
	isUnfinished: vi.fn(() => false),
}))

const ACCOUNT = { id: '9', acct: 'films@tube.example', username: 'films', display_name: 'Films' }

/**
 * @param {object} card what the video card says
 * @param {object} [extra] the rest of the status
 * @return {object} a video post
 */
function status(card = {}, extra = {}) {
	return {
		id: '1',
		account: ACCOUNT,
		created_at: '2026-10-13T12:00:00.000Z',
		sensitive: false,
		media_attachments: [{ id: 'm', type: 'video', url: 'https://cloud.example/media/v.mp4' }],
		video_card: {
			title: 'Building a boat',
			duration: 754,
			thumbnail: 'https://cloud.example/media/poster.jpeg',
			views: 1234,
			live: false,
			category: null,
			published: '2026-10-13T12:00:00.000Z',
			...card,
		},
		...extra,
	}
}

/**
 * @param {object} entry the timeline entry
 * @param {object} [props] other props
 * @return {object} the mounted card
 */
function mountCard(entry = status(), props = {}) {
	return mount(VideoCard, {
		props: { entry, ...props },
		global: { stubs: { RouterLink: RouterLinkStub, ActorAvatar: true } },
	})
}

/** @param {object} matches what the window answers to each query */
function setMedia(matches) {
	window.matchMedia = /** @type {any} */ ((query) => ({ matches: matches[query] === true, addEventListener() {}, removeEventListener() {} }))
}

const desk = { '(hover: hover) and (pointer: fine)': true }

describe('a video card', () => {
	const originalMatchMedia = window.matchMedia

	beforeEach(() => {
		vi.useFakeTimers({ now: new Date('2026-10-16T12:00:00Z') })
		setMedia(desk)
	})

	afterEach(() => {
		vi.useRealTimers()
		window.matchMedia = originalMatchMedia
		vi.mocked(progressOf).mockReturnValue(null)
	})

	describe('the picture', () => {
		it('carries the running time', () => {
			const wrapper = mountCard()
			expect(wrapper.find('.video-card__badge').text()).toBe('12:34')
			expect(wrapper.find('img').attributes('src')).toBe('https://cloud.example/media/poster.jpeg')
		})

		it('writes hours from an hour on', () => {
			expect(mountCard(status({ duration: 3725 })).find('.video-card__badge').text()).toBe('1:02:05')
		})

		it('says Live instead of a running time for a broadcast', () => {
			const badge = mountCard(status({ live: true, duration: 30 })).find('.video-card__badge')
			expect(badge.text()).toBe('Live')
			expect(badge.classes()).toContain('video-card__badge--live')
		})

		it('carries no badge when the running time is not known', () => {
			expect(mountCard(status({ duration: null })).find('.video-card__badge').exists()).toBe(false)
		})

		it('draws a placeholder when there is no picture', () => {
			const wrapper = mountCard(status({ thumbnail: null }))
			expect(wrapper.find('img').exists()).toBe(false)
			expect(wrapper.find('.video-card__nothumb').exists()).toBe(true)
		})

		it('veils a sensitive picture', () => {
			expect(mountCard(status({}, { sensitive: true })).find('img').classes()).toContain('video-card__image--veiled')
		})
	})

	describe('the words', () => {
		it('says the title, the channel, the views and the age', () => {
			const wrapper = mountCard()
			expect(wrapper.find('.video-card__title').text()).toBe('Building a boat')
			expect(wrapper.find('.video-card__channel').text()).toBe('Films')
			expect(wrapper.find('.video-card__facts').text()).toBe('1.2K views · 3 days ago')
		})

		it('leaves the views out when nobody counted them', () => {
			expect(mountCard(status({ views: null })).find('.video-card__facts').text()).toBe('3 days ago')
		})

		it('says one view in the singular', () => {
			expect(mountCard(status({ views: 1 })).find('.video-card__facts').text()).toBe('1 view · 3 days ago')
		})

		it('names a video without words after its channel', () => {
			expect(mountCard(status({ title: '' })).find('.video-card__title').text()).toBe('Video by Films')
		})

		it('tells a screen reader all of it in one name', () => {
			expect(mountCard().find('a').attributes('aria-label')).toBe('Building a boat, Films, 13 minutes, 1.2K views · 3 days ago')
		})

		it('draws a boost as the video that was boosted', () => {
			const wrapper = mountCard({ id: 'b', account: { acct: 'bob' }, reblog: status(), video_card: null })
			expect(wrapper.find('.video-card__title').text()).toBe('Building a boat')
			expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'single-post', params: { account: 'films@tube.example', id: '1' } })
		})
	})

	it('opens the video page, or where it was told to', () => {
		expect(mountCard().findComponent(RouterLinkStub).props('to')).toEqual({ name: 'single-post', params: { account: 'films@tube.example', id: '1' } })
		const to = { name: 'shorts', query: { start: '1' } }
		expect(mountCard(status(), { to, upright: true }).findComponent(RouterLinkStub).props('to')).toEqual(to)
	})

	describe('the progress bar', () => {
		it('is not drawn for a video the reader never started', () => {
			expect(mountCard().find('.video-card__progress').exists()).toBe(false)
		})

		it('shows how far the reader got', () => {
			vi.mocked(progressOf).mockReturnValue({ position: 30, duration: 120, fraction: 0.25, updatedAt: 1 })
			expect(mountCard().find('.video-card__progress-done').attributes('style')).toContain('inline-size: 25%')
		})
	})

	describe('the preview under the pointer', () => {
		it('starts after a rest and stops on the way out', async () => {
			const wrapper = mountCard()
			await wrapper.find('a').trigger('mouseenter')
			vi.advanceTimersByTime(PREVIEW_AFTER - 1)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('video').exists()).toBe(false)

			vi.advanceTimersByTime(1)
			await wrapper.vm.$nextTick()
			const video = wrapper.find('video')
			expect(video.attributes('src')).toBe('https://cloud.example/media/v.mp4')
			expect(video.element.muted).toBe(true)

			await wrapper.find('a').trigger('mouseleave')
			expect(wrapper.find('video').exists()).toBe(false)
		})

		it('does not start when the pointer leaves first', async () => {
			const wrapper = mountCard()
			await wrapper.find('a').trigger('mouseenter')
			await wrapper.find('a').trigger('mouseleave')
			vi.advanceTimersByTime(PREVIEW_AFTER * 2)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('video').exists()).toBe(false)
		})

		it.each([
			['on a touch screen', {}],
			['for somebody who asked for less motion', { ...desk, '(prefers-reduced-motion: reduce)': true }],
		])('does not start %s', async (unused, matches) => {
			setMedia(matches)
			const wrapper = mountCard()
			await wrapper.find('a').trigger('mouseenter')
			vi.advanceTimersByTime(PREVIEW_AFTER)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('video').exists()).toBe(false)
		})

		it.each([
			['a live broadcast', status({ live: true })],
			['something sensitive', status({}, { sensitive: true })],
			['a video with no file here', status({}, { media_attachments: [] })],
		])('does not start for %s', async (unused, entry) => {
			const wrapper = mountCard(entry)
			await wrapper.find('a').trigger('mouseenter')
			vi.advanceTimersByTime(PREVIEW_AFTER)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('video').exists()).toBe(false)
		})
	})

	/** A row of Up next: the picture beside the words, and no face. */
	it('draws a compact row with the title, the channel and the facts, and no face', () => {
		const wrapper = mountCard(status(), { compact: true })

		expect(wrapper.classes()).toContain('video-card--compact')
		expect(wrapper.find('.video-card__avatar').exists()).toBe(false)
		expect(wrapper.find('.video-card__title').text()).toBe('Building a boat')
		expect(wrapper.find('.video-card__channel').text()).toBe('Films')
		expect(wrapper.find('.video-card__badge').text()).toBe('12:34')
	})
})
