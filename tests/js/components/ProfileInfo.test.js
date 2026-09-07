/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ProfileInfo from '../../../src/components/ProfileInfo.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

vi.mock('@nextcloud/dialogs', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

const pristine = structuredClone(account.state)

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}
const FollowButtonStub = {
	name: 'FollowButton',
	props: ['uid'],
	template: '<button class="follow-button-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}
const NcModalStub = {
	name: 'NcModal',
	emits: ['close'],
	template: '<div class="modal-stub"><slot /></div>',
}

const bob = {
	id: 'https://remote.example/users/bob',
	url: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	avatar: 'https://remote.example/media/bob.png',
	header: 'https://remote.example/media/bob-header.png',
	statuses_count: 12,
	following_count: 3,
	followers_count: 5,
	fields: [],
}
const carol = {
	id: 'https://cloud.example.org/users/carol',
	url: 'https://cloud.example.org/users/carol',
	acct: 'carol',
	username: 'carol',
	display_name: null,
	avatar: 'https://cloud.example.org/avatar/carol/128',
	header: '',
	statuses_count: 0,
	following_count: 0,
	followers_count: 1,
	fields: [],
}
const alice = {
	id: 'https://cloud.example.org/users/alice',
	url: 'https://cloud.example.org/users/alice',
	acct: 'alice',
	username: 'alice',
	display_name: 'Alice',
	avatar: 'https://cloud.example.org/avatar/alice/128',
	header: '',
	statuses_count: 7,
	following_count: 2,
	followers_count: 2,
	fields: [],
}

let store

const makeStore = (serverData = {}) => {
	Object.assign(account.state, structuredClone(pristine))
	store = createStore({ modules: { account, settings } })
	store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	for (const data of [bob, carol, alice]) {
		store.commit('addAccount', { actorId: data.url, data })
	}
	return store
}

const mountProfile = (uid) => mount(ProfileInfo, {
	props: { uid },
	global: {
		plugins: [store],
		stubs: { NcAvatar: NcAvatarStub, FollowButton: FollowButtonStub, RouterLink: RouterLinkStub, NcModal: NcModalStub },
	},
})

const linkTexts = (wrapper) => wrapper.findAll('.user-profile__info li').map((li) => li.text().replace(/\s+/g, ' '))
const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)
const bannerOf = (wrapper) => wrapper.find('.user-profile__banner')

describe('ProfileInfo', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.mocked(showError).mockClear()
		vi.mocked(showSuccess).mockClear()
	})

	it('renders nothing for an account that is not in the store', () => {
		expect(mountProfile('nobody@remote.example').find('.user-profile').exists()).toBe(false)
	})

	it('shows the name, the delivered avatar and the counters of a remote account', () => {
		const wrapper = mountProfile('bob@remote.example')
		expect(wrapper.find('h2').text()).toBe('Bob')
		const avatar = wrapper.findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe('https://remote.example/media/bob.png')
		expect(avatar.props('user')).toBeUndefined()
		expect(avatar.props('size')).toBe(128)
		expect(linkTexts(wrapper)).toEqual(['12 posts', '3 following', '5 followers'])
	})

	it('links the counters to the profile sub pages of the shown account', () => {
		const links = mountProfile('bob@remote.example').findAllComponents(RouterLinkStub).map((link) => link.props('to'))
		expect(links).toEqual([
			{ name: 'profile', params: { account: 'bob@remote.example' } },
			{ name: 'profile.following', params: { account: 'bob@remote.example' } },
			{ name: 'profile.followers', params: { account: 'bob@remote.example' } },
		])
	})

	it('uses the server avatar of the uid for a local account and falls back to the username', () => {
		const wrapper = mountProfile('carol')
		const avatar = wrapper.findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('carol')
		expect(avatar.props('url')).toBeUndefined()
		expect(wrapper.find('h2').text()).toBe('carol')
	})

	it('hands the uid to the follow button and does not offer the public follow flow', () => {
		const wrapper = mountProfile('bob@remote.example')
		expect(wrapper.findComponent(FollowButtonStub).props('uid')).toBe('bob@remote.example')
		expect(buttonByText(wrapper, 'Follow')).toBeUndefined()
	})

	it('only lets the viewer edit the banner of their own profile', () => {
		const own = mountProfile('alice')
		expect(buttonByText(own, 'Change banner')).toBeDefined()
		expect(buttonByText(own, 'Set from URL')).toBeDefined()
		expect(bannerOf(own).classes()).toContain('user-profile__banner--editable')

		const other = mountProfile('bob@remote.example')
		expect(buttonByText(other, 'Change banner')).toBeUndefined()
		expect(buttonByText(other, 'Set from URL')).toBeUndefined()
		expect(bannerOf(other).classes()).not.toContain('user-profile__banner--editable')
	})

	it('marks the banner as visible only when the account has a header image', () => {
		expect(bannerOf(mountProfile('bob@remote.example')).classes()).toContain('user-profile__banner--visible')
		expect(bannerOf(mountProfile('carol')).classes()).not.toContain('user-profile__banner--visible')
	})

	it('paints an existing header image onto the banner on first render', async () => {
		const wrapper = mountProfile('bob@remote.example')
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://remote.example/media/bob-header.png')
		expect(bannerOf(wrapper).element.style.backgroundSize).toBe('cover')
	})

	it('paints the header image onto the banner when it changes and clears it when removed', async () => {
		const wrapper = mountProfile('carol')
		store.commit('addAccount', { actorId: carol.url, data: { header: 'https://cloud.example.org/carol-header.png' } })
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/carol-header.png')
		expect(bannerOf(wrapper).element.style.backgroundSize).toBe('cover')
		expect(bannerOf(wrapper).classes()).toContain('user-profile__banner--visible')

		store.commit('addAccount', { actorId: carol.url, data: { header: '' } })
		await nextTick()
		expect(bannerOf(wrapper).element.style.backgroundImage).toBe('')
		expect(bannerOf(wrapper).element.style.backgroundColor).toBe('var(--color-background-dark)')
		expect(bannerOf(wrapper).classes()).not.toContain('user-profile__banner--visible')
	})

	describe('on the public page', () => {
		beforeEach(() => {
			makeStore({ public: true })
		})

		it('offers a follow button that opens the remote follow dialog for the local part of the uid', async () => {
			const open = vi.spyOn(window, 'open').mockImplementation(() => null)
			const wrapper = mountProfile('bob@remote.example')
			await buttonByText(wrapper, 'Follow').trigger('click')
			expect(open).toHaveBeenCalledTimes(1)
			expect(open.mock.calls[0][0]).toBe('/index.php/apps/social/api/v1/ostatus/followRemote/bob')
			expect(open.mock.calls[0][1]).toBe('followRemote')
		})
	})

	describe('banner upload on the own profile', () => {
		let post
		let dispatch

		beforeEach(() => {
			post = vi.spyOn(axios, 'post')
			dispatch = vi.spyOn(store, 'dispatch').mockResolvedValue(alice)
		})

		it('uploads the chosen file, applies the returned banner and refreshes the account', async () => {
			post.mockResolvedValue({ data: { result: { url: 'https://cloud.example.org/banners/alice.png' } } })
			const wrapper = mountProfile('alice')
			const file = new File(['png'], 'banner.png', { type: 'image/png' })
			const input = wrapper.find('input[type="file"]')
			Object.defineProperty(input.element, 'files', { value: [file], configurable: true })

			await input.trigger('change')
			await flushPromises()

			expect(post).toHaveBeenCalledTimes(1)
			const [url, body] = post.mock.calls[0]
			expect(url).toBe('/index.php/apps/social/api/v1/banner')
			expect(body).toBeInstanceOf(FormData)
			expect(body.get('file')).toBe(file)
			expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/banners/alice.png')
			expect(showSuccess).toHaveBeenCalledWith('Banner uploaded successfully')
			expect(dispatch).toHaveBeenCalledWith('fetchAccountInfo', 'alice@cloud.example.org')
			expect(buttonByText(wrapper, 'Change banner').attributes('disabled')).toBeUndefined()
		})

		it('ignores a change event without a file', async () => {
			const wrapper = mountProfile('alice')
			await wrapper.find('input[type="file"]').trigger('change')
			await flushPromises()
			expect(post).not.toHaveBeenCalled()
		})

		it('reports a failed upload and unlocks the buttons again', async () => {
			post.mockRejectedValue(new Error('500'))
			const wrapper = mountProfile('alice')
			const input = wrapper.find('input[type="file"]')
			Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'b.png', { type: 'image/png' })], configurable: true })

			await input.trigger('change')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to upload banner')
			expect(dispatch).not.toHaveBeenCalled()
			expect(buttonByText(wrapper, 'Change banner').attributes('disabled')).toBeUndefined()
		})

		it('sets the banner from a URL through the modal', async () => {
			post.mockResolvedValue({ data: { result: { url: 'https://cloud.example.org/banners/from-url.png' } } })
			const wrapper = mountProfile('alice')
			expect(wrapper.find('.modal-stub').exists()).toBe(false)

			await buttonByText(wrapper, 'Set from URL').trigger('click')
			const modal = wrapper.find('.modal-stub')
			expect(modal.find('h3').text()).toBe('Set banner from URL')
			const apply = buttonByText(modal, 'Apply')
			expect(apply.attributes('disabled')).toBeDefined()

			await modal.find('input[type="url"]').setValue(' https://example.com/image.jpg ')
			expect(apply.attributes('disabled')).toBeUndefined()
			await apply.trigger('click')
			await flushPromises()

			const [url, body, config] = post.mock.calls[0]
			expect(url).toBe('/index.php/apps/social/api/v1/banner/url')
			expect(body).toBeInstanceOf(URLSearchParams)
			expect(body.get('url')).toBe('https://example.com/image.jpg')
			expect(config.headers['Content-Type']).toBe('application/x-www-form-urlencoded')
			expect(wrapper.find('.modal-stub').exists()).toBe(false)
			expect(bannerOf(wrapper).element.style.backgroundImage).toContain('https://cloud.example.org/banners/from-url.png')
			expect(showSuccess).toHaveBeenCalledWith('Banner set successfully')
			expect(dispatch).toHaveBeenCalledWith('fetchAccountInfo', 'alice@cloud.example.org')
		})

		it('keeps the modal open and reports when the URL cannot be fetched', async () => {
			post.mockRejectedValue(new Error('400'))
			const wrapper = mountProfile('alice')
			await buttonByText(wrapper, 'Set from URL').trigger('click')
			await wrapper.find('.modal-stub input[type="url"]').setValue('https://example.com/broken.jpg')
			await buttonByText(wrapper.find('.modal-stub'), 'Apply').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to set banner from URL')
			expect(wrapper.find('.modal-stub').exists()).toBe(true)
			expect(buttonByText(wrapper.find('.modal-stub'), 'Apply').attributes('disabled')).toBeUndefined()
		})
	})
})
