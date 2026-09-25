/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import VideoReels from '../../../src/views/VideoReels.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/** The observers each mount made, so a test can fire one by hand. */
let observers = []

function video(id, { attachments, content = '<p>a clip</p>', acct = 'alice@cloud.example' } = {}) {
	return {
		id,
		content,
		created_at: '2026-01-0' + id + 'T10:00:00Z',
		account: { acct, username: 'alice', display_name: 'Alice', avatar: 'https://cloud.example/a.png' },
		media_attachments: attachments ?? [
			{ id: id + '-1', type: 'video', url: 'https://cloud.example/v' + id + '.mp4', preview_url: '', description: '' },
		],
	}
}

async function mountReels(statuses = [video('1'), video('2')]) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useTimelineStore()
	store.fetchTimeline = vi.fn(async () => {
		// the first call fills the list, every later one says there is no more
		if (store.timeline.length === 0) {
			store.addToTimeline(statuses)

			return statuses
		}

		return []
	})

	const wrapper = mount(VideoReels, {
		global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: true } },
	})
	await flushPromises()

	return { wrapper, store }
}

describe('VideoReels', () => {
	beforeEach(() => {
		observers = []
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback) {
				this.callback = callback
				this.observed = []
				observers.push(this)
			}

			observe(el) {
				this.observed.push(el)
			}

			disconnect() {}
		})
		// jsdom has no media element; every reel calls these
		HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve())
		HTMLMediaElement.prototype.pause = vi.fn()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('asks for the videos timeline at the scope it was given', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		store.fetchTimeline = vi.fn(async () => [])

		mount(VideoReels, {
			props: { scope: 'federated' },
			global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: true } },
		})
		await flushPromises()

		expect(store.type).toBe('videos')
		expect(store.params.scope).toBe('federated')
	})

	/**
	 * A post with three videos on it is three things to watch, and a stack
	 * that showed only the first would hide two of them.
	 */
	it('makes one slide per video, not per post', async () => {
		const { wrapper } = await mountReels([
			video('1', {
				attachments: [
					{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.reel')).toHaveLength(2)
	})

	it('leaves the pictures on a mixed post out of the stack', async () => {
		const { wrapper } = await mountReels([
			video('1', {
				attachments: [
					{ id: 'a', type: 'image', url: 'https://cloud.example/a.jpg' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.reel')).toHaveLength(1)
		expect(wrapper.find('video').attributes('src')).toBe('https://cloud.example/b.mp4')
	})

	/** No browser autoplays with sound, and a page that made noise on open is one people close. */
	it('starts muted', async () => {
		const { wrapper } = await mountReels()

		expect(wrapper.vm.muted).toBe(true)
		expect(wrapper.find('video').element.muted).toBe(true)
	})

	/**
	 * A dozen videos playing behind the one on screen is a phone getting hot
	 * for nothing.
	 */
	it('plays the slide that is on screen and pauses every other one', async () => {
		const { wrapper } = await mountReels()
		const videos = wrapper.findAll('video').map((one) => one.element)

		wrapper.vm.play(1)

		expect(videos[1].play).toHaveBeenCalled()
		expect(videos[0].pause).toHaveBeenCalled()
	})

	it('rewinds a video it scrolled past, so coming back is the video again', async () => {
		const { wrapper } = await mountReels()
		const first = wrapper.findAll('video')[0].element
		Object.defineProperty(first, 'currentTime', { value: 12, writable: true })

		wrapper.vm.play(1)

		expect(first.currentTime).toBe(0)
	})

	/** The sound is a statement about the page, not about one video. */
	it('unmutes the whole stack at once', async () => {
		const { wrapper } = await mountReels()

		await wrapper.vm.toggleSound()

		expect(wrapper.vm.muted).toBe(false)
		expect(wrapper.findAll('video')[0].element.muted).toBe(false)
	})

	/** A stack is reachable from a keyboard or it is reachable by nobody using one. */
	it('moves with the arrow keys', async () => {
		const { wrapper } = await mountReels()
		const scrolled = []
		wrapper.vm.slides.forEach((slide, at) => {
			slide.scrollIntoView = () => scrolled.push(at)
		})

		await wrapper.find('.reels__track').trigger('keydown', { key: 'ArrowDown' })

		expect(scrolled).toEqual([1])
	})

	it('pauses and resumes with the space bar', async () => {
		const { wrapper } = await mountReels()
		const first = wrapper.findAll('video')[0].element
		Object.defineProperty(first, 'paused', { value: false, configurable: true })

		await wrapper.find('.reels__track').trigger('keydown', { key: ' ' })

		expect(first.pause).toHaveBeenCalled()
	})

	/**
	 * The next page is asked for before the reader reaches the end, or the
	 * stack stops dead while it loads.
	 */
	it('asks for more before the end of what it holds', async () => {
		const { wrapper, store } = await mountReels()
		store.fetchTimeline.mockClear()

		wrapper.vm.play(wrapper.vm.reels.length - 1)
		await flushPromises()

		expect(store.fetchTimeline).toHaveBeenCalled()
	})

	/** A cursor rounded through a Number skips rows or loops on one. */
	it('pages on the id as a string', async () => {
		const { wrapper, store } = await mountReels([
			video('114500000000000001'),
			video('114500000000000002'),
		])
		store.fetchTimeline.mockClear()

		await wrapper.vm.load()

		expect(store.fetchTimeline).toHaveBeenCalledWith({ max_id: '114500000000000001' })
	})

	it('stops asking once a page comes back empty', async () => {
		const { wrapper, store } = await mountReels()

		await wrapper.vm.load()
		store.fetchTimeline.mockClear()
		await wrapper.vm.load()

		expect(store.fetchTimeline).not.toHaveBeenCalled()
	})

	/** Leaving the page must not leave a video playing behind it. */
	it('pauses everything when it goes away', async () => {
		const { wrapper } = await mountReels()
		const videos = wrapper.findAll('video').map((one) => one.element)

		wrapper.unmount()

		expect(videos[0].pause).toHaveBeenCalled()
	})

	/**
	 * The single-post route is `/@:account/:id`: a link that names only the
	 * id throws while it renders, and Vue drops the component whose render
	 * threw, so the link was never on the page at all.
	 */
	it('links each slide to its post, by account and id', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		const statuses = [video('1', { acct: 'bob@remote.example' })]
		store.fetchTimeline = vi.fn(async () => {
			store.addToTimeline(statuses)

			return []
		})
		const empty = { render: () => null }
		const router = createRouter({
			history: createMemoryHistory('/index.php/apps/social'),
			routes: [
				{ path: '/', component: empty },
				{ path: '/timeline/:type?', name: 'timeline', component: empty },
				{ path: '/@:account', name: 'profile', component: empty },
				{ path: '/@:account/:id', name: 'single-post', component: empty },
			],
		})
		await router.push('/')

		const wrapper = mount(VideoReels, {
			global: { plugins: [pinia, router], stubs: { NcButton: true } },
		})
		await flushPromises()

		expect(wrapper.find('a.reel__open').attributes('href')).toBe('/index.php/apps/social/@bob@remote.example/1')
	})

	it('says so when there is nothing to watch', async () => {
		const { wrapper } = await mountReels([])

		expect(wrapper.text()).toContain('No videos here yet.')
	})
})
