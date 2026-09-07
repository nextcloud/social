/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import { flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import account from '../../../src/store/account.js'
import errors from '../../../src/store/errors.js'
import logger from '../../../src/services/logger.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const alice = { id: '11', acct: 'alice', username: 'alice', display_name: 'Alice', url: 'https://cloud.example.org/@alice' }
const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', url: 'https://remote.tld/@bob' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', url: 'https://remote.tld/@carol' }
const ALICE = 'alice@cloud.example.org'

const freshState = () => ({
	currentAccount: '',
	accounts: {},
	accountsFollowers: {},
	accountsFollowings: {},
	accountsRelationships: {},
	accountIdMap: {},
	accountsFollowersMaxId: {},
	accountsFollowingsMaxId: {},
	accountsFollowersLoading: {},
	accountsFollowingsLoading: {},
	accountsFollowersAllLoaded: {},
	accountsFollowingsAllLoaded: {},
})

const defaultRelationship = (id, following) => ({
	id,
	following,
	showing_reblogs: false,
	notifying: false,
	followed_by: false,
	blocking: false,
	blocked_by: false,
	muting: false,
	muting_notifications: false,
	requested: false,
	domain_blocking: false,
	endorsed: false,
})

let store

beforeEach(() => {
	vi.resetAllMocks()
	vi.spyOn(console, 'debug').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	// The module keeps a single state object that its helpers read directly,
	// so it is reset in place rather than replaced.
	Object.assign(account.state, freshState())
	errors.state.errors = []
	store = createStore({ modules: { account, errors } })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('account store state', () => {
	it('starts with empty maps and no current account', () => {
		expect(store.state.account).toEqual(freshState())
	})
})

describe('account store mutations and getters', () => {
	it('addAccount indexes a local account by actor url and maps acct@host to it', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })

		const state = store.state.account
		expect(state.accounts[alice.url]).toEqual(alice)
		expect(state.accountIdMap).toEqual({ [ALICE]: alice.url })
		expect(state.accountsFollowers[alice.url]).toEqual([])
		expect(state.accountsFollowings[alice.url]).toEqual([])
	})

	it('addAccount keeps a remote acct as the map key and merges partial updates', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addAccount', { actorId: bob.url, data: { id: '22', acct: bob.acct, url: bob.url, note: '<p>hi</p>' } })

		expect(store.state.account.accountIdMap).toEqual({ 'bob@remote.tld': bob.url })
		expect(store.state.account.accounts[bob.url]).toEqual({ ...bob, note: '<p>hi</p>' })
	})

	it('addAccount without an acct stores the account but adds no handle mapping', () => {
		store.commit('addAccount', { actorId: 'https://x.tld/y', data: { id: '1', url: 'https://x.tld/y' } })

		expect(store.state.account.accounts['https://x.tld/y']).toEqual({ id: '1', url: 'https://x.tld/y' })
		expect(store.state.account.accountIdMap).toEqual({})
	})

	it('getAccount, accountLoaded, getAllAccounts and getActorIdForAccount resolve a handle through the map', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })

		expect(store.getters.getAccount(ALICE)).toEqual(alice)
		expect(store.getters.accountLoaded(ALICE)).toEqual(alice)
		expect(store.getters.getAccount('nobody@remote.tld')).toBeUndefined()
		expect(store.getters.accountLoaded('nobody@remote.tld')).toBeUndefined()
		expect(store.getters.getAllAccounts()).toEqual({ [alice.url]: alice })
		expect(store.getters.getActorIdForAccount(ALICE)).toBe(alice.url)
	})

	it('currentAccount is the account named by setCurrentAccount', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })
		expect(store.getters.currentAccount).toBeUndefined()

		store.commit('setCurrentAccount', ALICE)

		expect(store.state.account.currentAccount).toBe(ALICE)
		expect(store.getters.currentAccount).toEqual(alice)
	})

	it('addRelationship stores a relationship by account id', () => {
		const relationship = { id: '22', following: true, followed_by: false }

		store.commit('addRelationship', { actorId: '22', data: relationship })

		expect(store.getters.getRelationshipWith('22')).toEqual(relationship)
		expect(store.getters.getRelationshipWith('11')).toBeUndefined()
	})

	it('isFollowingUser says yes for the handle of an account the user follows', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: true } })

		expect(store.getters.isFollowingUser(bob.acct)).toBe(true)
	})

	it('isFollowingUser says no for the handle of an account the user does not follow', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: false } })

		expect(store.getters.isFollowingUser(bob.acct)).toBe(false)
	})

	it('isFollowingUser also answers for the plain account id, which the remote follow dialog passes', () => {
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: true } })

		expect(store.getters.isFollowingUser(bob.id)).toBe(true)
	})

	it('isFollowingUser says no while the relationship of a known account has not been loaded', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })

		expect(store.getters.isFollowingUser(bob.acct)).toBe(false)
	})

	it('isFollowingUser says no for a handle that is unknown, instead of failing', () => {
		expect(() => store.getters.isFollowingUser('nobody@remote.tld')).not.toThrow()
		expect(store.getters.isFollowingUser('nobody@remote.tld')).toBe(false)
	})

	it('addFollowers replaces the list, indexes each follower and remembers the last id', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })

		store.commit('addFollowers', { account: ALICE, data: [bob, carol] })

		expect(store.getters.getAccountFollowers(ALICE)).toEqual([bob, carol])
		expect(store.getters.getAccount('carol@remote.tld')).toEqual(carol)
		expect(store.state.account.accountsFollowersMaxId[alice.url]).toBe('33')
		expect(store.state.account.accountsFollowersAllLoaded[alice.url]).toBe(false)

		store.commit('addFollowers', { account: ALICE, data: [carol] })
		expect(store.getters.getAccountFollowers(ALICE)).toEqual([carol])
	})

	it('addFollowersAppend extends the list with the next page', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })
		store.commit('addFollowers', { account: ALICE, data: [bob] })

		store.commit('addFollowersAppend', { account: ALICE, data: [carol] })

		expect(store.getters.getAccountFollowers(ALICE)).toEqual([bob, carol])
		expect(store.state.account.accountsFollowersMaxId[alice.url]).toBe('33')
	})

	it('addFollowing and addFollowingAppend maintain the following list the same way', () => {
		store.commit('addAccount', { actorId: alice.url, data: alice })

		store.commit('addFollowing', { account: ALICE, data: [bob] })
		expect(store.getters.getAccountFollowing(ALICE)).toEqual([bob])
		expect(store.state.account.accountsFollowingsMaxId[alice.url]).toBe('22')
		expect(store.state.account.accountsFollowingsAllLoaded[alice.url]).toBe(false)

		store.commit('addFollowingAppend', { account: ALICE, data: [carol] })
		expect(store.getters.getAccountFollowing(ALICE)).toEqual([bob, carol])
		expect(store.state.account.accountsFollowingsMaxId[alice.url]).toBe('33')

		store.commit('addFollowing', { account: ALICE, data: [] })
		expect(store.getters.getAccountFollowing(ALICE)).toEqual([])
	})

	it('follower lists use the raw key for accounts that are not loaded', () => {
		store.commit('addFollowers', { account: 'dave@remote.tld', data: [bob] })

		expect(store.state.account.accountsFollowers['dave@remote.tld']).toEqual([bob.url])
		expect(store.getters.getAccountFollowers('dave@remote.tld')).toEqual([bob])
		expect(store.getters.getAccountFollowers('nobody@remote.tld')).toEqual([])
	})

	it('followAccount flips the relationship of a loaded account to following', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: false, followed_by: true } })

		store.commit('followAccount', bob.acct)

		expect(store.getters.getRelationshipWith(bob.id)).toEqual({ id: bob.id, following: true, followed_by: true })
	})

	it('followAccount creates a default relationship when none was loaded yet', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })

		store.commit('followAccount', bob.acct)

		expect(store.getters.getRelationshipWith(bob.id)).toEqual(defaultRelationship(bob.id, true))
	})

	it('unfollowAccount flips the relationship back, creating a default one if needed', () => {
		store.commit('addAccount', { actorId: bob.url, data: bob })
		store.commit('addAccount', { actorId: carol.url, data: carol })
		store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: true } })

		store.commit('unfollowAccount', bob.acct)
		store.commit('unfollowAccount', carol.acct)

		expect(store.getters.getRelationshipWith(bob.id)).toEqual({ id: bob.id, following: false })
		expect(store.getters.getRelationshipWith(carol.id)).toEqual(defaultRelationship(carol.id, false))
	})

	it('followAccount and unfollowAccount leave relationships alone for accounts that are not loaded', () => {
		store.commit('followAccount', 'nobody@remote.tld')
		store.commit('unfollowAccount', 'nobody@remote.tld')

		expect(store.state.account.accountsRelationships).toEqual({})
	})
})

describe('account store actions', () => {
	describe('fetchAccountInfo', () => {
		it('GETs the global account info and indexes the result', async () => {
			axios.get.mockResolvedValue({ data: bob })

			const result = await store.dispatch('fetchAccountInfo', bob.acct)

			expect(axios.get).toHaveBeenCalledWith(`${API}/global/account/info?account=bob@remote.tld`)
			expect(result).toEqual(bob)
			expect(store.getters.getAccount(bob.acct)).toEqual(bob)
		})

		it('records an app error instead of throwing when the lookup fails', async () => {
			axios.get.mockRejectedValue(new Error('unreachable'))

			await expect(store.dispatch('fetchAccountInfo', bob.acct)).resolves.toBeUndefined()

			expect(store.getters.appErrors).toEqual([expect.objectContaining({
				title: 'Account lookup failed',
				message: 'Could not load account bob@remote.tld. The remote server may be unreachable.',
			})])
			expect(logger.error).toHaveBeenCalledWith('Failed to load account details', { error: expect.any(Error) })
			expect(store.getters.getAccount(bob.acct)).toBeUndefined()
		})
	})

	describe('fetchPublicAccountInfo', () => {
		it('GETs the public info of a local user and indexes the result', async () => {
			axios.get.mockResolvedValue({ data: alice })

			const result = await store.dispatch('fetchPublicAccountInfo', 'alice')

			expect(axios.get).toHaveBeenCalledWith(`${API}/account/alice/info`)
			expect(result).toEqual(alice)
			expect(store.getters.getAccount(ALICE)).toEqual(alice)
		})

		it('records an app error naming the uid when the lookup fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('fetchPublicAccountInfo', 'alice')).resolves.toBeUndefined()

			expect(store.getters.appErrors).toEqual([expect.objectContaining({
				title: 'Account lookup failed',
				message: 'Could not load account alice. The remote server may be unreachable.',
			})])
		})
	})

	describe('fetchAccountRelationshipInfo', () => {
		it('GETs the relationships for the given ids and stores each one by id', async () => {
			const relationships = [{ id: '11', following: false }, { id: '22', following: true }]
			axios.get.mockResolvedValue({ data: relationships })

			const result = await store.dispatch('fetchAccountRelationshipInfo', ['11', '22'])

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/relationships`, { params: { id: ['11', '22'] } })
			expect(result).toEqual(relationships)
			expect(store.getters.getRelationshipWith('11')).toEqual(relationships[0])
			expect(store.getters.getRelationshipWith('22')).toEqual(relationships[1])
		})

		it('shows an error and resolves to undefined when the request fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('fetchAccountRelationshipInfo', ['11'])).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Failed to load relationship info')
			expect(store.state.account.accountsRelationships).toEqual({})
		})
	})

	it('fetchCurrentAccountInfo remembers the current account and loads its info', async () => {
		axios.get.mockResolvedValue({ data: alice })

		await store.dispatch('fetchCurrentAccountInfo', ALICE)
		await flushPromises()

		expect(store.state.account.currentAccount).toBe(ALICE)
		expect(axios.get).toHaveBeenCalledWith(`${API}/global/account/info?account=${ALICE}`)
		expect(store.getters.currentAccount).toEqual(alice)
	})

	describe('followAccount', () => {
		beforeEach(() => {
			store.commit('addAccount', { actorId: bob.url, data: bob })
		})

		it('PUTs to the follow endpoint with the encoded handle and marks the relationship as following', async () => {
			const response = { data: { status: 1, result: [] } }
			axios.put.mockResolvedValue(response)

			await expect(store.dispatch('followAccount', { accountToFollow: bob.acct })).resolves.toBe(response)

			expect(axios.put).toHaveBeenCalledWith(`${API}/current/follow?account=bob%40remote.tld`)
			expect(store.getters.getRelationshipWith(bob.id)).toMatchObject({ following: true })
			expect(showError).not.toHaveBeenCalled()
		})

		it('rejects with the response and changes nothing when the server reports status -1', async () => {
			const response = { data: { status: -1, message: 'nope' } }
			axios.put.mockResolvedValue(response)

			await expect(store.dispatch('followAccount', { accountToFollow: bob.acct })).rejects.toBe(response)

			expect(store.getters.getRelationshipWith(bob.id)).toBeUndefined()
			expect(showError).not.toHaveBeenCalled()
		})

		it('shows an error and resolves to undefined on a network failure', async () => {
			axios.put.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('followAccount', { accountToFollow: bob.acct })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Failed to follow user bob@remote.tld')
			expect(logger.error).toHaveBeenCalledWith('Failed to follow user bob@remote.tld', { error: expect.any(Error) })
			expect(store.getters.getRelationshipWith(bob.id)).toBeUndefined()
		})
	})

	describe('unfollowAccount', () => {
		beforeEach(() => {
			store.commit('addAccount', { actorId: bob.url, data: bob })
			store.commit('addRelationship', { actorId: bob.id, data: { id: bob.id, following: true } })
		})

		it('DELETEs the follow and marks the relationship as not following', async () => {
			const response = { data: { status: 1, result: [] } }
			axios.delete.mockResolvedValue(response)

			await expect(store.dispatch('unfollowAccount', { accountToUnfollow: bob.acct })).resolves.toBe(response)

			expect(axios.delete).toHaveBeenCalledWith(`${API}/current/follow?account=bob%40remote.tld`)
			expect(store.getters.getRelationshipWith(bob.id)).toEqual({ id: bob.id, following: false })
		})

		it('rejects with the response and keeps following when the server reports status -1', async () => {
			const response = { data: { status: -1, message: 'nope' } }
			axios.delete.mockResolvedValue(response)

			await expect(store.dispatch('unfollowAccount', { accountToUnfollow: bob.acct })).rejects.toBe(response)

			expect(store.getters.getRelationshipWith(bob.id)).toMatchObject({ following: true })
		})

		it('shows an error and resolves with the error on a network failure', async () => {
			const error = new Error('boom')
			axios.delete.mockRejectedValue(error)

			await expect(store.dispatch('unfollowAccount', { accountToUnfollow: bob.acct })).resolves.toBe(error)

			expect(showError).toHaveBeenCalledWith('Failed to unfollow user bob@remote.tld')
			expect(store.getters.getRelationshipWith(bob.id)).toMatchObject({ following: true })
		})
	})

	describe('fetchAccountFollowers', () => {
		beforeEach(() => {
			store.commit('addAccount', { actorId: alice.url, data: alice })
		})

		it('loads the first page, marks a short page as complete and clears the loading flag', async () => {
			let loadingDuringRequest
			axios.get.mockImplementation(async () => {
				loadingDuringRequest = store.state.account.accountsFollowersLoading[alice.url]
				return { data: [bob, carol] }
			})

			const result = await store.dispatch('fetchAccountFollowers', { account: ALICE })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/followers`, { params: {} })
			expect(result).toEqual([bob, carol])
			expect(loadingDuringRequest).toBe(true)
			expect(store.getters.getAccountFollowers(ALICE)).toEqual([bob, carol])
			expect(store.state.account.accountsFollowersAllLoaded[alice.url]).toBe(true)
			expect(store.state.account.accountsFollowersLoading[alice.url]).toBe(false)
		})

		it('passes max_id for the next page, appends it and keeps paging while pages are full', async () => {
			store.commit('addFollowers', { account: ALICE, data: [bob] })
			const page = Array.from({ length: 20 }, (_, i) => ({
				id: String(100 + i),
				acct: `u${i}@remote.tld`,
				url: `https://remote.tld/@u${i}`,
			}))
			axios.get.mockResolvedValue({ data: page })

			await store.dispatch('fetchAccountFollowers', { account: ALICE, maxId: '22' })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/followers`, { params: { max_id: '22' } })
			expect(store.getters.getAccountFollowers(ALICE)).toHaveLength(21)
			expect(store.state.account.accountsFollowersMaxId[alice.url]).toBe('119')
			expect(store.state.account.accountsFollowersAllLoaded[alice.url]).toBe(false)
		})

		it('does not start a second request while one is running', async () => {
			store.commit('setFollowersLoading', { actorId: alice.url, loading: true })

			await expect(store.dispatch('fetchAccountFollowers', { account: ALICE })).resolves.toBeUndefined()

			expect(axios.get).not.toHaveBeenCalled()
		})

		it('shows an error and clears the loading flag when the request fails', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('fetchAccountFollowers', { account: ALICE })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Failed to fetch followers list')
			expect(store.state.account.accountsFollowersLoading[alice.url]).toBe(false)
			expect(store.getters.getAccountFollowers(ALICE)).toEqual([])
		})
	})

	describe('fetchAccountFollowing', () => {
		beforeEach(() => {
			store.commit('addAccount', { actorId: alice.url, data: alice })
		})

		it('loads the first page into the following list and marks a short page as complete', async () => {
			axios.get.mockResolvedValue({ data: [bob] })

			const result = await store.dispatch('fetchAccountFollowing', { account: ALICE })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/following`, { params: {} })
			expect(result).toEqual([bob])
			expect(store.getters.getAccountFollowing(ALICE)).toEqual([bob])
			expect(store.state.account.accountsFollowingsAllLoaded[alice.url]).toBe(true)
			expect(store.state.account.accountsFollowingsLoading[alice.url]).toBe(false)
		})

		it('appends the next page when max_id is given', async () => {
			store.commit('addFollowing', { account: ALICE, data: [bob] })
			axios.get.mockResolvedValue({ data: [carol] })

			await store.dispatch('fetchAccountFollowing', { account: ALICE, maxId: '22' })

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/${ALICE}/following`, { params: { max_id: '22' } })
			expect(store.getters.getAccountFollowing(ALICE)).toEqual([bob, carol])
		})

		it('skips the request while one is running and reports failures', async () => {
			store.commit('setFollowingsLoading', { actorId: alice.url, loading: true })
			await store.dispatch('fetchAccountFollowing', { account: ALICE })
			expect(axios.get).not.toHaveBeenCalled()

			store.commit('setFollowingsLoading', { actorId: alice.url, loading: false })
			axios.get.mockRejectedValue(new Error('boom'))
			await store.dispatch('fetchAccountFollowing', { account: ALICE })

			expect(showError).toHaveBeenCalledWith('Failed to fetch following list')
			expect(store.state.account.accountsFollowingsLoading[alice.url]).toBe(false)
		})
	})
})
