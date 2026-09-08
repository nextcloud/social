/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createStore } from 'vuex'
import { h } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'

import currentUserMixin from '../../../src/mixins/currentUserMixin.js'
import settings from '../../../src/store/settings.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn() }))

const Probe = {
	mixins: [currentUserMixin],
	render: () => h('div'),
}

describe('currentUserMixin', () => {
	let store
	let wrapper

	beforeEach(() => {
		getCurrentUser.mockReturnValue({ uid: 'alice', displayName: 'Alice', isAdmin: false })
		settings.state.serverData = {}
		store = createStore({ modules: { settings } })
	})

	afterEach(() => {
		wrapper?.unmount()
		vi.clearAllMocks()
	})

	it('exposes the logged-in user from @nextcloud/auth', () => {
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.currentUser).toEqual({ uid: 'alice', displayName: 'Alice', isAdmin: false })
	})

	it('builds the cloud id from the user id and the configured cloud address host', () => {
		store.commit('setServerData', { cloudAddress: 'https://cloud.example.org:8443/nextcloud' })
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.cloudId).toBe('alice@cloud.example.org')
	})

	it('socialId is the cloud id prefixed with @', () => {
		store.commit('setServerData', { cloudAddress: 'https://cloud.example.org' })
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.socialId).toBe('@alice@cloud.example.org')
	})

	it('recomputes the ids when the cloud address is loaded later', async () => {
		wrapper = mount(Probe, { global: { plugins: [store] } })
		const before = wrapper.vm.cloudId

		store.commit('setServerData', { cloudAddress: 'https://social.example.net' })
		await wrapper.vm.$nextTick()

		expect(before).not.toBe('alice@social.example.net')
		expect(wrapper.vm.cloudId).toBe('alice@social.example.net')
	})
})
