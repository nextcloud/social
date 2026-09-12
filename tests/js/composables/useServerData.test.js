/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { loadState } from '@nextcloud/initial-state'

import { useServerData } from '../../../src/composables/useServerData.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const { setInitialState } = globalThis

// a component is what a composable needs to live in; this one exposes what it
// returns so the assertions can read it
const Probe = {
	setup() {
		return useServerData()
	},
	render: () => h('div'),
}

describe('useServerData', () => {
	let pinia
	let settingsStore
	let wrapper

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		settingsStore = useSettingsStore()
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
		settingsStore.setServerData(loadState('social', 'serverData'))
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.serverData).toEqual(injected)
	})

	it('derives the hostname from the cloud address, ignoring scheme, port and path', () => {
		settingsStore.setServerData({ cloudAddress: 'https://cloud.example.org:8443/nextcloud/index.php' })
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.hostname).toBe('cloud.example.org')
	})

	it('falls back to the page host while the cloud address is not loaded', () => {
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.hostname).toBe(window.location.hostname)
	})

	it('is an empty object until the server data is loaded', () => {
		wrapper = mount(Probe, { global: { plugins: [pinia] } })

		expect(wrapper.vm.serverData).toEqual({})
	})
})
