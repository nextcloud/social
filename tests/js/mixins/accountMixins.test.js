/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createStore } from 'vuex'
import { h } from 'vue'

import accountMixins from '../../../src/mixins/accountMixins.js'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

const alice = { id: '11', acct: 'alice', username: 'alice', display_name: 'Alice', url: 'https://cloud.example.org/@alice' }
const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', url: 'https://remote.tld/@bob' }

const Probe = {
	mixins: [accountMixins],
	props: { uid: { type: String, default: '' } },
	render: () => h('div'),
}

describe('accountMixins', () => {
	let store
	let wrapper

	const mountWith = (uid) => {
		wrapper = mount(Probe, { props: { uid }, global: { plugins: [store] } })
		return wrapper.vm
	}

	beforeEach(() => {
		vi.spyOn(console, 'debug').mockImplementation(() => {})
		Object.assign(account.state, {
			currentAccount: '',
			accounts: {},
			accountsFollowers: {},
			accountsFollowings: {},
			accountsRelationships: {},
			accountIdMap: {},
		})
		settings.state.serverData = {}
		store = createStore({ modules: { account, settings } })
		store.commit('setServerData', { cloudAddress: 'https://cloud.example.org' })
	})

	afterEach(() => {
		wrapper?.unmount()
		vi.restoreAllMocks()
	})

	it('profileAccount qualifies a local user id with the cloud hostname', () => {
		expect(mountWith('alice').profileAccount).toBe('alice@cloud.example.org')
	})

	it('profileAccount keeps a remote handle as it is', () => {
		expect(mountWith('bob@remote.tld').profileAccount).toBe('bob@remote.tld')
	})

	it('profileAccount is empty without a uid', () => {
		expect(mountWith('').profileAccount).toBe('')
	})

	it('accountInfo and accountLoaded reflect whether the account is in the store', async () => {
		const vm = mountWith('alice')
		expect(vm.accountLoaded).toBe(false)
		expect(vm.accountInfo).toBeUndefined()

		store.commit('addAccount', { actorId: alice.url, data: alice })
		await wrapper.vm.$nextTick()

		expect(vm.accountLoaded).toBe(true)
		expect(vm.accountInfo).toEqual(alice)
	})

	it('isLocal is true for accounts of this server and false for remote ones', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })
		store.commit('addAccount', { actorId: bob.url, data: bob })

		expect(mountWith('alice').isLocal).toBe(true)
		wrapper.unmount()
		expect(mountWith('bob@remote.tld').isLocal).toBe(false)
	})

	it('isLocal is falsy while the account is not loaded', () => {
		expect(mountWith('alice').isLocal).toBeFalsy()
	})

	it('relationship looks up the relationship by the account id once both are loaded', async () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		const vm = mountWith('bob@remote.tld')
		expect(vm.relationship).toBeUndefined()

		const relationship = { id: bob.id, following: true, requested: false }
		store.commit('addRelationship', { actorId: bob.id, data: relationship })
		await wrapper.vm.$nextTick()

		expect(vm.relationship).toEqual(relationship)
	})

	it('relationship is falsy when the account itself is unknown', () => {
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: true } })

		expect(mountWith('bob@remote.tld').relationship).toBeFalsy()
	})
})
