/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import Navigation from '../../../src/components/Navigation.vue'
import errors from '../../../src/store/errors.js'
import settings from '../../../src/store/settings.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

// Simple stand-ins for the @nextcloud/vue navigation shell that render their
// slots and expose the props the component drives them with.
const stubs = {
	NcAppNavigation: { template: '<nav><slot name="search" /><slot name="list" /><slot name="footer" /></nav>' },
	NcAppNavigationSearch: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input class="nav-search" :value="modelValue" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcAppNavigationItem: {
		props: ['name', 'active', 'counter', 'href', 'target'],
		emits: ['click'],
		template: '<li class="nav-item" :class="{ active }" :data-name="name" :data-counter="counter" :data-href="href" @click="$emit(\'click\')">'
			+ '<slot name="icon" /><span class="nav-item__name">{{ name }}</span><slot name="subname" /><slot /></li>',
	},
	NcAppNavigationSpacer: { template: '<hr>' },
	NcAppNavigationSettings: { props: ['name'], template: '<div class="nav-settings" :data-name="name"><slot /></div>' },
	NcAvatar: { props: ['user', 'displayName', 'size'], template: '<span class="nc-avatar-stub" :data-user="user" />' },
	NcModal: { props: ['name'], emits: ['close'], template: '<div class="modal-stub" :data-name="name"><slot /></div>' },
	Composer: { template: '<div class="composer-stub" />' },
}

let store
let router

const mountNavigation = (route = { name: 'timeline', params: {} }) => mount(Navigation, {
	global: { plugins: [store], mocks: { $route: route, $router: router }, stubs },
})

const items = (wrapper) => wrapper.findAll('.nav-item')
const itemNames = (wrapper) => items(wrapper).map((item) => item.attributes('data-name'))
const item = (wrapper, name) => items(wrapper).find((candidate) => candidate.attributes('data-name') === name)

describe('Navigation', () => {
	beforeEach(() => {
		store = createStore({ modules: { errors, settings } })
		store.commit('clearErrors')
		store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org' })
		router = { push: vi.fn() }
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('lists the fixed entries in order, without an errors entry when there are none', () => {
		expect(itemNames(mountNavigation())).toEqual([
			'New post',
			'Home',
			'Notifications',
			'Direct messages',
			'Local',
			'Global',
			'Liked posts',
			'Profile',
			'Reset local cache',
			'Help & documentation',
		])
	})

	it.each([
		['Home', { name: 'timeline' }],
		['Notifications', { name: 'timeline', params: { type: 'notifications' } }],
		['Direct messages', { name: 'timeline', params: { type: 'direct' } }],
		['Local', { name: 'timeline', params: { type: 'timeline' } }],
		['Global', { name: 'timeline', params: { type: 'federated' } }],
		['Liked posts', { name: 'timeline', params: { type: 'favourites' } }],
		['Profile', { name: 'profile', params: { account: 'alice' } }],
	])('navigates to the %s timeline on click', async (name, to) => {
		const wrapper = mountNavigation()
		await item(wrapper, name).trigger('click')
		expect(router.push).toHaveBeenCalledWith(to)
	})

	it('shows the notifications counter placeholder', () => {
		expect(item(mountNavigation(), 'Notifications').attributes('data-counter')).toBe('0')
	})

	it.each([
		[{ name: 'timeline', params: {} }, 'Home'],
		[{ name: 'timeline', params: { type: 'federated' } }, 'Global'],
		[{ name: 'timeline', params: { type: 'direct' } }, 'Direct messages'],
		[{ name: 'profile', params: { account: 'alice' } }, 'Profile'],
		[{ name: 'profile.followers', params: { account: 'alice' } }, 'Profile'],
	])('marks only the entry matching route %o as active', (route, active) => {
		const wrapper = mountNavigation(route)
		expect(items(wrapper).filter((candidate) => candidate.classes('active')).map((candidate) => candidate.attributes('data-name'))).toEqual([active])
	})

	it('does not mark the own profile entry active for somebody else\'s profile', () => {
		const wrapper = mountNavigation({ name: 'profile', params: { account: 'bob@remote.example' } })
		expect(items(wrapper).filter((candidate) => candidate.classes('active'))).toHaveLength(0)
	})

	it('shows the current user on the profile entry', () => {
		const profile = item(mountNavigation(), 'Profile')
		expect(profile.find('.nc-avatar-stub').attributes('data-user')).toBe('alice')
		expect(profile.find('.navigation__subname').text()).toBe('@alice')
	})

	it('emits the search term as the user types', async () => {
		const wrapper = mountNavigation()
		await wrapper.find('.nav-search').setValue('nextcloud')
		expect(wrapper.emitted('search')).toEqual([['nextcloud']])
	})

	it('opens the composer modal from "New post"', async () => {
		const wrapper = mountNavigation()
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
		await item(wrapper, 'New post').trigger('click')
		const modal = wrapper.find('.modal-stub')
		expect(modal.attributes('data-name')).toBe('New post')
		expect(modal.find('.composer-stub').exists()).toBe(true)
	})

	it('emits reset-cache from the settings entry and links to the documentation', async () => {
		const wrapper = mountNavigation()
		expect(wrapper.find('.nav-settings').attributes('data-name')).toBe('Settings')
		await item(wrapper, 'Reset local cache').trigger('click')
		expect(wrapper.emitted('reset-cache')).toHaveLength(1)
		expect(item(wrapper, 'Help & documentation').attributes('data-href')).toBe('https://github.com/SchBenedikt/social/')
	})

	describe('errors', () => {
		beforeEach(() => {
			vi.spyOn(Date, 'now').mockReturnValueOnce(1).mockReturnValueOnce(2)
			store.commit('addError', { title: 'Account lookup failed', message: 'Could not load bob' })
			store.commit('addError', { title: 'Post failed', message: 'Server unreachable' })
		})

		it('adds an errors entry with the error count', () => {
			const wrapper = mountNavigation()
			expect(itemNames(wrapper).slice(0, 3)).toEqual(['New post', 'Errors', 'Home'])
			expect(item(wrapper, 'Errors').attributes('data-counter')).toBe('2')
		})

		it('opens a modal listing the errors and dismisses a single one through the store', async () => {
			const dispatch = vi.spyOn(store, 'dispatch')
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')

			const modal = wrapper.find('.modal-stub[data-name="Errors"]')
			expect(modal.findAll('.modal-errors__title').map((title) => title.text())).toEqual(['Account lookup failed', 'Post failed'])
			expect(modal.findAll('.modal-errors__message').map((message) => message.text())).toEqual(['Could not load bob', 'Server unreachable'])

			await modal.find('.modal-errors__item button').trigger('click')
			expect(dispatch).toHaveBeenCalledWith('dismissAppError', 1)
			await nextTick()
			expect(modal.findAll('.modal-errors__title').map((title) => title.text())).toEqual(['Post failed'])
			expect(item(wrapper, 'Errors').attributes('data-counter')).toBe('1')
		})

		it('offers "Dismiss all" only for several errors and clears them all', async () => {
			const commit = vi.spyOn(store, 'commit')
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')

			const dismissAll = () => wrapper.findAll('.modal-stub button').filter((button) => button.text() === 'Dismiss all')
			expect(dismissAll()).toHaveLength(1)

			await dismissAll()[0].trigger('click')
			expect(commit).toHaveBeenCalledWith('clearErrors')
			await nextTick()
			expect(wrapper.find('.modal-errors__item').exists()).toBe(false)
			expect(dismissAll()).toHaveLength(0)
			expect(item(wrapper, 'Errors')).toBeUndefined()
		})

		it('hides "Dismiss all" when only one error is left', async () => {
			store.commit('dismissError', 2)
			const wrapper = mountNavigation()
			await item(wrapper, 'Errors').trigger('click')
			expect(wrapper.findAll('.modal-stub button').map((button) => button.text())).toEqual(['Dismiss'])
		})
	})
})
