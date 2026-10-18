/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PhotoPost from '../../../src/components/PhotoPost.vue'
import GalleryCarousel from '../../../src/components/GalleryCarousel.vue'
import GalleryMedia from '../../../src/components/GalleryMedia.vue'
import eventBus from '../../../src/services/eventBus.js'
import { createPinia, setActivePinia } from 'pinia'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'
import { showSuccess } from '../../../src/services/toast.js'

vi.mock('../../../src/services/toast.js', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showInfo: vi.fn(),
}))
vi.mock('../../../src/services/senses.js', () => ({ feel: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const alice = {
	id: '1',
	acct: 'alice',
	username: 'alice',
	nextcloud_user: 'alice',
	display_name: 'Alice',
	url: 'https://cloud.example.org/@alice',
	avatar: 'https://cloud.example.org/avatar/alice/64',
	note: '',
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
	note: '',
}

const carol = {
	id: '3',
	acct: 'carol.bsky.social',
	username: 'carol.bsky.social',
	display_name: 'Carol',
	url: 'https://bsky.app/profile/carol.bsky.social',
	avatar: 'https://cdn.bsky.app/carol.jpg',
	note: '',
	bluesky: { handle: 'carol.bsky.social', did: 'did:plc:carol', url: 'https://bsky.app/profile/carol.bsky.social', native: true },
}

/**
 * @param {number} index which picture
 * @param {object} overrides what differs
 * @return {object} an image attachment
 */
function photo(index = 1, overrides = {}) {
	return {
		id: `m${index}`,
		type: 'image',
		url: `https://remote.example/m${index}.jpg`,
		preview_url: `https://remote.example/m${index}-small.jpg`,
		description: null,
		blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
		meta: { original: { width: 1600, height: 1200 }, small: { width: 800, height: 600 } },
		...overrides,
	}
}

function makeItem(overrides = {}) {
	return {
		id: '101',
		uri: 'https://remote.example/@bob/101',
		url: 'https://remote.example/@bob/101',
		created_at: '2026-09-01T10:00:00Z',
		content: '<p>Sunset over the dunes</p>',
		visibility: 'public',
		replies_count: 0,
		reblogs_count: 0,
		favourites_count: 0,
		reblogged: false,
		favourited: false,
		bookmarked: false,
		media_attachments: [photo(1)],
		mentions: [],
		tags: [],
		account: bob,
		...overrides,
	}
}

const NcActionsStub = { name: 'NcActions', template: '<div class="post-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="post-menu__item" @click="$emit(\'click\')"><slot /></button>',
}
const PhotoCommentsStub = {
	name: 'PhotoComments',
	props: ['item'],
	emits: ['close'],
	template: '<div class="photo-comments-stub" @click="$emit(\'close\')" />',
}

let getContext

/** every post mounted by a test, unmounted after it: which post's comments are open is the page's */
const mounted = []

/**
 * @param {object} options what the post and the page are
 * @return {object} the post, its store and the mock behind the store's actions
 */
function mountPhoto({
	item = makeItem(),
	serverData = {},
	dispatch = vi.fn().mockImplementation(({ status }) => Promise.resolve(status)),
	attachTo,
} = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	const accountStore = useAccountStore()
	accountStore.addAccount({ actorId: alice.url, data: alice })
	accountStore.setCurrentAccount('alice@cloud.example.org')
	const store = useTimelineStore()
	for (const action of ['postLike', 'postUnlike', 'postBoost', 'postUnBoost', 'postBookmark']) {
		vi.spyOn(store, action).mockImplementation(dispatch)
	}
	vi.spyOn(store, 'post').mockResolvedValue({ id: '202' })
	vi.spyOn(store, 'updateStatus')
	const wrapper = mount(PhotoPost, {
		props: { item, type: 'photos' },
		attachTo,
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'timeline', params: { type: 'photos' } }, $router: { push: vi.fn() } },
			stubs: {
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
				PhotoComments: PhotoCommentsStub,
				RouterLink: RouterLinkStub,
				AccountHoverCard: { template: '<span><slot /></span>' },
			},
		},
	})
	mounted.push(wrapper)

	return { wrapper, store, dispatch }
}

/** @param {object} wrapper the post @return {Promise<void>} two presses on the picture, close together */
async function doubleTap(wrapper) {
	const media = wrapper.find('.photo-post__media')
	await media.trigger('click')
	await media.trigger('click')
	await flushPromises()
}

/**
 * @param {Element} first one element
 * @param {Element} second another
 * @return {boolean} whether the first comes before the second in the page
 */
function isBefore(first, second) {
	return Boolean(first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING)
}

describe('PhotoPost', () => {
	beforeEach(() => {
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			createImageData: (width, height) => ({ data: new Uint8ClampedArray(width * height * 4) }),
			putImageData: () => {},
		})
	})

	afterEach(() => {
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
		getContext.mockRestore()
		eventBus.all.clear()
		vi.useRealTimers()
	})

	describe('layout', () => {
		it('reads header, picture, actions, likes, caption, comments, comment field and date, in that order', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ favourites_count: 23, replies_count: 4 }) })
			const order = [
				'.photo-post__header',
				'.photo-post__media',
				'.photo-post__actions',
				'.photo-post__likes',
				'.photo-post__caption',
				'.photo-post__view-comments',
				'.photo-post__comment',
				'.photo-post__date',
			].map((selector) => {
				const found = wrapper.find(selector)
				expect(found.exists(), selector).toBe(true)
				return found.element
			})

			for (let index = 1; index < order.length; index++) {
				expect(isBefore(order[index - 1], order[index])).toBe(true)
			}
		})

		it('heads the post with the face, the name, the age and the menu', () => {
			const { wrapper } = mountPhoto()
			const header = wrapper.find('.photo-post__header')

			expect(header.findComponent({ name: 'ActorAvatar' }).exists()).toBe(true)
			expect(header.find('.photo-post__author').text()).toBe('Bob')
			expect(header.find('.photo-post__age').attributes('aria-label')).toMatch(/^Open this post, written /)
			expect(header.findComponent({ name: 'PostMenu' }).exists()).toBe(true)
		})

		it('puts the bookmark at the far end of the actions', () => {
			const { wrapper } = mountPhoto()
			const buttons = wrapper.findAll('.photo-post__actions button')

			expect(buttons.map((button) => button.attributes('aria-label'))).toEqual(['Like', 'Comments', 'Boost', 'Share', 'Bookmark'])
		})

		it('names every action for a screen reader', () => {
			const { wrapper } = mountPhoto()

			for (const button of wrapper.findAll('.photo-post__actions button')) {
				expect(button.attributes('aria-label')).toBeTruthy()
			}
		})

		// a post from either network looks the same and names neither
		it('looks the same whichever network the post came from', () => {
			const remote = mountPhoto().wrapper
			const bluesky = mountPhoto({ item: makeItem({ account: carol }) }).wrapper

			expect(bluesky.findComponent({ name: 'BlueskyBadge' }).exists()).toBe(false)
			// the menu is the one every post has, with its way to the original
			const outsideTheMenu = bluesky.findAll('.photo-post > :not(.photo-post__header), .photo-post__byline')
			expect(outsideTheMenu.map((part) => part.text()).join(' ')).not.toMatch(/bluesky|activitypub|fediverse/i)
			expect(bluesky.findAll('.photo-post__actions button').length).toBe(remote.findAll('.photo-post__actions button').length)
		})

		it('runs the author\'s name into the caption', () => {
			const { wrapper } = mountPhoto()
			const caption = wrapper.find('.photo-post__caption')

			expect(caption.find('.photo-post__caption-author').text()).toBe('Bob')
			expect(caption.text()).toContain('Sunset over the dunes')
			expect(caption.classes()).toContain('photo-post__caption--clamped')
		})

		it('offers "more" for a caption past its two lines, and then shows it whole', async () => {
			const tall = vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(120)
			const short = vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockReturnValue(42)
			try {
				const { wrapper } = mountPhoto()
				await wrapper.vm.$nextTick()

				await wrapper.find('.photo-post__more').trigger('click')

				expect(wrapper.find('.photo-post__caption').classes()).not.toContain('photo-post__caption--clamped')
				expect(wrapper.find('.photo-post__more').exists()).toBe(false)
			} finally {
				tall.mockRestore()
				short.mockRestore()
			}
		})

		it('offers no "more" for a caption that fits', () => {
			expect(mountPhoto().wrapper.find('.photo-post__more').exists()).toBe(false)
		})
	})

	describe('likes', () => {
		it('counts them the way a photo feed does', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ favourites_count: 23 }) })

			expect(wrapper.find('.photo-post__likes').text()).toBe('23 likes')
		})

		it('says nothing about a post nobody has liked yet', () => {
			expect(mountPhoto().wrapper.find('.photo-post__likes').exists()).toBe(false)
		})

		it('keeps the number to itself for a reader who hides counts', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ favourites_count: 23, replies_count: 2 }), serverData: { hideCounts: true } })

			expect(wrapper.find('.photo-post__likes').exists()).toBe(false)
			expect(wrapper.find('.photo-post__view-comments').text()).toBe('View comments')
		})

		// the server sends every count as 0 to such a reader
		it('still offers the comments to a reader who hides counts', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ replies_count: 0 }), serverData: { hideCounts: true } })

			expect(wrapper.find('.photo-post__view-comments').text()).toBe('View comments')
		})
	})

	describe('the picture', () => {
		const frame = (wrapper) => wrapper.find('.photo-post__media .photo')

		it('keeps a shape inside the feed\'s range', () => {
			const { wrapper } = mountPhoto()

			expect(frame(wrapper).attributes('style')).toContain(`aspect-ratio: ${1600 / 1200}`)
		})

		it('cuts a tall picture to 4:5', () => {
			const tall = photo(1, { meta: { original: { width: 1080, height: 1920 }, small: { width: 450, height: 800 } } })
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [tall] }) })

			expect(frame(wrapper).attributes('style')).toContain('aspect-ratio: 0.8')
		})

		it('cuts a panorama to 1.91:1', () => {
			const wide = photo(1, { meta: { original: { width: 3000, height: 1000 }, small: { width: 800, height: 267 } } })
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [wide] }) })

			expect(frame(wrapper).attributes('style')).toContain('aspect-ratio: 1.91')
		})

		it('crops around the focal point the poster chose', () => {
			const focused = photo(1, { meta: { original: { width: 1080, height: 1920 }, focus: { x: 0, y: 1 } } })
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [focused] }) })

			expect(frame(wrapper).attributes('style')).toContain('--focal-position: 50.00% 0.00%')
		})

		it('draws a small picture at its own size rather than blowing it up', () => {
			const small = photo(1, { meta: { original: { width: 320, height: 320 }, small: { width: 320, height: 320 } } })
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [small] }) })

			expect(wrapper.find('.photo-post__media').attributes('style')).toContain('--photo-max: 320px')
		})

		it('keeps the blurhash placeholder while the picture loads', () => {
			const { wrapper } = mountPhoto()

			expect(wrapper.find('.photo-post__media canvas.attachment__blurhash').exists()).toBe(true)
		})

		it('is not a button: a press on it is half of a double tap', () => {
			expect(mountPhoto().wrapper.find('.photo-post__media button.photo__open').exists()).toBe(false)
		})

		it('pages several pictures in a carousel with dots, all in the first one\'s shape', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [photo(1), photo(2), photo(3)] }) })
			const carousel = wrapper.findComponent(GalleryCarousel)

			expect(carousel.exists()).toBe(true)
			expect(carousel.props()).toMatchObject({ fit: 'cover', openable: false, ratio: 1600 / 1200 })
			expect(wrapper.findAll('.gallery__dot')).toHaveLength(3)
		})

		it('shows a lone picture without a carousel', () => {
			const { wrapper } = mountPhoto()

			expect(wrapper.findComponent(GalleryCarousel).exists()).toBe(false)
			expect(wrapper.findComponent(GalleryMedia).exists()).toBe(true)
		})

		it('carries an ALT badge that reveals the description', async () => {
			const described = photo(1, { description: 'Two dogs on a beach' })
			const { wrapper } = mountPhoto({ item: makeItem({ media_attachments: [described] }) })

			await wrapper.find('.alt-badge').trigger('click')

			expect(wrapper.find('.alt-description').text()).toBe('Two dogs on a beach')
			expect(wrapper.find('.alt-description').isVisible()).toBe(true)
		})

		it('covers a sensitive picture until the reader asks for it', async () => {
			const { wrapper } = mountPhoto({ item: makeItem({ sensitive: true }) })

			expect(wrapper.find('.photo-post__media').exists()).toBe(false)
			const cover = wrapper.find('.photo-post__cover')
			expect(cover.attributes('style')).toContain(`aspect-ratio: ${1600 / 1200}`)

			await cover.find('button').trigger('click')

			expect(wrapper.find('.photo-post__media').exists()).toBe(true)
		})

		it('offers no way past the cover where the reader hides sensitive media', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ sensitive: true }), serverData: { nsfwPolicy: 'hide_all' } })

			expect(wrapper.find('.photo-post__cover button').exists()).toBe(false)
			expect(wrapper.find('.photo-post__cover').text()).toContain('Sensitive media, hidden by your settings')
		})

		it('keeps the caption under a content warning with the picture', async () => {
			const { wrapper } = mountPhoto({ item: makeItem({ spoiler_text: 'Spiders', sensitive: true }) })

			expect(wrapper.find('.photo-post__cover').text()).toContain('Spiders')
			expect(wrapper.find('.photo-post__caption').exists()).toBe(false)

			await wrapper.find('.photo-post__cover button').trigger('click')

			expect(wrapper.find('.photo-post__caption').exists()).toBe(true)
		})
	})

	describe('double tap', () => {
		it('likes the post and draws a heart', async () => {
			const { wrapper, store } = mountPhoto()

			await doubleTap(wrapper)

			expect(store.postLike).toHaveBeenCalledTimes(1)
			expect(wrapper.find('.photo-post__heart').exists()).toBe(true)
		})

		it('never takes a like back', async () => {
			const { wrapper, store } = mountPhoto({ item: makeItem({ favourited: true }) })

			await doubleTap(wrapper)
			await doubleTap(wrapper)

			expect(store.postUnlike).not.toHaveBeenCalled()
			expect(store.postLike).not.toHaveBeenCalled()
			// it still answers, so the gesture is not met with nothing
			expect(wrapper.find('.photo-post__heart').exists()).toBe(true)
		})

		it('likes nothing on a single press', async () => {
			const { wrapper, store } = mountPhoto()

			await wrapper.find('.photo-post__media').trigger('click')
			await flushPromises()

			expect(store.postLike).not.toHaveBeenCalled()
		})

		it('takes two presses far apart for two single presses', async () => {
			vi.useFakeTimers()
			const { wrapper, store } = mountPhoto()
			const media = wrapper.find('.photo-post__media')

			await media.trigger('click')
			vi.advanceTimersByTime(1000)
			await media.trigger('click')

			expect(store.postLike).not.toHaveBeenCalled()
		})

		it('leaves the controls on the picture alone', async () => {
			const described = photo(1, { description: 'Two dogs on a beach' })
			const { wrapper, store } = mountPhoto({ item: makeItem({ media_attachments: [described] }) })
			const badge = wrapper.find('.alt-badge')

			await badge.trigger('click')
			await badge.trigger('click')

			expect(store.postLike).not.toHaveBeenCalled()
		})

		it('takes the heart away again', async () => {
			vi.useFakeTimers()
			const { wrapper } = mountPhoto()

			await doubleTap(wrapper)
			vi.advanceTimersByTime(1000)
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.photo-post__heart').exists()).toBe(false)
		})

		it('likes nothing the author does not allow to be liked', async () => {
			const { wrapper, store } = mountPhoto({ item: makeItem({ interaction_policy: { like: false } }) })

			await doubleTap(wrapper)

			expect(store.postLike).not.toHaveBeenCalled()
		})
	})

	describe('the like button and the shortcut', () => {
		it('likes and unlikes from the button', async () => {
			const { wrapper, store } = mountPhoto()

			await wrapper.find('.photo-post__actions button[aria-label="Like"]').trigger('click')
			await flushPromises()
			expect(store.postLike).toHaveBeenCalledTimes(1)

			await wrapper.setProps({ item: makeItem({ favourited: true }) })
			await wrapper.find('.photo-post__actions button[aria-label="Undo Like"]').trigger('click')
			await flushPromises()
			expect(store.postUnlike).toHaveBeenCalledTimes(1)
		})

		it('likes the post the keyboard is on with `l`', async () => {
			const { store } = mountPhoto()

			eventBus.emit('timeline:focused', makeItem())
			eventBus.emit('shortcut:like')
			await flushPromises()

			expect(store.postLike).toHaveBeenCalledTimes(1)
		})

		it('opens the comments with `r`', async () => {
			const { wrapper } = mountPhoto()

			eventBus.emit('timeline:focused', makeItem())
			eventBus.emit('shortcut:reply')
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.photo-comments-stub').exists()).toBe(true)
		})

		it('bookmarks from the far end of the row', async () => {
			const { wrapper, store } = mountPhoto()

			await wrapper.find('.photo-post__bookmark').trigger('click')

			expect(store.postBookmark).toHaveBeenCalledWith({ status: expect.objectContaining({ id: '101' }), bookmarked: true })
		})
	})

	describe('comments', () => {
		it('offers the comments there are, by number', () => {
			const one = mountPhoto({ item: makeItem({ replies_count: 1 }) }).wrapper
			const many = mountPhoto({ item: makeItem({ replies_count: 12 }) }).wrapper

			expect(one.find('.photo-post__view-comments').text()).toBe('View 1 comment')
			expect(many.find('.photo-post__view-comments').text()).toBe('View all 12 comments')
		})

		it('offers none for a post nobody has answered', () => {
			expect(mountPhoto().wrapper.find('.photo-post__view-comments').exists()).toBe(false)
		})

		it('opens the panel from the button and from the link, and closes it again', async () => {
			const { wrapper } = mountPhoto({ item: makeItem({ replies_count: 2 }) })
			const button = wrapper.find('.photo-post__actions button[aria-label="Comments"]')
			expect(button.attributes('aria-expanded')).toBe('false')

			await button.trigger('click')
			expect(wrapper.findComponent(PhotoCommentsStub).props('item').id).toBe('101')
			expect(button.attributes('aria-expanded')).toBe('true')

			await wrapper.find('.photo-comments-stub').trigger('click')
			expect(wrapper.find('.photo-comments-stub').exists()).toBe(false)

			await wrapper.find('.photo-post__view-comments').trigger('click')
			expect(wrapper.find('.photo-comments-stub').exists()).toBe(true)
		})

		it('gives the focus back to the comment button when the panel closes', async () => {
			const { wrapper } = mountPhoto({ attachTo: document.body })
			const button = wrapper.find('.photo-post__actions button[aria-label="Comments"]')

			await button.trigger('click')
			await wrapper.find('.photo-comments-stub').trigger('click')
			await flushPromises()

			expect(document.activeElement).toBe(button.element)
		})

		it('keeps one panel open on the page at a time', async () => {
			const first = mountPhoto().wrapper
			const second = mountPhoto({ item: makeItem({ id: '102' }) }).wrapper

			await first.find('.photo-post__actions button[aria-label="Comments"]').trigger('click')
			await second.find('.photo-post__actions button[aria-label="Comments"]').trigger('click')

			expect(first.find('.photo-comments-stub').exists()).toBe(false)
			expect(second.find('.photo-comments-stub').exists()).toBe(true)
		})
	})

	describe('the comment field', () => {
		const field = (wrapper) => wrapper.find('.photo-post__comment-input')

		it('answers the post, addressed to its author, in the post\'s audience', async () => {
			const { wrapper, store } = mountPhoto({ item: makeItem({ visibility: 'unlisted' }) })

			await field(wrapper).setValue('Lovely light')
			await wrapper.find('.photo-post__comment').trigger('submit')
			await flushPromises()

			expect(store.post).toHaveBeenCalledWith(expect.objectContaining({
				status: '@bob@remote.example Lovely light',
				in_reply_to_id: '101',
				visibility: 'unlisted',
				media_ids: [],
			}))
		})

		it('shows what was sent, empties the field and counts the comment', async () => {
			const { wrapper, store } = mountPhoto({ item: makeItem({ replies_count: 2 }) })

			await field(wrapper).setValue('Lovely light')
			await wrapper.find('.photo-post__comment').trigger('submit')
			await flushPromises()

			expect(wrapper.find('.photo-post__sent').text()).toContain('Lovely light')
			expect(field(wrapper).element.value).toBe('')
			expect(store.updateStatus).toHaveBeenCalledWith(expect.objectContaining({ id: '101', replies_count: 3 }))
		})

		it('keeps the words when the server refused them', async () => {
			const { wrapper, store } = mountPhoto()
			store.post.mockResolvedValue(undefined)

			await field(wrapper).setValue('Lovely light')
			await wrapper.find('.photo-post__comment').trigger('submit')
			await flushPromises()

			expect(field(wrapper).element.value).toBe('Lovely light')
			expect(wrapper.find('.photo-post__sent').exists()).toBe(false)
		})

		it('sends nothing empty, and offers Post only once there is something to send', async () => {
			const { wrapper, store } = mountPhoto()

			expect(wrapper.find('.photo-post__comment-send').exists()).toBe(false)
			await field(wrapper).setValue('   ')
			await wrapper.find('.photo-post__comment').trigger('submit')

			expect(store.post).not.toHaveBeenCalled()
		})

		it('is labelled for a screen reader', () => {
			expect(field(mountPhoto().wrapper).attributes('aria-label')).toBe('Add a comment')
		})

		it('is not offered where the author takes no replies', () => {
			const { wrapper } = mountPhoto({ item: makeItem({ interaction_policy: { reply: false } }) })

			expect(wrapper.find('.photo-post__comment').exists()).toBe(false)
		})
	})

	describe('sharing', () => {
		afterEach(() => {
			delete navigator.share
		})

		it('hands the post to the device\'s share sheet where there is one', async () => {
			navigator.share = vi.fn().mockResolvedValue(undefined)
			const { wrapper } = mountPhoto()

			await wrapper.find('.photo-post__actions button[aria-label="Share"]').trigger('click')

			expect(navigator.share).toHaveBeenCalledWith({ url: 'https://remote.example/@bob/101' })
		})

		it('copies the link where there is none', async () => {
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const { wrapper } = mountPhoto()

			await wrapper.find('.photo-post__actions button[aria-label="Share"]').trigger('click')
			await flushPromises()

			expect(writeText).toHaveBeenCalledWith('https://remote.example/@bob/101')
			expect(showSuccess).toHaveBeenCalledWith('Link copied')
		})
	})

	describe('fetching ahead', () => {
		let observed
		let images
		// the avatars fetch their own pictures the same way
		const pictures = () => images.map((image) => image.src).filter((src) => String(src).includes('-small.jpg'))

		beforeEach(() => {
			observed = []
			images = []
			vi.stubGlobal('IntersectionObserver', class {
				constructor(callback, options) {
					this.callback = callback
					this.options = options
					observed.push(this)
				}

				observe() {}

				disconnect() {}
			})
			vi.stubGlobal('Image', class {
				constructor() {
					images.push(this)
				}
			})
		})

		afterEach(() => {
			vi.unstubAllGlobals()
			vi.restoreAllMocks()
		})

		it('fetches every picture of the post a couple of screens ahead', () => {
			mountPhoto({ item: makeItem({ media_attachments: [photo(1), photo(2)] }) })

			expect(observed[0].options.rootMargin).toBe('0px 0px 200% 0px')
			observed[0].callback([{ isIntersecting: true }])

			expect(pictures()).toEqual([
				'https://remote.example/m1-small.jpg',
				'https://remote.example/m2-small.jpg',
			])
		})

		it('fetches nothing behind a cover', () => {
			mountPhoto({ item: makeItem({ sensitive: true }) })

			observed[0].callback([{ isIntersecting: true }])

			expect(pictures()).toHaveLength(0)
		})

		it('fetches nothing ahead for a reader saving data', () => {
			vi.spyOn(window, 'matchMedia').mockImplementation((query) => ({
				matches: query === '(prefers-reduced-data: reduce)',
				media: query,
				addEventListener: () => {},
				removeEventListener: () => {},
			}))

			mountPhoto()

			expect(observed).toHaveLength(0)
		})
	})
})
