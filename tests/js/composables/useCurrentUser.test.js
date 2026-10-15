/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'

import { useCurrentUser } from '../../../src/composables/useCurrentUser.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn() }))

const Probe = {
	setup() {
		return useCurrentUser()
	},
	render: () => h('div'),
}

describe('useCurrentUser', () => {
	let pinia
	let settingsStore
	let wrapper

	beforeEach(() => {
		getCurrentUser.mockReturnValue({ uid: 'alice', displayName: 'Alice', isAdmin: false })
		pinia = createPinia()
		setActivePinia(pinia)
		settingsStore = useSettingsStore()
	})

	afterEach(() => {
		wrapper?.unmount()
		vi.clearAllMocks()
	})

	it('exposes the logged-in user from @nextcloud/auth', () => {
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.currentUser).toEqual({ uid: 'alice', displayName: 'Alice', isAdmin: false })
	})

	it('names the account by the username the server says, not by the user id', () => {
		settingsStore.setServerData({
			cloudAddress: 'https://cloud.example.org:8443/nextcloud',
			viewer: { username: 'aloha_al', address: 'aloha_al@example.org' },
		})
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.username).toBe('aloha_al')
		expect(wrapper.vm.cloudId).toBe('aloha_al@example.org')
		expect(wrapper.vm.socialId).toBe('@aloha_al@example.org')
		// the user id is still the Nextcloud user's
		expect(wrapper.vm.currentUser.uid).toBe('alice')
	})

	it('has no address before there is an account', () => {
		settingsStore.setServerData({ cloudAddress: 'https://cloud.example.org', needsAccount: true })
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.username).toBe('')
		expect(wrapper.vm.cloudId).toBe('')
		expect(wrapper.vm.socialId).toBe('')
		expect(wrapper.vm.isOwnHandle('alice')).toBe(false)
	})

	it('follows the server data when it changes', async () => {
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		settingsStore.setServerData({ viewer: { username: 'aloha_al', address: 'aloha_al@social.example.net' } })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.socialId).toBe('@aloha_al@social.example.net')
	})

	it('knows the reader\'s own handle in every spelling, and nobody else\'s', () => {
		settingsStore.setServerData({
			cloudAddress: 'https://cloud.example.org',
			viewer: { username: 'aloha_al', address: 'aloha_al@example.org' },
		})
		wrapper = mount(Probe, { global: { plugins: [pinia] } })
		const { isOwnHandle } = wrapper.vm

		expect(isOwnHandle('aloha_al')).toBe(true)
		expect(isOwnHandle('@Aloha_Al@cloud.example.org')).toBe(true)
		expect(isOwnHandle('aloha_al@example.org')).toBe(true)
		// the user id names another account here, or none
		expect(isOwnHandle('alice')).toBe(false)
		// and the same name on another server is somebody else
		expect(isOwnHandle('aloha_al@mastodon.example')).toBe(false)
		expect(isOwnHandle('')).toBe(false)
		expect(isOwnHandle(undefined)).toBe(false)
	})
})
