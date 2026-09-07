/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { createStore } from 'vuex'

import settings from '../../../src/store/settings.js'

describe('settings store', () => {
	let store

	beforeEach(() => {
		settings.state.serverData = {}
		store = createStore({ modules: { settings } })
	})

	it('starts with an empty serverData object', () => {
		expect(store.state.settings.serverData).toEqual({})
		expect(store.getters.getServerData).toEqual({})
	})

	it('setServerData replaces the whole server data object', () => {
		const serverData = { cloudAddress: 'https://cloud.example.org', firstrun: true, isAdmin: false, public: false, setup: false }

		store.commit('setServerData', serverData)

		expect(store.getters.getServerData).toEqual(serverData)

		store.commit('setServerData', { public: true })

		expect(store.getters.getServerData).toEqual({ public: true })
	})

	it('setServerDataEntry sets a single key from an object payload, including falsy values', () => {
		store.commit('setServerData', { setup: true, cloudAddress: 'https://cloud.example.org' })

		store.commit('setServerDataEntry', { key: 'setup', value: false })

		expect(store.getters.getServerData.setup).toBe(false)
		expect(store.getters.getServerData.cloudAddress).toBe('https://cloud.example.org')

		store.commit('setServerDataEntry', { key: 'cloudAddress', value: 'https://social.example.org' })

		expect(store.getters.getServerData.cloudAddress).toBe('https://social.example.org')
	})

	it('exposes the getter reactively so components see the data loaded later', () => {
		const before = store.getters.getServerData

		store.commit('setServerData', { firstrun: false })

		expect(before).toEqual({})
		expect(store.getters.getServerData).toEqual({ firstrun: false })
	})
})
