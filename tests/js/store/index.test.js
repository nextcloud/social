/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import store from '../../../src/store/index.js'

describe('root store', () => {
	afterEach(() => {
		vi.unstubAllEnvs()
		vi.restoreAllMocks()
	})

	it('registers the timeline, account, settings, errors and notifications modules without namespaces', () => {
		expect(store.hasModule('timeline')).toBe(true)
		expect(store.hasModule('account')).toBe(true)
		expect(store.hasModule('settings')).toBe(true)
		expect(store.hasModule('errors')).toBe(true)
		expect(store.hasModule('notifications')).toBe(true)
		expect(Object.keys(store.state).sort()).toEqual(['account', 'errors', 'notifications', 'settings', 'timeline'])

		expect(store.getters.getTimeline).toEqual([])
		expect(typeof store.getters.getAccount).toBe('function')
		expect(store.getters.getServerData).toEqual({})
		expect(store.getters.hasErrors).toBe(false)
	})

	it('lets modules dispatch each other\'s actions through the shared namespace', async () => {
		await store.dispatch('addAppError', { title: 't', message: 'm' })

		expect(store.getters.appErrors).toHaveLength(1)

		store.commit('clearErrors')
	})

	it('runs in strict mode outside production and rejects mutations made outside a handler', () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		expect(store.strict).toBe(true)

		expect(() => {
			store.state.settings.serverData = { tampered: true }
		}).toThrow(/do not mutate vuex store state outside mutation handlers/)
	})

	it('disables strict mode in production builds', async () => {
		vi.stubEnv('NODE_ENV', 'production')
		vi.resetModules()

		const { default: productionStore } = await import('../../../src/store/index.js')

		expect(productionStore.strict).toBe(false)
		expect(productionStore.hasModule('timeline')).toBe(true)
	})
})
