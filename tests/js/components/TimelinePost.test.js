/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TimelinePost from '../../../src/components/TimelinePost.vue'
import eventBus from '../../../src/services/eventBus.js'

const alice = {
	id: '1',
	acct: 'alice',
	username: 'alice',
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

const makeItem = (overrides = {}) => ({
	id: '101',
	uri: 'https://cloud.example.org/@alice/101',
	created_at: '2026-09-01T10:00:00Z',
	content: '<p>Hello <strong>world</strong></p>',
	visibility: 'public',
	replies_count: 0,
	reblogs_count: 0,
	favourites_count: 0,
	reblogged: false,
	favourited: false,
	media_attachments: [],
	mentions: [],
	tags: [],
	account: alice,
	...overrides,
})

// NcActions only renders its entries inside a popover once opened; these
// stand-ins render them inline so the menu content can be asserted.
const NcActionsStub = { name: 'NcActions', template: '<div class="post-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="post-menu__item" @click="$emit(\'click\')"><slot /></button>',
}

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	template: '<div v-if="open" class="report-dialog"><slot /></div>',
}

const mountPost = ({
	item = makeItem(),
	route = { name: 'timeline', params: { type: 'home' } },
	currentAccount = alice,
	serverData = { public: false, cloudAddress: 'https://cloud.example.org' },
} = {}) => {
	const $store = {
		dispatch: vi.fn().mockResolvedValue(undefined),
		commit: vi.fn(),
		getters: { currentAccount, getServerData: serverData },
	}
	const $router = { push: vi.fn() }
	const wrapper = mount(TimelinePost, {
		props: { item, type: 'home' },
		global: {
			mocks: { $store, $route: route, $router },
			stubs: {
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
				NcDialog: NcDialogStub,
				PostAttachment: true,
				RouterLink: RouterLinkStub,
			},
		},
	})
	return { wrapper, item, $store, $router }
}

const actionButton = (wrapper, label) => wrapper.find(`.post-actions button[aria-label="${label}"]`)
const menuItem = (wrapper, label) => wrapper.findAll('.post-menu__item').find((button) => button.text() === label)

describe('TimelinePost', () => {
	afterEach(() => {
		eventBus.all.clear()
	})

	describe('header and body', () => {
		it('shows the author and links to the profile', () => {
			const { wrapper } = mountPost()

			expect(wrapper.find('.post-author').text()).toBe('Alice')
			expect(wrapper.find('.post-author-id').text()).toBe('@alice')
			expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile', params: { account: 'alice' } })
			expect(wrapper.attributes('data-social-status')).toBe('101')
		})

		it('renders the status content through MessageContent', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-message strong').text()).toBe('world')
		})

		it('shows the post visibility as an icon with the label as tooltip', () => {
			const { wrapper } = mountPost({ item: makeItem({ visibility: 'followers' }) })
			const icon = wrapper.find('.post-visibility')
			expect(icon.classes()).toContain('account-multiple-icon')
			expect(icon.find('title').text()).toBe('Followers')
		})

		it('exposes the creation time on the timestamp', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-timestamp').attributes('data-timestamp')).toBe(String(Date.parse('2026-09-01T10:00:00Z')))
		})

		it('falls back to the sanitised author bio when the status has no content', () => {
			const { wrapper } = mountPost({
				item: makeItem({ content: '', account: { ...alice, note: '<p>bio <b>bold</b><script>alert(1)</script></p>' } }),
			})

			const message = wrapper.find('.post-message')
			expect(message.find('b').text()).toBe('bold')
			expect(message.find('script').exists()).toBe(false)
			expect(message.text()).toBe('bio bold')
		})

		it('renders attachments only when the status has some', () => {
			expect(mountPost().wrapper.findComponent({ name: 'PostAttachment' }).exists()).toBe(false)

			const media = [{ id: 'm1', url: 'https://cloud.example.org/m1.jpg' }]
			const { wrapper } = mountPost({ item: makeItem({ media_attachments: media }) })
			expect(wrapper.findComponent({ name: 'PostAttachment' }).props('attachments')).toEqual(media)
		})
	})

	describe('link preview', () => {
		const card = {
			url: 'https://example.org/news/today',
			title: 'The headline',
			description: 'What it is about',
			provider_name: 'Example News',
			image: null,
		}

		it('shows the preview of the linked page', () => {
			const { wrapper } = mountPost({ item: makeItem({ card }) })
			const preview = wrapper.findComponent({ name: 'PostCard' })

			expect(preview.exists()).toBe(true)
			expect(preview.props('card')).toEqual(card)
		})

		it('shows no preview for a post that links nowhere', () => {
			expect(mountPost().wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
			expect(mountPost({ item: makeItem({ card: null }) }).wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
		})

		it('leaves the preview out when the post carries media of its own', () => {
			const item = makeItem({
				card,
				media_attachments: [{ id: '1', type: 'image', url: 'https://cloud.example.org/m.png' }],
			})

			expect(mountPost({ item }).wrapper.findComponent({ name: 'PostCard' }).exists()).toBe(false)
		})
	})

	describe('opening the single post view', () => {
		it('navigates to the single-post route for a local post', async () => {
			const { wrapper, $router } = mountPost()
			await wrapper.find('.post-timestamp').trigger('click')
			expect($router.push).toHaveBeenCalledWith({
				name: 'single-post',
				params: { account: 'alice', id: '101', type: 'single-post' },
			})
		})

		it('does not navigate for a remote post', async () => {
			const { wrapper, $router } = mountPost({ item: makeItem({ account: bob }) })
			await wrapper.find('.post-timestamp').trigger('click')
			expect($router.push).not.toHaveBeenCalled()
		})
	})

	describe('action bar', () => {
		it('is shown on a regular timeline for a logged-in user', () => {
			const { wrapper } = mountPost()
			expect(wrapper.find('.post-actions').exists()).toBe(true)
			expect(actionButton(wrapper, 'Reply').exists()).toBe(true)
		})

		it('is hidden on the notifications timeline', () => {
			const { wrapper } = mountPost({ route: { name: 'timeline', params: { type: 'notifications' } } })
			expect(wrapper.find('.post-actions').exists()).toBe(false)
		})

		it('is hidden on the public page', () => {
			const { wrapper } = mountPost({ serverData: { public: true, cloudAddress: 'https://cloud.example.org' } })
			expect(wrapper.find('.post-actions').exists()).toBe(false)
		})

		it('shows a counter only when it is above zero', () => {
			expect(mountPost().wrapper.findAll('.post-action-count')).toHaveLength(0)

			const { wrapper } = mountPost({ item: makeItem({ replies_count: 3, reblogs_count: 0, favourites_count: 12 }) })
			expect(wrapper.findAll('.post-action-count').map((count) => count.text())).toEqual(['3', '12'])
		})
	})

	describe('reply', () => {
		it('opens the composer and hands it the status to reply to', async () => {
			const onReply = vi.fn()
			eventBus.on('composer-reply', onReply)
			const { wrapper, item, $store } = mountPost()

			await actionButton(wrapper, 'Reply').trigger('click')

			expect($store.commit).toHaveBeenCalledWith('setComposerDisplayStatus', true)
			expect(onReply).toHaveBeenCalledTimes(1)
			expect(onReply.mock.calls[0][0]).toEqual(item)
		})
	})

	describe('boost', () => {
		it.each(['public', 'unlisted'])('is offered for a %s post', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(true)
		})

		it.each(['followers', 'direct'])('is not offered for a %s post', (visibility) => {
			const { wrapper } = mountPost({ item: makeItem({ visibility }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)
			expect(actionButton(wrapper, 'Undo boost').exists()).toBe(false)
		})

		it('boosts a post that is not boosted yet', async () => {
			const { wrapper, item, $store } = mountPost()
			await actionButton(wrapper, 'Boost').trigger('click')
			expect($store.dispatch).toHaveBeenCalledTimes(1)
			expect($store.dispatch).toHaveBeenCalledWith('postBoost', expect.objectContaining({ status: item }))
		})

		it('undoes the boost of an already boosted post', async () => {
			const { wrapper, item, $store } = mountPost({ item: makeItem({ reblogged: true }) })
			expect(actionButton(wrapper, 'Boost').exists()).toBe(false)

			await actionButton(wrapper, 'Undo boost').trigger('click')

			expect($store.dispatch).toHaveBeenCalledTimes(1)
			expect($store.dispatch).toHaveBeenCalledWith('postUnBoost', expect.objectContaining({ status: item }))
		})
	})

	describe('like', () => {
		it('likes a post that is not liked yet', async () => {
			const { wrapper, item, $store } = mountPost()
			expect(actionButton(wrapper, 'Like').find('.heart-outline-icon').exists()).toBe(true)
			expect(actionButton(wrapper, 'Undo Like').exists()).toBe(false)

			await actionButton(wrapper, 'Like').trigger('click')

			expect($store.dispatch).toHaveBeenCalledTimes(1)
			expect($store.dispatch).toHaveBeenCalledWith('postLike', expect.objectContaining({ status: item }))
		})

		it('removes the like from a liked post', async () => {
			const { wrapper, item, $store } = mountPost({ item: makeItem({ favourited: true }) })
			expect(actionButton(wrapper, 'Like').exists()).toBe(false)
			expect(actionButton(wrapper, 'Undo Like').find('.heart-icon').exists()).toBe(true)

			await actionButton(wrapper, 'Undo Like').trigger('click')

			expect($store.dispatch).toHaveBeenCalledTimes(1)
			expect($store.dispatch).toHaveBeenCalledWith('postUnlike', expect.objectContaining({ status: item }))
		})
	})

	describe('edit and delete', () => {
		it('are offered for the viewer\'s own post', () => {
			const { wrapper } = mountPost()
			expect(menuItem(wrapper, 'Edit')).toBeDefined()
			expect(menuItem(wrapper, 'Delete')).toBeDefined()
		})

		it('are withheld for somebody else\'s post, which offers Report instead', () => {
			const { wrapper } = mountPost({ item: makeItem({ account: bob }) })
			expect(menuItem(wrapper, 'Edit')).toBeUndefined()
			expect(menuItem(wrapper, 'Delete')).toBeUndefined()
			expect(menuItem(wrapper, 'Report')).toBeDefined()
		})

		it('are withheld while the current account is unknown', () => {
			const { wrapper } = mountPost({ currentAccount: null })
			expect(menuItem(wrapper, 'Edit')).toBeUndefined()
			expect(menuItem(wrapper, 'Delete')).toBeUndefined()
		})

		it('offers Pin to profile for an own post and pins it', async () => {
			const { wrapper, item, $store } = mountPost()

			expect(menuItem(wrapper, 'Pin to profile')).toBeDefined()
			await menuItem(wrapper, 'Pin to profile').trigger('click')

			expect($store.dispatch).toHaveBeenCalledWith('postPin', { status: item, pinned: true })
		})

		it('flips to Unpin from profile for a post that is already pinned', async () => {
			const { wrapper, item, $store } = mountPost({ item: makeItem({ pinned: true }) })

			expect(menuItem(wrapper, 'Pin to profile')).toBeUndefined()
			await menuItem(wrapper, 'Unpin from profile').trigger('click')

			expect($store.dispatch).toHaveBeenCalledWith('postPin', { status: item, pinned: false })
		})

		it('never offers pinning for somebody else\'s post or a remote one', () => {
			expect(menuItem(mountPost({ item: makeItem({ account: bob }) }).wrapper, 'Pin to profile')).toBeUndefined()
			expect(menuItem(mountPost({ item: makeItem({ local: false }) }).wrapper, 'Pin to profile')).toBeUndefined()
		})

		it('marks a pinned post in the header', () => {
			expect(mountPost().wrapper.find('.post-pinned').exists()).toBe(false)
			expect(mountPost({ item: makeItem({ pinned: true }) }).wrapper.find('.post-pinned').text()).toBe('Pinned')
		})

		it('deletes the post', async () => {
			const { wrapper, item, $store } = mountPost()
			await menuItem(wrapper, 'Delete').trigger('click')
			expect($store.dispatch).toHaveBeenCalledWith('postDelete', item)
		})

		it('opens an inline editor prefilled with the plain text of the post', async () => {
			const { wrapper } = mountPost({ item: makeItem({ content: '<p>Line one</p><p>Line <b>two</b><br>three</p>' }) })

			await menuItem(wrapper, 'Edit').trigger('click')

			expect(wrapper.find('.post-message').exists()).toBe(false)
			expect(wrapper.find('textarea.post-edit-textarea').element.value).toBe('Line one\nLine two\nthree')
		})

		it('saves the trimmed text and leaves edit mode', async () => {
			const { wrapper, item, $store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('  Edited text  ')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect($store.dispatch).toHaveBeenCalledWith('postEdit', {
				status: item,
				content: 'Edited text',
				spoiler_text: '',
				sensitive: false,
			})
			expect(wrapper.find('textarea').exists()).toBe(false)
			expect(wrapper.find('.post-message').exists()).toBe(true)
		})

		it('saves on Ctrl+Enter', async () => {
			const { wrapper, $store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('Quick fix')
			await wrapper.find('textarea').trigger('keydown', { key: 'Enter', ctrlKey: true })
			await flushPromises()

			expect($store.dispatch).toHaveBeenCalledWith('postEdit', expect.objectContaining({ content: 'Quick fix' }))
		})

		it('refuses to save an empty edit and stays in edit mode', async () => {
			const { wrapper, $store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')

			await wrapper.find('textarea').setValue('   ')
			await wrapper.find('.post-edit-actions button[aria-label="Save"]').trigger('click')
			await flushPromises()

			expect($store.dispatch).not.toHaveBeenCalled()
			expect(wrapper.find('textarea').exists()).toBe(true)
		})

		it('cancels without saving and restores the content', async () => {
			const { wrapper, $store } = mountPost()
			await menuItem(wrapper, 'Edit').trigger('click')
			await wrapper.find('textarea').setValue('Never saved')

			await wrapper.find('.post-edit-actions button[aria-label="Cancel"]').trigger('click')

			expect($store.dispatch).not.toHaveBeenCalled()
			expect(wrapper.find('textarea').exists()).toBe(false)
			expect(wrapper.find('.post-message strong').text()).toBe('world')

			// the draft is not kept for the next edit
			await menuItem(wrapper, 'Edit').trigger('click')
			expect(wrapper.find('textarea').element.value).toBe('Hello world')
		})
	})
})
