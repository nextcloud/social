/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import VideoHeader from '../../../src/components/VideoHeader.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

const ACCOUNT = {
	id: '1',
	acct: 'films@peer.tube',
	username: 'films',
	display_name: 'Films',
	followers_count: 1234,
}

/**
 * @param {object} video what the post said about the video
 * @param {object} overrides the rest of the status
 * @return {object} a status carrying a Video
 */
function status(video = {}, overrides = {}) {
	return {
		id: '1',
		account: ACCOUNT,
		content: '<p>A film about stairwells</p>',
		media_attachments: [],
		video: { title: 'Stairwells', ...video },
		...overrides,
	}
}

const VideoDescriptionStub = {
	name: 'VideoDescription',
	props: ['status'],
	emits: ['seek'],
	template: '<div class="description-stub" />',
}
const VideoActionsStub = {
	name: 'VideoActions',
	props: ['status', 'theater'],
	emits: ['toggleTheater'],
	template: '<div class="actions-stub" />',
}

/**
 * @param {object} theStatus the post to draw
 * @param {object} serverData what the page was told
 * @param {object} props further props
 * @return {object} the mounted page
 */
function mountHeader(theStatus = status(), serverData = {}, props = {}) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData(serverData)
	vi.spyOn(useAccountStore(), 'fetchRelationship').mockResolvedValue([])

	return mount(VideoHeader, {
		props: { status: theStatus, ...props },
		global: {
			stubs: {
				RouterLink: RouterLinkStub,
				PostAttachment: { template: '<div class="player-stub"><slot /></div>' },
				ActorAvatar: true,
				FollowButton: true,
				VideoDescription: VideoDescriptionStub,
				VideoActions: VideoActionsStub,
			},
		},
	})
}

/**
 * A video's own page: the same route a post opens on, drawn as a video site
 * draws one — player, title, channel and actions, then the description.
 */
describe('the video page', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	describe('what it is called', () => {
		it('uses the video own title where there is one, as the page heading', () => {
			const title = mountHeader().find('h1.watch__title')

			expect(title.text()).toBe('Stairwells')
		})

		/**
		 * A video posted from here has no separate title — the composer does
		 * not ask for one — so the first line stands in, which is where
		 * somebody writing about a video puts its name.
		 */
		it('falls back to the first line of the post', () => {
			const wrapper = mountHeader(status({ title: '' }, {
				content: '<p>Stairwells of East Berlin</p><p>Shot over ten years.</p>',
			}))

			expect(wrapper.find('.watch__title').text()).toBe('Stairwells of East Berlin')
		})

		it('skips the empty lines the markup leaves behind', () => {
			const wrapper = mountHeader(status({ title: '' }, {
				content: '<p></p>\n<p>  </p><p>Stairwells</p>',
			}))

			expect(wrapper.find('.watch__title').text()).toBe('Stairwells')
		})

		it('is empty rather than broken for a post with no words in it', () => {
			expect(mountHeader(status({ title: '' }, { content: '' })).find('.watch__title').text())
				.toBe('')
		})

		it('marks a broadcast that is happening now', () => {
			expect(mountHeader(status({ live: true })).find('.watch__live').text()).toBe('Live')
			expect(mountHeader().find('.watch__live').exists()).toBe(false)
		})
	})

	/** On PeerTube a video is listed under its channel, not under a person. */
	describe('the channel', () => {
		it('names it, links to it and says how many follow it', () => {
			const wrapper = mountHeader()

			expect(wrapper.find('.watch__channel-name').text()).toBe('Films')
			expect(wrapper.find('.watch__channel-followers').text()).toBe('1.2K followers')
			expect(wrapper.findComponent(RouterLinkStub).props('to'))
				.toEqual({ name: 'profile', params: { account: 'films@peer.tube' } })
			expect(wrapper.findComponent({ name: 'FollowButton' }).attributes('uid')).toBe('films@peer.tube')
		})

		/** The follow button draws the relationship, which nothing else on the page asks for. */
		it('asks for the reader\'s relationship with the channel, for the follow button', () => {
			const wrapper = mountHeader(status({}, { account: { ...ACCOUNT, url: 'https://peer.tube/c/films' } }))
			const accounts = useAccountStore()

			expect(accounts.getAccount('films@peer.tube')?.id).toBe('1')
			expect(accounts.fetchRelationship).toHaveBeenCalledWith('1')
			wrapper.unmount()
		})

		it('asks nothing for a reader who is not signed in', () => {
			mountHeader(status({}, { account: { ...ACCOUNT, url: 'https://peer.tube/c/films' } }), { public: true })

			expect(useAccountStore().fetchRelationship).not.toHaveBeenCalled()
		})

		it('says nothing of the followers to a reader who hides the numbers, or when nobody counted', () => {
			expect(mountHeader(status(), { hideCounts: true }).find('.watch__channel-followers').exists()).toBe(false)
			expect(mountHeader(status({}, { account: { ...ACCOUNT, followers_count: undefined } }))
				.find('.watch__channel-followers').exists()).toBe(false)
		})

		it('falls back to the username when the channel has no name', () => {
			const wrapper = mountHeader(status({}, {
				account: { ...ACCOUNT, display_name: '' },
			}))

			expect(wrapper.find('.watch__channel-name').text()).toBe('films')
		})
	})

	describe('around the player', () => {
		it('hands the actions the post and whether the wide player is on, and passes their switch on', async () => {
			const wrapper = mountHeader(status(), {}, { theater: true })
			const actions = wrapper.findComponent(VideoActionsStub)

			expect(actions.props('theater')).toBe(true)
			await actions.vm.$emit('toggleTheater')
			expect(wrapper.emitted('toggleTheater')).toHaveLength(1)
		})

		/**
		 * `ended` does not bubble; it is heard on its way down to the element,
		 * so the page needs nothing from the player but a `<video>`.
		 */
		it('says when the video has played to its end, and not when anything else ends', () => {
			const wrapper = mountHeader()
			const stage = wrapper.find('.watch__stage').element
			const video = document.createElement('video')
			const audio = document.createElement('audio')
			stage.appendChild(video)
			stage.appendChild(audio)

			audio.dispatchEvent(new Event('ended'))
			expect(wrapper.emitted('ended')).toBeUndefined()

			video.dispatchEvent(new Event('ended'))
			expect(wrapper.emitted('ended')).toHaveLength(1)
		})

		it('moves the player to a chapter pressed in the description, and plays it', async () => {
			const wrapper = mountHeader()
			const player = document.createElement('video')
			player.play = vi.fn().mockResolvedValue(undefined)
			wrapper.find('.watch__stage').element.appendChild(player)

			await wrapper.findComponent(VideoDescriptionStub).vm.$emit('seek', 125)

			expect(player.currentTime).toBe(125)
			expect(player.play).toHaveBeenCalled()
		})

		/** A browser that refuses to play unprompted must not throw here. */
		it('does not fail when the browser refuses to play, or when there is no player yet', async () => {
			const wrapper = mountHeader()
			expect(() => wrapper.findComponent(VideoDescriptionStub).vm.$emit('seek', 3)).not.toThrow()

			const player = document.createElement('video')
			player.play = vi.fn().mockRejectedValue(new Error('not allowed'))
			wrapper.find('.watch__stage').element.appendChild(player)
			wrapper.findComponent(VideoDescriptionStub).vm.$emit('seek', 7)
			await flushPromises()

			expect(player.currentTime).toBe(7)
		})
	})
})
