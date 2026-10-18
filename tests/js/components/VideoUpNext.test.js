/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import VideoUpNext, { COUNTDOWN } from '../../../src/components/VideoUpNext.vue'
import { loadRelated } from '../../../src/services/relatedVideos.js'
import { autoplayNext, setAutoplayNext } from '../../../src/services/watchPreferences.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/relatedVideos.js', () => ({ loadRelated: vi.fn() }))
vi.mock('../../../src/services/watchPreferences.js', () => ({
	autoplayNext: vi.fn(() => false),
	setAutoplayNext: vi.fn(),
}))

/**
 * @param {string} id the post's id
 * @return {object} a video post
 */
function video(id) {
	return {
		id,
		account: { id: 'a' + id, acct: 'films@tube.example', username: 'films' },
		created_at: '2026-10-13T12:00:00.000Z',
		media_attachments: [],
		video_card: { title: 'Video ' + id, duration: 60, thumbnail: null, views: 3, live: false, category: null, published: '2026-10-13T12:00:00.000Z' },
	}
}

const VideoCardStub = { name: 'VideoCard', props: { entry: Object, compact: Boolean }, template: '<a class="card-stub">{{ entry.id }}</a>' }
const SwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['modelValue', 'type'],
	emits: ['update:modelValue'],
	template: '<button class="switch-stub" :aria-checked="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
}
const ButtonStub = { name: 'NcButton', props: ['variant'], emits: ['click'], template: '<button class="nc-button-stub" @click="$emit(\'click\')"><slot /></button>' }

const $router = { push: vi.fn() }
let store

/**
 * @param {string} id the video being watched
 * @return {Promise<object>} the mounted column, its list answered
 */
async function mountUpNext(id = '1') {
	setActivePinia(createPinia())
	store = useTimelineStore()
	vi.spyOn(store, 'addToStatuses')
	const wrapper = mount(VideoUpNext, {
		props: { status: video(id) },
		global: {
			mocks: { $router },
			stubs: { VideoCard: VideoCardStub, NcCheckboxRadioSwitch: SwitchStub, NcButton: ButtonStub },
		},
	})
	await flushPromises()

	return wrapper
}

const cards = (wrapper) => wrapper.findAllComponents(VideoCardStub).map((card) => card.props('entry').id)

/** The column beside the player, and what happens when the video ends. */
describe('Up next', () => {
	beforeEach(() => {
		vi.mocked(loadRelated).mockResolvedValue([video('2'), video('3')])
		vi.mocked(autoplayNext).mockReturnValue(false)
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.clearAllMocks()
	})

	it('lists what the server offers after this video, as small cards, and keeps them for opening at once', async () => {
		const wrapper = await mountUpNext()

		expect(loadRelated).toHaveBeenCalledWith('1')
		expect(cards(wrapper)).toEqual(['2', '3'])
		expect(wrapper.findAllComponents(VideoCardStub).every((card) => card.props('compact') === true)).toBe(true)
		expect(store.addToStatuses).toHaveBeenCalledTimes(2)
		expect(wrapper.find('h2').text()).toBe('Up next')
	})

	it('says so when there is nothing to offer', async () => {
		vi.mocked(loadRelated).mockResolvedValue([])
		const wrapper = await mountUpNext()

		expect(wrapper.find('.up-next__none').exists()).toBe(true)
	})

	it('asks again for another video, and drops an answer that came too late', async () => {
		let first
		vi.mocked(loadRelated).mockImplementationOnce(() => new Promise((resolve) => {
			first = resolve
		}))
		const wrapper = await mountUpNext()

		vi.mocked(loadRelated).mockResolvedValue([video('7')])
		await wrapper.setProps({ status: video('5') })
		await flushPromises()
		first([video('2')])
		await flushPromises()

		expect(loadRelated).toHaveBeenLastCalledWith('5')
		expect(cards(wrapper)).toEqual(['7'])
	})

	describe('autoplay', () => {
		it('is remembered in this browser', async () => {
			const wrapper = await mountUpNext()

			await wrapper.find('.switch-stub').trigger('click')
			expect(setAutoplayNext).toHaveBeenCalledWith(true)
			expect(wrapper.find('.switch-stub').attributes('aria-checked')).toBe('true')
		})

		it('does nothing at the end of a video while it is off', async () => {
			vi.useFakeTimers()
			const wrapper = await mountUpNext()

			wrapper.vm.videoEnded()
			vi.advanceTimersByTime(COUNTDOWN * 1000 + 1000)
			await flushPromises()

			expect(wrapper.find('.up-next__countdown').exists()).toBe(false)
			expect($router.push).not.toHaveBeenCalled()
		})

		it('counts down from the end of a video, says so once, and opens the first of the list', async () => {
			vi.mocked(autoplayNext).mockReturnValue(true)
			vi.useFakeTimers()
			const wrapper = await mountUpNext()

			wrapper.vm.videoEnded()
			await flushPromises()
			expect(wrapper.find('.up-next__countdown-text').text()).toBe(`Playing the next video in ${COUNTDOWN} seconds`)
			expect(wrapper.find('[role="status"]').text()).toBe(`Playing the next video in ${COUNTDOWN} seconds`)

			vi.advanceTimersByTime(2000)
			await flushPromises()
			expect(wrapper.find('.up-next__countdown-text').text()).toBe(`Playing the next video in ${COUNTDOWN - 2} seconds`)
			expect(wrapper.find('[role="status"]').text()).toBe(`Playing the next video in ${COUNTDOWN} seconds`)
			expect($router.push).not.toHaveBeenCalled()

			vi.advanceTimersByTime((COUNTDOWN - 2) * 1000)
			await flushPromises()
			expect($router.push).toHaveBeenCalledWith({ name: 'single-post', params: { account: 'films@tube.example', id: '2' } })
			expect(wrapper.find('.up-next__countdown').exists()).toBe(false)
		})

		it('can be called off, or cut short', async () => {
			vi.mocked(autoplayNext).mockReturnValue(true)
			vi.useFakeTimers()
			const wrapper = await mountUpNext()

			wrapper.vm.videoEnded()
			await flushPromises()
			await wrapper.findAll('.nc-button-stub').at(1).trigger('click')
			vi.advanceTimersByTime(COUNTDOWN * 1000 + 1000)
			expect($router.push).not.toHaveBeenCalled()
			expect(wrapper.find('.up-next__countdown').exists()).toBe(false)

			wrapper.vm.videoEnded()
			await flushPromises()
			await wrapper.findAll('.nc-button-stub').at(0).trigger('click')
			expect($router.push).toHaveBeenCalledTimes(1)
		})

		it('stops counting when switched off, when another video opens, and when the page goes', async () => {
			vi.mocked(autoplayNext).mockReturnValue(true)
			vi.useFakeTimers()
			const wrapper = await mountUpNext()

			wrapper.vm.videoEnded()
			await wrapper.find('.switch-stub').trigger('click')
			expect(wrapper.find('.up-next__countdown').exists()).toBe(false)

			await wrapper.find('.switch-stub').trigger('click')
			wrapper.vm.videoEnded()
			await wrapper.setProps({ status: video('9') })
			expect(wrapper.find('.up-next__countdown').exists()).toBe(false)

			await flushPromises()
			wrapper.vm.videoEnded()
			wrapper.unmount()
			vi.advanceTimersByTime(COUNTDOWN * 1000 + 1000)
			expect($router.push).not.toHaveBeenCalled()
		})
	})
})
