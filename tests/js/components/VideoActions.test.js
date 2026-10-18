/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import VideoActions from '../../../src/components/VideoActions.vue'
import { showSuccess } from '../../../src/services/toast.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

const REMOTE = { id: '9', acct: 'films@tube.example', username: 'films' }
const LOCAL = { id: '3', acct: 'alice', username: 'alice' }

/**
 * @param {object} [extra] the rest of the status
 * @return {object} a video post
 */
function status(extra = {}) {
	return {
		id: '1',
		account: REMOTE,
		url: 'https://tube.example/w/1',
		visibility: 'public',
		favourites_count: 1500,
		dislikes_count: null,
		media_attachments: [{ id: 'm', type: 'video', url: 'https://cloud.example/media/v.mp4' }],
		video: { title: 'Boats' },
		...extra,
	}
}

let store

/**
 * @param {object} theStatus the post
 * @param {object} [serverData] what the page was told
 * @param {object} [props] further props
 * @return {object} the mounted row
 */
function mountActions(theStatus = status(), serverData = {}, props = {}) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData({ public: false, ...serverData })
	store = useTimelineStore()
	for (const action of ['postLike', 'postUnlike', 'postDislike', 'postUndislike', 'postBoost', 'postUnBoost', 'postBookmark']) {
		store[action] = vi.fn().mockResolvedValue({})
	}

	return mount(VideoActions, { props: { status: theStatus, ...props } })
}

const has = (wrapper, name) => wrapper.find(`.video-actions__${name}`).exists()

/** The pills under a video, each the same action as under a post. */
describe('the row of actions under a video', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	describe('like and dislike', () => {
		it('likes, and takes the like back', async () => {
			const wrapper = mountActions()
			await wrapper.find('.video-actions__like').trigger('click')
			expect(store.postLike).toHaveBeenCalledWith({ status: wrapper.props('status') })

			await wrapper.setProps({ status: status({ favourited: true }) })
			expect(wrapper.find('.video-actions__like').attributes('aria-pressed')).toBe('true')
			await wrapper.find('.video-actions__like').trigger('click')
			expect(store.postUnlike).toHaveBeenCalled()
		})

		it('says how many liked it the way a video site does', () => {
			expect(mountActions().find('.video-actions__like').text()).toBe('1.5K')
		})

		it('says no numbers to a reader who hides them', () => {
			const wrapper = mountActions(status({ dislikes_count: 4 }), { hideCounts: true })

			expect(wrapper.find('.video-actions__like').text()).toBe('')
			expect(wrapper.find('.video-actions__dislike').text()).toBe('')
		})

		/** This server counts the `Dislike`s it received; PeerTube states a number covering its own viewers. */
		it('prefers this server own dislike count over the one the origin stated', () => {
			expect(mountActions(status({ dislikes_count: 2, video: { dislikes: 9 } })).find('.video-actions__dislike').text()).toBe('2')
			expect(mountActions(status({ video: { dislikes: 9 } })).find('.video-actions__dislike').text()).toBe('9')
		})

		it('dislikes, and takes it back, never twice while one is in flight', async () => {
			const wrapper = mountActions()
			let finish
			store.postDislike = vi.fn(() => new Promise((resolve) => {
				finish = resolve
			}))

			await wrapper.find('.video-actions__dislike').trigger('click')
			await wrapper.find('.video-actions__dislike').trigger('click')
			expect(store.postDislike).toHaveBeenCalledTimes(1)
			expect(wrapper.find('.video-actions__dislike').attributes('disabled')).toBeDefined()
			finish()
			await flushPromises()

			await wrapper.setProps({ status: status({ disliked: true }) })
			expect(wrapper.find('.video-actions__dislike').attributes('aria-label')).toBe('Undo dislike')
			await wrapper.find('.video-actions__dislike').trigger('click')
			expect(store.postUndislike).toHaveBeenCalled()
		})

		it('offers no like where the author does not allow one', () => {
			expect(has(mountActions(status({ interaction_policy: { like: false } })), 'like')).toBe(false)
		})
	})

	describe('boost and save', () => {
		it('boosts a public video and takes the boost back', async () => {
			const wrapper = mountActions()
			await wrapper.find('.video-actions__boost').trigger('click')
			expect(store.postBoost).toHaveBeenCalled()

			await wrapper.setProps({ status: status({ reblogged: true }) })
			await flushPromises()
			await wrapper.find('.video-actions__boost').trigger('click')
			expect(store.postUnBoost).toHaveBeenCalled()
		})

		it('offers no boost of a post for followers only, or one the author keeps from being boosted', () => {
			expect(has(mountActions(status({ visibility: 'private' })), 'boost')).toBe(false)
			expect(has(mountActions(status({ interaction_policy: { boost: false } })), 'boost')).toBe(false)
		})

		it('saves the video, and lets it go again', async () => {
			const wrapper = mountActions()
			await wrapper.find('.video-actions__save').trigger('click')
			expect(store.postBookmark).toHaveBeenCalledWith({ status: wrapper.props('status'), bookmarked: true })

			await wrapper.setProps({ status: status({ bookmarked: true }) })
			expect(wrapper.find('.video-actions__save').text()).toBe('Saved')
			await wrapper.find('.video-actions__save').trigger('click')
			expect(store.postBookmark).toHaveBeenLastCalledWith({ status: wrapper.props('status'), bookmarked: false })
		})
	})

	describe('share', () => {
		const original = { share: navigator.share, clipboard: navigator.clipboard }

		beforeEach(() => {
			Object.defineProperty(navigator, 'share', { value: undefined, configurable: true, writable: true })
			Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true })
		})

		afterEach(() => {
			Object.defineProperty(navigator, 'share', { value: original.share, configurable: true, writable: true })
			Object.defineProperty(navigator, 'clipboard', { value: original.clipboard, configurable: true })
		})

		it('hands the address to the device to share where it can', async () => {
			navigator.share = vi.fn().mockResolvedValue(undefined)
			await mountActions().find('.video-actions__share').trigger('click')

			expect(navigator.share).toHaveBeenCalledWith({ url: 'https://tube.example/w/1' })
			expect(navigator.clipboard.writeText).not.toHaveBeenCalled()
		})

		it('copies it where there is nothing to hand it to', async () => {
			await mountActions().find('.video-actions__share').trigger('click')
			await flushPromises()

			expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://tube.example/w/1')
			expect(showSuccess).toHaveBeenCalledWith('Link copied')
		})

		it('takes a share sheet closed for no failure', async () => {
			navigator.share = vi.fn().mockRejectedValue(Object.assign(new Error('closed'), { name: 'AbortError' }))
			await mountActions().find('.video-actions__share').trigger('click')
			await flushPromises()

			expect(navigator.clipboard.writeText).not.toHaveBeenCalled()
		})
	})

	/** Only where something says the author allows it. */
	describe('download', () => {
		it('is offered for a video posted here, and for one whose author said yes', () => {
			expect(mountActions(status({ account: LOCAL, video: null })).find('.video-actions__download').attributes('href'))
				.toBe('https://cloud.example/media/v.mp4')
			expect(has(mountActions(status({ video: { download: true } })), 'download')).toBe(true)
		})

		it('is not offered where the author said no, said nothing, or the file is not here', () => {
			expect(has(mountActions(status({ account: LOCAL, video: { download: false } })), 'download')).toBe(false)
			expect(has(mountActions(), 'download')).toBe(false)
			expect(has(mountActions(status({ video: { download: true }, media_attachments: [{ id: 'm', type: 'video', url: null }] })), 'download')).toBe(false)
		})
	})

	it('offers a reader who is not signed in only what needs no account', () => {
		const wrapper = mountActions(status({ account: LOCAL }), { public: true })

		expect(['like', 'dislike', 'boost', 'save'].filter((name) => has(wrapper, name))).toEqual([])
		expect(['share', 'download', 'theater'].filter((name) => has(wrapper, name))).toEqual(['share', 'download', 'theater'])
	})

	it('switches the wide player, and says whether it is on', async () => {
		const wrapper = mountActions(status(), {}, { theater: true })
		const button = wrapper.find('.video-actions__theater')

		expect(button.attributes('aria-pressed')).toBe('true')
		expect(button.attributes('aria-label')).toBe('Theater mode')
		await button.trigger('click')
		expect(wrapper.emitted('toggleTheater')).toHaveLength(1)
	})
})
