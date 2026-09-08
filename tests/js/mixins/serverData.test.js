/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createStore } from 'vuex'
import { h } from 'vue'
import { loadState } from '@nextcloud/initial-state'

import serverData from '../../../src/mixins/serverData.js'
import settings from '../../../src/store/settings.js'

const { setInitialState } = globalThis

const Probe = {
	mixins: [serverData],
	render: () => h('div'),
}

describe('serverData mixin', () => {
	let store
	let wrapper

	beforeEach(() => {
		settings.state.serverData = {}
		store = createStore({ modules: { settings } })
	})

	afterEach(() => {
		wrapper?.unmount()
	})

	it('returns the server data the app loaded from the initial state', () => {
		const injected = {
			cloudAddress: 'https://cloud.example.org',
			firstrun: true,
			isAdmin: false,
			public: false,
			setup: false,
			cliUrl: 'https://cloud.example.org',
		}
		setInitialState('social', 'serverData', injected)
		store.commit('setServerData', loadState('social', 'serverData'))
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.serverData).toEqual(injected)
	})

	it('derives the hostname from the cloud address, ignoring scheme, port and path', () => {
		store.commit('setServerData', { cloudAddress: 'https://cloud.example.org:8443/nextcloud/index.php' })
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.hostname).toBe('cloud.example.org')
	})

	it('falls back to the page host while the cloud address is not loaded', () => {
		wrapper = mount(Probe, { global: { plugins: [store] } })

		expect(wrapper.vm.hostname).toBe(window.location.hostname)
	})

	it('returns an empty object when no store is installed', () => {
		wrapper = mount(Probe)

		expect(wrapper.vm.serverData).toEqual({})
	})
})
