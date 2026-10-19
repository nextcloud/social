/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import VideoPlayer, { SPEED_KEY } from '../../../src/components/VideoPlayer.vue'
import { closeMiniPlayer, handOff, miniPlayer } from '../../../src/services/miniPlayer.js'
import { forgetProgress, progressOf } from '../../../src/services/videoProgress.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const { post } = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve({ data: {} })) }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post } }))
const session = vi.hoisted(() => ({ user: { uid: 'alice' } }))
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => session.user,
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * A stand-in for hls.js, which jsdom cannot run: it says what a playlist
 * offers when it is told to load one.
 */
const hlsState = vi.hoisted(() => ({ instances: [], levels: [], subtitleTracks: [] }))
vi.mock('hls.js', () => {
	class Hls {
		static Events = {
			ERROR: 'error',
			MANIFEST_PARSED: 'manifestParsed',
			LEVEL_SWITCHED: 'levelSwitched',
			SUBTITLE_TRACKS_UPDATED: 'subtitleTracksUpdated',
		}

		static isSupported() {
			return true
		}

		constructor() {
			this.handlers = {}
			this.currentLevel = -1
			this.subtitleTrack = -1
			this.levels = []
			hlsState.instances.push(this)
		}

		on(name, handler) {
			this.handlers[name] = handler
		}

		loadSource() {
			this.levels = hlsState.levels
			this.handlers.manifestParsed?.()
			this.handlers.subtitleTracksUpdated?.('subtitleTracksUpdated', { subtitleTracks: hlsState.subtitleTracks })
		}

		attachMedia() {}

		destroy() {
			this.destroyed = true
		}
	}

	return { default: Hls }
})

const POST_ID = '110'
const WATCHED = `/index.php/apps/social/api/v1/statuses/${POST_ID}/watched`
const VIEW = `/index.php/apps/social/api/v1/statuses/${POST_ID}/view`

/**
 * @param {object} overrides the rest of the status
 * @param {object} attachment the video attachment's own fields
 * @return {object} a video post
 */
function status(overrides = {}, attachment = {}) {
	return {
		id: POST_ID,
		url: 'https://tube.example/w/1',
		account: { acct: 'films@tube.example' },
		media_attachments: [{
			id: '9',
			type: 'video',
			media_type: 'video/mp4',
			url: 'https://cloud.example/apps/social/media/movie.mp4',
			preview_url: 'https://cloud.example/apps/social/media/poster.jpeg',
			description: 'A film about stairwells',
			...attachment,
		}],
		video: null,
		video_card: { title: 'Stairwells', live: false },
		...overrides,
	}
}

/**
 * Gives a video element what jsdom leaves out: a clock, a length, and play
 * and pause that say so.
 *
 * @param {HTMLVideoElement} element the element
 * @param {number} duration how long it runs
 * @return {object} its state, to move the clock
 */
function fakeMedia(element, duration = 600) {
	const state = { paused: true, ended: false, currentTime: 0, duration, volume: 1, muted: false, playbackRate: 1, defaultPlaybackRate: 1 }
	for (const key of Object.keys(state)) {
		Object.defineProperty(element, key, {
			configurable: true,
			get: () => state[key],
			set: (value) => {
				state[key] = value
				if (key === 'volume' || key === 'muted') {
					element.dispatchEvent(new Event('volumechange'))
				}
				if (key === 'playbackRate') {
					element.dispatchEvent(new Event('ratechange'))
				}
			},
		})
	}
	element.play = vi.fn(() => {
		state.paused = false
		element.dispatchEvent(new Event('play'))
		return Promise.resolve()
	})
	element.pause = vi.fn(() => {
		state.paused = true
		element.dispatchEvent(new Event('pause'))
	})

	return state
}

const mounted = []

/**
 * @param {object} theStatus the video post
 * @param {object} props the rest of the props
 * @param {object} options how
 * @param {number} options.duration how long the video runs
 * @param {boolean} options.load whether its metadata has arrived
 * @return {{wrapper: object, media: object, video: HTMLVideoElement}}
 */
function mountPlayer(theStatus = status(), props = {}, { duration = 600, load = true } = {}) {
	const wrapper = mount(VideoPlayer, {
		props: { status: theStatus, ...props },
		attachTo: document.body,
	})
	mounted.push(wrapper)
	const found = wrapper.find('video')
	const video = found.exists() ? found.element : null
	const media = video ? fakeMedia(video, duration) : null
	if (video && load) {
		video.dispatchEvent(new Event('loadedmetadata'))
	}

	return { wrapper, media, video }
}

/**
 * Moves the clock of a playing video and says so.
 *
 * @param {object} player what `mountPlayer()` answered
 * @param {number} seconds where it is now
 */
function playTo(player, seconds) {
	player.media.currentTime = seconds
	player.video.dispatchEvent(new Event('timeupdate'))
}

/**
 * @param {string} key the key
 * @param {object} init the rest of the event
 * @param {EventTarget} target where it is pressed
 * @return {KeyboardEvent} the press
 */
function press(key, init = {}, target = document.body) {
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
	target.dispatchEvent(event)

	return event
}

/**
 * @param {object} wrapper the player
 * @return {Promise<string>} what a screen reader was last told
 */
async function said(wrapper) {
	await wrapper.vm.$nextTick()

	return wrapper.find('[aria-live]').text()
}

const watchedCalls = () => post.mock.calls.filter(([url]) => url === WATCHED).map(([, body]) => body)

describe('VideoPlayer', () => {
	let now

	beforeEach(() => {
		setActivePinia(createPinia())
		useSettingsStore().setServerData({})
		session.user = { uid: 'alice' }
		post.mockClear()
		hlsState.instances = []
		hlsState.levels = []
		hlsState.subtitleTracks = []
		now = 1_000_000
		vi.spyOn(Date, 'now').mockImplementation(() => now)
		window.localStorage.clear()
		closeMiniPlayer()
		forgetProgress()
	})

	afterEach(() => {
		while (mounted.length) {
			mounted.pop().unmount()
		}
		vi.restoreAllMocks()
		delete window.OCP.Accessibility
		document.body.innerHTML = ''
	})

	describe('where it starts', () => {
		it('goes on from where the reader stopped', async () => {
			const { media, wrapper } = mountPlayer(status({ watch_progress: { position: 90, duration: 600, updated_at: null } }))

			expect(media.currentTime).toBe(90)
			expect(await said(wrapper)).toBe('Going on from 1:30')
		})

		it('starts at the beginning a video the reader nearly finished', () => {
			expect(mountPlayer(status({ watch_progress: { position: 595, duration: 600, updated_at: null } })).media.currentTime).toBe(0)
			expect(mountPlayer(status({ watch_progress: { position: 580, duration: 600, updated_at: null } })).media.currentTime).toBe(0)
		})

		it('starts where it is told to over where the reader stopped', () => {
			const { media } = mountPlayer(status({ watch_progress: { position: 90, duration: 600, updated_at: null } }), { startAt: 200 })

			expect(media.currentTime).toBe(200)
		})

		/** A broadcast has no place to come back to. */
		it('does not seek in a live broadcast', () => {
			const { media, wrapper } = mountPlayer(status({
				video_card: { title: 'Now', live: true },
				watch_progress: { position: 90, duration: 600, updated_at: null },
			}))

			expect(media.currentTime).toBe(0)
			expect(wrapper.find('.video-player__seek').exists()).toBe(false)
			expect(wrapper.find('.video-player__live').text()).toBe('Live')
		})

		it('plays the file, with its poster, and the playlist of a remote video through hls.js', async () => {
			const { video } = mountPlayer()
			expect(video.getAttribute('src')).toBe('https://cloud.example/apps/social/media/movie.mp4')
			expect(video.getAttribute('poster')).toBe('https://cloud.example/apps/social/media/poster.jpeg')
			expect(video.hasAttribute('playsinline')).toBe(true)

			const remote = mountPlayer(status({}, {
				media_type: 'application/x-mpegURL',
				url: 'https://cloud.example/apps/social/media/playlist/4',
			}))
			await flushPromises()
			expect(remote.video.hasAttribute('src')).toBe(false)
			expect(hlsState.instances).toHaveLength(1)
		})
	})

	describe('telling the server where the reader is', () => {
		it('reports every ten seconds of playing, on pause, and at the end', async () => {
			const player = mountPlayer()
			player.video.play()

			now += 5000
			playTo(player, 5)
			expect(watchedCalls()).toEqual([])

			now += 5000
			playTo(player, 10)
			expect(watchedCalls()).toEqual([{ position: 10, duration: 600 }])

			now += 1000
			playTo(player, 11)
			expect(watchedCalls()).toHaveLength(1)

			player.media.currentTime = 15.4
			player.video.pause()
			expect(watchedCalls()).toEqual([{ position: 10, duration: 600 }, { position: 15, duration: 600 }])

			// the same place is not sent twice
			player.video.dispatchEvent(new Event('pause'))
			expect(watchedCalls()).toHaveLength(2)

			player.media.currentTime = 600
			player.video.dispatchEvent(new Event('ended'))
			expect(watchedCalls().at(-1)).toEqual({ position: 600, duration: 600 })
			expect(progressOf({ id: POST_ID }), 'finished is forgotten here too').toBeNull()
		})

		it('keeps every card of the video in step with what the server confirmed', async () => {
			const player = mountPlayer()
			player.media.currentTime = 300
			player.video.pause()
			await flushPromises()

			expect(progressOf({ id: POST_ID }).fraction).toBe(0.5)
		})

		/** A place the server did not take is not a place the reader is at. */
		it('says so when the server did not take the place, and tries the next one', async () => {
			post.mockRejectedValueOnce(new Error('down'))
			const player = mountPlayer()
			player.media.currentTime = 300
			player.video.pause()
			await flushPromises()

			expect(player.wrapper.find('.video-player__save-failed').exists()).toBe(true)
			expect(progressOf({ id: POST_ID })).toBeNull()

			player.video.dispatchEvent(new Event('pause'))
			await flushPromises()

			expect(player.wrapper.find('.video-player__save-failed').exists()).toBe(false)
			expect(progressOf({ id: POST_ID }).fraction).toBe(0.5)
		})

		/** A page that is going away gets no answer to an ordinary request. */
		it('reports by beacon as the page goes away', () => {
			const sendBeacon = vi.fn(() => true)
			Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: sendBeacon })
			const player = mountPlayer()
			player.media.currentTime = 42

			window.dispatchEvent(new Event('pagehide'))

			expect(sendBeacon).toHaveBeenCalledTimes(1)
			const [url, body] = sendBeacon.mock.calls[0]
			expect(url).toBe(WATCHED)
			expect(body.toString()).toBe('position=42&duration=600')
			delete navigator.sendBeacon
		})

		it('reports nothing for somebody not signed in', () => {
			session.user = null
			const player = mountPlayer()
			player.video.play()
			now += 20000
			playTo(player, 20)
			player.video.pause()

			expect(watchedCalls()).toEqual([])
		})

		it('counts a view after five seconds of playing, once, and not for a seek', () => {
			const player = mountPlayer()
			player.video.play()

			playTo(player, 60)
			expect(post).not.toHaveBeenCalledWith(VIEW)

			for (const second of [61, 62, 63, 64]) {
				playTo(player, second)
			}
			expect(post).not.toHaveBeenCalledWith(VIEW)

			playTo(player, 65)
			playTo(player, 66)
			expect(post.mock.calls.filter(([url]) => url === VIEW)).toEqual([[VIEW]])
		})
	})

	describe('speed', () => {
		it('offers half to double speed, and remembers the choice for the browser', async () => {
			const { wrapper, video } = mountPlayer()

			await wrapper.find('.video-player__button--text').trigger('click')
			const items = wrapper.findAll('[role="menuitemradio"]')
			expect(items.map((item) => item.text())).toEqual(['0.5×', '0.75×', 'Normal', '1.25×', '1.5×', '1.75×', '2×'])
			expect(items[2].attributes('aria-checked')).toBe('true')

			await items[4].trigger('click')

			expect(video.playbackRate).toBe(1.5)
			expect(window.localStorage.getItem(SPEED_KEY)).toBe('1.5')
			expect(wrapper.find('[role="menu"]').exists()).toBe(false)
			expect(await said(wrapper)).toBe('Speed 1.5×')

			const next = mountPlayer()
			expect(next.video.playbackRate).toBe(1.5)
			expect(next.wrapper.find('.video-player__button--text').text()).toBe('1.5×')
		})

		it('goes one step slower and faster with < and >, and no further than the ends', () => {
			const { video } = mountPlayer()

			press('>', { shiftKey: true })
			expect(video.playbackRate).toBe(1.25)
			press('<', { shiftKey: true })
			press('<', { shiftKey: true })
			press('<', { shiftKey: true })
			press('<', { shiftKey: true })
			expect(video.playbackRate).toBe(0.5)
		})
	})

	describe('quality', () => {
		const ladder = { hls_url: 'https://cloud.example/apps/social/media/hls/uuid/master.m3u8' }

		it('offers Auto and each size the source has, largest first, each once', async () => {
			hlsState.levels = [{ height: 720, bitrate: 2 }, { height: 360, bitrate: 1 }, { height: 1080, bitrate: 3 }, { height: 720, bitrate: 1 }]
			const { wrapper } = mountPlayer(status({}, ladder))
			await flushPromises()

			await wrapper.find('[aria-label^="Quality"]').trigger('click')
			const items = wrapper.findAll('[role="menuitemradio"]')
			expect(items.map((item) => item.text())).toEqual(['Auto', '1080p', '720p', '360p'])
			expect(items[0].attributes('aria-checked')).toBe('true')

			await items[2].trigger('click')

			expect(hlsState.instances[0].currentLevel).toBe(0)
			expect(await said(wrapper)).toBe('Quality 720p')
		})

		it('offers no choice where the source has one size', async () => {
			const plain = mountPlayer()
			hlsState.levels = [{ height: 720 }]
			const single = mountPlayer(status({}, ladder))
			await flushPromises()

			expect(plain.wrapper.find('[aria-label^="Quality"]').exists()).toBe(false)
			expect(single.wrapper.find('[aria-label^="Quality"]').exists()).toBe(false)
		})
	})

	describe('subtitles', () => {
		const subtitled = () => status({
			video: {
				captions: [
					{ language: 'de', url: 'https://cloud.example/apps/social/media/captions/110/0.vtt' },
					{ language: 'en', url: 'https://cloud.example/apps/social/media/captions/110/1.vtt' },
				],
			},
		})

		/** @param {object} wrapper the player @return {object[]} each track's TextTrack stand-in */
		function textTracks(wrapper) {
			return wrapper.findAll('track').map((track) => {
				const textTrack = { mode: 'disabled' }
				Object.defineProperty(track.element, 'track', { configurable: true, value: textTrack })

				return textTrack
			})
		}

		it('carries the tracks the video published, from this server', () => {
			const { wrapper } = mountPlayer(subtitled())
			const tracks = wrapper.findAll('track')

			expect(tracks.map((track) => [track.attributes('srclang'), track.attributes('src')])).toEqual([
				['de', 'https://cloud.example/apps/social/media/captions/110/0.vtt'],
				['en', 'https://cloud.example/apps/social/media/captions/110/1.vtt'],
			])
			expect(tracks[0].attributes('kind')).toBe('subtitles')
		})

		it('turns them on in the reader\'s own language with c, and off again', async () => {
			const { wrapper } = mountPlayer(subtitled())
			const [german, english] = textTracks(wrapper)

			press('c')
			expect([german.mode, english.mode]).toEqual(['disabled', 'showing'])
			expect(await said(wrapper)).toContain('Subtitles:')

			press('c')
			expect([german.mode, english.mode]).toEqual(['disabled', 'disabled'])
			expect(await said(wrapper)).toBe('Subtitles off')
		})

		it('lets the reader choose among several', async () => {
			const { wrapper } = mountPlayer(subtitled())
			const [german] = textTracks(wrapper)

			await wrapper.find('[aria-label="Subtitles (c)"]').trigger('click')
			const items = wrapper.findAll('[role="menuitemradio"]')
			expect(items).toHaveLength(3)
			expect(items[0].text()).toBe('Off')
			await items[1].trigger('click')

			expect(german.mode).toBe('showing')
		})

		it('offers the subtitle tracks a playlist carries as well', async () => {
			hlsState.subtitleTracks = [{ name: 'Français', lang: 'fr' }]
			const { wrapper } = mountPlayer(status({}, { media_type: 'application/x-mpegURL', url: 'https://cloud.example/apps/social/media/playlist/4' }))
			await flushPromises()

			press('c')

			expect(hlsState.instances[0].subtitleTrack).toBe(0)
			expect(await said(wrapper)).toBe('Subtitles: Français')
		})

		it('has no subtitle button for a video without any', () => {
			expect(mountPlayer().wrapper.find('[aria-label="Subtitles (c)"]').exists()).toBe(false)
		})
	})

	describe('keys', () => {
		it('plays and pauses with k and the space bar', () => {
			const { media } = mountPlayer()

			press('k')
			expect(media.paused).toBe(false)
			press(' ')
			expect(media.paused).toBe(true)
		})

		it('seeks with j, l, the arrows and the digits', () => {
			const { media } = mountPlayer()
			media.currentTime = 100

			press('l')
			expect(media.currentTime).toBe(110)
			press('j')
			press('j')
			expect(media.currentTime).toBe(90)
			press('ArrowRight')
			expect(media.currentTime).toBe(95)
			press('ArrowLeft')
			expect(media.currentTime).toBe(90)
			press('5')
			expect(media.currentTime).toBe(300)
			press('0')
			expect(media.currentTime).toBe(0)
		})

		it('changes the volume with the up and down arrows only while the player has the focus', async () => {
			const { wrapper, media } = mountPlayer()
			media.volume = 0.5

			press('ArrowUp')
			expect(media.volume).toBe(0.5)

			wrapper.element.focus()
			press('ArrowUp', {}, wrapper.element)
			expect(media.volume).toBe(0.55)
			press('ArrowDown', {}, wrapper.element)
			press('ArrowDown', {}, wrapper.element)
			expect(media.volume).toBe(0.45)
			expect(await said(wrapper)).toBe('Volume 45%')
		})

		it('mutes with m and goes full screen with f', async () => {
			const { wrapper, media } = mountPlayer()
			wrapper.element.requestFullscreen = vi.fn(() => Promise.resolve())

			press('m')
			expect(media.muted).toBe(true)
			expect(await said(wrapper)).toBe('Sound off')
			press('f')
			expect(wrapper.element.requestFullscreen).toHaveBeenCalled()
		})

		/** On the watch page j, k and l are the player's, not the timeline's. */
		it('keeps a key it takes from the app\'s own shortcuts', () => {
			mountPlayer()
			const appShortcuts = vi.fn()
			window.addEventListener('keydown', appShortcuts)

			const event = press('j')

			expect(event.defaultPrevented).toBe(true)
			expect(appShortcuts).not.toHaveBeenCalled()
			window.removeEventListener('keydown', appShortcuts)
		})

		it('does nothing while the reader types', () => {
			const { media } = mountPlayer()
			const field = document.createElement('textarea')
			document.body.appendChild(field)

			press('k', {}, field)

			expect(media.paused).toBe(true)
		})

		it('does nothing with a modifier held, or behind a dialog', () => {
			const { media } = mountPlayer()
			press('k', { ctrlKey: true })
			expect(media.paused).toBe(true)

			const dialog = document.createElement('div')
			dialog.setAttribute('aria-modal', 'true')
			document.body.appendChild(dialog)
			press('k')
			expect(media.paused).toBe(true)
		})

		it('leaves the space bar to a button elsewhere on the page that has the focus', () => {
			const { media } = mountPlayer()
			const button = document.createElement('button')
			document.body.appendChild(button)

			press(' ', {}, button)

			expect(media.paused).toBe(true)
		})

		it('does nothing while the reader turned shortcuts off here', () => {
			useSettingsStore().setServerData({ keyboardShortcuts: false })
			const { media } = mountPlayer()

			press('k')

			expect(media.paused).toBe(true)
		})

		it('does nothing while the reader turned shortcuts off for all of Nextcloud', () => {
			window.OCP.Accessibility = { disableKeyboardShortcuts: () => true }
			const { media } = mountPlayer()

			press('k')

			expect(media.paused).toBe(true)
		})

		it('leaves the arrows to a slider of the player that has the focus', () => {
			const { wrapper, media } = mountPlayer()
			media.currentTime = 100
			const seek = wrapper.find('.video-player__seek').element

			press('ArrowRight', {}, seek)
			expect(media.currentTime).toBe(100)
			press('k', {}, seek)
			expect(media.paused).toBe(false)
		})

		it('walks a menu with the arrows and leaves it with Escape', async () => {
			const { wrapper } = mountPlayer()
			const button = wrapper.find('.video-player__button--text')
			await button.trigger('click')
			const items = wrapper.findAll('[role="menuitemradio"]')
			expect(document.activeElement).toBe(items[2].element)

			await items[2].trigger('keydown', { key: 'ArrowDown' })
			expect(document.activeElement).toBe(items[3].element)
			await items[3].trigger('keydown', { key: 'Escape' })

			expect(wrapper.find('[role="menu"]').exists()).toBe(false)
			expect(document.activeElement).toBe(button.element)
		})
	})

	describe('picture in picture', () => {
		it('is offered only where the browser has it', async () => {
			expect(mountPlayer().wrapper.find('[aria-label="Play in a floating window"]').exists()).toBe(false)

			Object.defineProperty(document, 'pictureInPictureEnabled', { configurable: true, value: true })
			HTMLVideoElement.prototype.requestPictureInPicture = vi.fn(() => Promise.resolve())
			try {
				const { wrapper, video } = mountPlayer()
				await wrapper.find('[aria-label="Play in a floating window"]').trigger('click')

				expect(video.requestPictureInPicture).toHaveBeenCalled()
			} finally {
				delete document.pictureInPictureEnabled
				delete HTMLVideoElement.prototype.requestPictureInPicture
			}
		})
	})

	describe('the small player', () => {
		it('takes over a video that was playing when its page was left', () => {
			const theStatus = status()
			const { wrapper, media, video } = mountPlayer(theStatus)
			video.play()
			media.currentTime = 123

			wrapper.unmount()
			mounted.splice(mounted.indexOf(wrapper), 1)

			expect(miniPlayer.status).toStrictEqual(theStatus)
			expect(miniPlayer.position).toBe(123)
			expect(miniPlayer.playing).toBe(true)
		})

		it('is not given a video that was paused', () => {
			const { wrapper } = mountPlayer()
			wrapper.unmount()
			mounted.splice(mounted.indexOf(wrapper), 1)

			expect(miniPlayer.status).toBeNull()
		})

		/** Back on the video's page, it goes on from where the small player was. */
		it('gives the video back to its page', () => {
			handOff(status(), 0)
			miniPlayer.position = 321
			const { media } = mountPlayer()

			expect(media.currentTime).toBe(321)
			expect(media.paused).toBe(false)
			expect(miniPlayer.status).toBeNull()
		})

		it('keeps holding another video until the page plays its own', () => {
			handOff(status({ id: '999' }), 40)
			const { video } = mountPlayer()
			expect(miniPlayer.status.id).toBe('999')

			video.play()

			expect(miniPlayer.status).toBeNull()
		})

		it('says where it is as it plays, and never hands itself on', () => {
			handOff(status(), 50)
			const { wrapper, media, video } = mountPlayer(status(), { mini: true, startAt: 50, autoplay: true })
			expect(media.currentTime).toBe(50)
			expect(media.paused).toBe(false)

			playTo({ media, video }, 51)
			expect(miniPlayer.position).toBe(51)
			video.pause()
			expect(miniPlayer.playing).toBe(false)

			video.play()
			closeMiniPlayer()
			wrapper.unmount()
			mounted.splice(mounted.indexOf(wrapper), 1)
			expect(miniPlayer.status).toBeNull()
		})

		it('answers only to keys pressed while it has the focus', () => {
			const { wrapper, media } = mountPlayer(status(), { mini: true })

			press('k')
			expect(media.paused).toBe(true)
			press('k', {}, wrapper.element)
			expect(media.paused).toBe(false)
		})
	})

	describe('a video that cannot be played here', () => {
		it('says why where the attachment does', () => {
			const { wrapper } = mountPlayer(status({}, { url: null }), {}, { load: false })

			expect(wrapper.find('video').exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'MediaAttachment' }).exists()).toBe(true)
		})

		it('links to where it was published when there is no file at all', () => {
			const { wrapper } = mountPlayer(status({ media_attachments: [] }), {}, { load: false })

			expect(wrapper.find('a').attributes('href')).toBe('https://tube.example/w/1')
		})
	})

	describe('accessibility', () => {
		it('labels every control', () => {
			const { wrapper } = mountPlayer()

			for (const control of wrapper.findAll('button, input')) {
				expect(control.attributes('aria-label'), control.html()).toBeTruthy()
			}
			expect(wrapper.attributes('role')).toBe('region')
			expect(wrapper.attributes('tabindex')).toBe('0')
		})

		it('says play or pause on its button as it changes', async () => {
			const { wrapper, video } = mountPlayer()
			const play = wrapper.find('.video-player__bar button')
			expect(play.attributes('aria-label')).toBe('Play (k)')

			video.play()
			await wrapper.vm.$nextTick()

			expect(play.attributes('aria-label')).toBe('Pause (k)')
		})
	})
})
