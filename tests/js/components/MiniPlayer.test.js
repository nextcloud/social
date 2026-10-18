/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import MiniPlayer from '../../../src/components/MiniPlayer.vue'
import { closeMiniPlayer, handOff, miniPlayer, movedTo, takeBack } from '../../../src/services/miniPlayer.js'

const VIDEO = { id: '42', account: { acct: 'films@tube.example' }, video_card: { title: 'Stairwells' } }

/**
 * @return {{wrapper: object, router: {push: Function}}} the small player over a video handed to it
 */
function mountMini() {
	const router = { push: vi.fn() }
	const wrapper = mount(MiniPlayer, {
		global: {
			mocks: { $router: router },
			stubs: { VideoPlayer: { name: 'VideoPlayer', props: { status: Object, mini: Boolean, startAt: Number, autoplay: Boolean }, template: '<div class="video-player-stub" />' } },
		},
	})

	return { wrapper, router }
}

/** The small player a video goes on in when its page is left while it plays. */
describe('MiniPlayer', () => {
	afterEach(() => {
		closeMiniPlayer()
	})

	it('goes on playing the video from where its page was', () => {
		handOff(VIDEO, 75)
		const { wrapper } = mountMini()
		const player = wrapper.findComponent({ name: 'VideoPlayer' })

		expect(player.props()).toStrictEqual({ status: VIDEO, mini: true, startAt: 75, autoplay: true })
		expect(wrapper.find('.mini-player__title').text()).toBe('Stairwells')
		expect(wrapper.attributes('aria-label')).toBe('Video playing')
	})

	it('stops the video when it is closed', async () => {
		handOff(VIDEO, 75)
		const { wrapper } = mountMini()

		await wrapper.find('[aria-label="Close the player"]').trigger('click')

		expect(miniPlayer.status).toBeNull()
	})

	it('goes back to the video\'s own page, which takes the video back', async () => {
		handOff(VIDEO, 75)
		const { wrapper, router } = mountMini()

		await wrapper.find('[aria-label="Back to the video"]').trigger('click')

		expect(router.push).toHaveBeenCalledWith({ name: 'single-post', params: { account: 'films@tube.example', id: '42' } })
	})
})

describe('the small player\'s state', () => {
	afterEach(() => {
		closeMiniPlayer()
	})

	it('hands a video back only to the page of that video, where it was', () => {
		handOff(VIDEO, 10)
		movedTo(64, false)

		expect(takeBack('7')).toBeNull()
		expect(miniPlayer.status).toStrictEqual(VIDEO)
		expect(takeBack('42')).toEqual({ position: 64, playing: false })
		expect(miniPlayer.status).toBeNull()
		expect(takeBack('42')).toBeNull()
	})
})
