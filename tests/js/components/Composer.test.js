/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import Composer from '../../../src/components/Composer/Composer.vue'
import PreviewGridItem from '../../../src/components/Composer/PreviewGridItem.vue'
import SubmitStatusButton from '../../../src/components/Composer/SubmitStatusButton.vue'
import VisibilitySelect from '../../../src/components/Visibility/VisibilitySelect.vue'
import eventBus from '../../../src/services/eventBus.js'

// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))

const media = {
	id: 'media-1',
	type: 'image',
	url: 'https://cloud.example.org/media/original.png',
	preview_url: 'https://cloud.example.org/media/small.png',
	description: '',
	blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
	meta: { small: { width: 4, height: 3 } },
}

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
}

const carol = {
	id: '3',
	acct: 'carol',
	username: 'carol',
	display_name: 'Carol',
	url: 'https://cloud.example.org/@carol',
	avatar: 'https://cloud.example.org/avatar/carol/64',
}

const replyTo = (account = bob, overrides = {}) => ({
	id: '42',
	visibility: 'unlisted',
	content: '<p>Original post</p>',
	mentions: [],
	tags: [],
	account,
	...overrides,
})

// What the mention autocomplete inserts into the contenteditable
const MENTION_BOB = '<span class="mention" contenteditable="false">'
	+ '<a href="https://remote.example/@bob" target="_blank"><img src="https://remote.example/avatar.png">@bob@remote.example</a>'
	+ '</span>&nbsp;'

const wrappers = []

const mountComposer = (props = {}) => {
	const $store = {
		dispatch: vi.fn((action) => Promise.resolve(action === 'createMedia' ? media : undefined)),
		commit: vi.fn(),
		getters: { getServerData: { public: false, cloudAddress: 'https://cloud.example.org' } },
	}
	const wrapper = mount(Composer, {
		props,
		global: {
			mocks: { $store },
			stubs: {
				NcEmojiPicker: { name: 'NcEmojiPicker', emits: ['select'], template: '<div class="emoji-picker-stub"><slot /></div>' },
				NcAvatar: true,
				ActorAvatar: true,
				RouterLink: RouterLinkStub,
			},
		},
	})
	wrappers.push(wrapper)
	return { wrapper, $store }
}

const input = (wrapper) => wrapper.find('.message')
// untrimmed, unlike DOMWrapper.text(), because inserted emoji end with a space
const typed = (wrapper) => input(wrapper).element.textContent

const setContent = async (wrapper, html) => {
	input(wrapper).element.innerHTML = html
	await input(wrapper).trigger('input')
}

const submitButton = (wrapper) => wrapper.findComponent(SubmitStatusButton).find('button')
const canPost = (wrapper) => submitButton(wrapper).attributes('disabled') === undefined
const currentVisibility = (wrapper) => wrapper.findComponent(VisibilitySelect).props('visibility')
const selectVisibility = (wrapper, visibility) => wrapper.findComponent(VisibilitySelect).vm.$emit('update:visibility', visibility)
const pickEmoji = (wrapper, emoji) => wrapper.findComponent({ name: 'NcEmojiPicker' }).vm.$emit('select', emoji)

const attachFile = async (wrapper, file) => {
	const fileInput = wrapper.find('input[type="file"]')
	Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
	await fileInput.trigger('change')
}

const postedStatus = ($store) => $store.dispatch.mock.calls.find(([action]) => action === 'post')?.[1]

describe('Composer', () => {
	let getContext
	let createObjectURL

	// jsdom has no innerText; the composer empties its input through it after posting
	beforeAll(() => {
		Object.defineProperty(HTMLElement.prototype, 'innerText', {
			configurable: true,
			get() {
				return this.textContent
			},
			set(value) {
				this.textContent = value
			},
		})
	})

	afterAll(() => {
		delete HTMLElement.prototype.innerText
	})

	beforeEach(() => {
		localStorage.clear()
		vi.spyOn(console, 'debug').mockImplementation(() => {})
		getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			createImageData: (width, height) => ({ data: new Uint8ClampedArray(width * height * 4) }),
			putImageData: () => {},
		})
		createObjectURL = URL.createObjectURL
		URL.createObjectURL = vi.fn(() => 'blob:preview-1')
	})

	afterEach(() => {
		while (wrappers.length > 0) {
			wrappers.pop().unmount()
		}
		eventBus.all.clear()
		getContext.mockRestore()
		URL.createObjectURL = createObjectURL
		vi.restoreAllMocks()
	})

	describe('author', () => {
		it('shows the current user with their federated handle', () => {
			const { wrapper } = mountComposer()
			expect(wrapper.find('.post-author-name').text()).toBe('Alice')
			expect(wrapper.find('.post-author-id').text()).toBe('@alice@cloud.example.org')
		})
	})

	describe('default visibility', () => {
		it('uses the visibility passed by the parent first', () => {
			localStorage.setItem('social.lastPostType', 'direct')
			const { wrapper } = mountComposer({ defaultVisibility: 'unlisted' })
			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('falls back to the visibility of the last post', () => {
			localStorage.setItem('social.lastPostType', 'direct')
			const { wrapper } = mountComposer()
			expect(currentVisibility(wrapper)).toBe('direct')
		})

		it('defaults to followers only', () => {
			const { wrapper } = mountComposer()
			expect(currentVisibility(wrapper)).toBe('followers')
			expect(submitButton(wrapper).text()).toBe('Post to followers')
		})

		it('follows the visibility selector', async () => {
			const { wrapper } = mountComposer()
			await selectVisibility(wrapper, 'public')
			expect(currentVisibility(wrapper)).toBe('public')
			expect(submitButton(wrapper).text()).toBe('Post')
		})
	})

	describe('whether a post can be sent', () => {
		it('not while the message is empty', async () => {
			const { wrapper } = mountComposer()
			expect(canPost(wrapper)).toBe(false)
			await setContent(wrapper, '<br>')
			expect(canPost(wrapper)).toBe(false)
		})

		it('once there is some text', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'Hello fediverse')
			expect(canPost(wrapper)).toBe(true)
		})

		it('not beyond 500 characters, which is flagged on the input', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'a'.repeat(500))
			expect(canPost(wrapper)).toBe(true)
			expect(input(wrapper).classes()).not.toContain('too-long')

			await setContent(wrapper, 'a'.repeat(501))
			expect(canPost(wrapper)).toBe(false)
			expect(input(wrapper).classes()).toContain('too-long')
		})

		it('not as a direct message without anyone mentioned', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, 'psst, secret')
			expect(canPost(wrapper)).toBe(false)
		})

		it('as a direct message once a handle is typed', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, 'psst @bob@remote.example secret')
			expect(canPost(wrapper)).toBe(true)
		})

		it('as a direct message once the autocomplete inserted a mention', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'direct' })
			await setContent(wrapper, `${MENTION_BOB}secret`)
			expect(canPost(wrapper)).toBe(true)
		})

		it('not while an attachment is still uploading', async () => {
			const { wrapper, $store } = mountComposer()
			await setContent(wrapper, 'Look at this')
			let finishUpload
			$store.dispatch.mockImplementation((action) => (action === 'createMedia'
				? new Promise((resolve) => { finishUpload = resolve })
				: Promise.resolve()))

			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			expect(canPost(wrapper)).toBe(false)

			finishUpload(media)
			await flushPromises()
			expect(canPost(wrapper)).toBe(true)
		})
	})

	describe('attachments', () => {
		it('uploads a selected file and previews it', async () => {
			const { wrapper, $store } = mountComposer()
			const file = new File(['x'], 'cat.png', { type: 'image/png' })

			await attachFile(wrapper, file)

			expect(URL.createObjectURL).toHaveBeenCalledWith(file)
			expect($store.dispatch).toHaveBeenCalledWith('createMedia', file)
			const preview = wrapper.findComponent(PreviewGridItem)
			expect(preview.props('randomKey')).toBe('blob:preview-1')
			expect(preview.find('.loading-icon').exists()).toBe(true)

			await flushPromises()
			expect(preview.props('preview')).toEqual({ file, data: media })
			expect(preview.find('img').attributes('src')).toBe(media.preview_url)
		})

		it('drops a preview again when it is deleted', async () => {
			const { wrapper } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()

			await wrapper.findComponent(PreviewGridItem).find('button').trigger('click')

			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
		})

		it('opens the file picker from the attachment button', async () => {
			const { wrapper } = mountComposer()
			const click = vi.spyOn(wrapper.find('input[type="file"]').element, 'click')
			await wrapper.find('button[aria-label="Add attachment"]').trigger('click')
			expect(click).toHaveBeenCalledTimes(1)
		})
	})

	describe('emoji', () => {
		it('inserts a picked emoji into an empty message', async () => {
			const { wrapper } = mountComposer()
			await pickEmoji(wrapper, '😀')
			expect(typed(wrapper)).toBe('😀 ')
			expect(canPost(wrapper)).toBe(true)
		})

		it('appends a picked emoji to the text', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'hi')
			await pickEmoji(wrapper, '😀')
			expect(typed(wrapper)).toBe('hi😀 ')
		})

		it('keeps a picked emoji before a trailing line break', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'hi<br>')
			await pickEmoji(wrapper, '🎉')
			expect(input(wrapper).element.innerHTML).toBe('hi🎉 <br>')
		})

		it('unwraps the picker\'s category object to its first emoji', async () => {
			const { wrapper } = mountComposer()
			await pickEmoji(wrapper, { people: { grinning: '😀', wink: '😉' } })
			expect(typed(wrapper)).toBe('😀 ')
		})
	})

	describe('posting', () => {
		it('sends the plain text of the message with the attachments and visibility', async () => {
			const { wrapper, $store } = mountComposer({ defaultVisibility: 'public' })
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper,
				`<div>${MENTION_BOB}hello <img class="emoji" alt="😀" src="/apps/social/img/twemoji/1f600.svg"> Tom &amp; Jerry</div>`
				+ '<div>second line</div>')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus($store)).toEqual({
				content_type: '',
				status: '@bob@remote.example hello 😀 Tom & Jerry\nsecond line',
				visibility: 'public',
				media_ids: ['media-1'],
				in_reply_to_id: undefined,
				sensitive: false,
				spoiler_text: '',
			})
		})

		it('locks the input while sending and clears everything afterwards', async () => {
			const { wrapper, $store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await setContent(wrapper, 'Hello')
			let finishPost
			$store.dispatch.mockImplementation((action) => (action === 'post'
				? new Promise((resolve) => { finishPost = resolve })
				: Promise.resolve()))

			await submitButton(wrapper).trigger('click')
			expect(input(wrapper).attributes('contenteditable')).toBe('false')
			expect(input(wrapper).classes()).toContain('icon-loading')
			expect(canPost(wrapper)).toBe(false)

			finishPost()
			await flushPromises()

			expect(input(wrapper).attributes('contenteditable')).toBe('true')
			expect(typed(wrapper)).toBe('')
			expect(wrapper.findComponent(PreviewGridItem).exists()).toBe(false)
			expect(canPost(wrapper)).toBe(false)
			expect($store.dispatch).toHaveBeenLastCalledWith('refreshTimeline')
		})

		it('posts on Ctrl+Enter but not on Enter alone', async () => {
			const { wrapper, $store } = mountComposer()
			await setContent(wrapper, 'Hello')

			await input(wrapper).trigger('keyup', { key: 'Enter' })
			expect(postedStatus($store)).toBeUndefined()

			await input(wrapper).trigger('keyup', { key: 'Enter', ctrlKey: true })
			await flushPromises()
			expect(postedStatus($store)).toMatchObject({ status: 'Hello' })
		})

		it('sends no media ids after the only attachment was removed', async () => {
			const { wrapper, $store } = mountComposer()
			await attachFile(wrapper, new File(['x'], 'cat.png', { type: 'image/png' }))
			await flushPromises()
			await wrapper.findComponent(PreviewGridItem).find('button').trigger('click')
			await setContent(wrapper, 'No picture after all')

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus($store)).toMatchObject({ media_ids: [] })
		})
	})

	describe('replying', () => {
		it('shows who is being answered and adopts the visibility of their post', async () => {
			const { wrapper } = mountComposer({ defaultVisibility: 'public' })

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			const banner = wrapper.find('.reply-to')
			expect(banner.find('.reply-info').text()).toContain('In reply to')
			expect(banner.find('strong').text()).toBe('bob@remote.example')
			expect(banner.text()).toContain('Original post')
			expect(currentVisibility(wrapper)).toBe('unlisted')
		})

		it('prefills a mention of the remote author', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			const mention = input(wrapper).find('.mention a')
			expect(mention.attributes('href')).toBe('https://remote.example/@bob')
			expect(mention.find('img').attributes('src')).toBe(bob.avatar)
			expect(mention.text()).toBe('@bob@remote.example')
			expect(canPost(wrapper)).toBe(true)
		})

		it('completes the handle of a local author with the instance host', async () => {
			const { wrapper } = mountComposer()

			eventBus.emit('composer-reply', replyTo(carol))
			await flushPromises()

			expect(input(wrapper).find('.mention a').text()).toBe('@carol@cloud.example.org')
		})

		it('does not overwrite a message that is already being written', async () => {
			const { wrapper } = mountComposer()
			await setContent(wrapper, 'my draft')

			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			expect(wrapper.find('.reply-to').exists()).toBe(true)
			expect(input(wrapper).find('.mention').exists()).toBe(false)
			expect(input(wrapper).text()).toBe('my draft')
		})

		it('sends the reply as an answer to the original post', async () => {
			const { wrapper, $store } = mountComposer()
			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			await submitButton(wrapper).trigger('click')
			await flushPromises()

			expect(postedStatus($store)).toMatchObject({
				in_reply_to_id: '42',
				visibility: 'unlisted',
				status: '@bob@remote.example',
			})
			expect(wrapper.find('.reply-to').exists()).toBe(false)
		})

		it('can be dismissed, which also hides the composer', async () => {
			const { wrapper, $store } = mountComposer()
			eventBus.emit('composer-reply', replyTo(bob))
			await flushPromises()

			await wrapper.find('.reply-to button[aria-label="Close reply"]').trigger('click')

			expect(wrapper.find('.reply-to').exists()).toBe(false)
			expect($store.commit).toHaveBeenCalledWith('setComposerDisplayStatus', false)
		})
	})

	describe('initial mention', () => {
		it('starts the message with a mention of the given account', async () => {
			const { wrapper } = mountComposer({ initialMention: bob })
			await flushPromises()
			expect(input(wrapper).find('.mention a').text()).toBe('@bob@remote.example')
			expect(canPost(wrapper)).toBe(true)
		})
	})

	describe('mention autocomplete', () => {
		it('is wired up to the message input', () => {
			const { wrapper } = mountComposer()
			expect(input(wrapper).attributes('data-tribute')).toBe('true')
		})

		it('lets go of the message input when the composer disappears', async () => {
			const { wrapper } = mountComposer()
			const message = input(wrapper).element
			wrappers.pop()

			expect(() => wrapper.unmount()).not.toThrow()

			// tributejs cleans the marker off the element one macrotask later
			await new Promise((resolve) => setTimeout(resolve))
			expect(message.hasAttribute('data-tribute')).toBe(false)
		})
	})

	describe('lifecycle', () => {
		it('listens for replies only while mounted', () => {
			const { wrapper } = mountComposer()
			expect(eventBus.all.get('composer-reply')).toHaveLength(1)

			wrapper.unmount()
			wrappers.pop()

			expect(eventBus.all.get('composer-reply') ?? []).toHaveLength(0)
			expect(() => eventBus.emit('composer-reply', replyTo(bob))).not.toThrow()
		})
	})
})
