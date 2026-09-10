/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

const state = {
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
}

const addAccount = (state, { actorId, data }) => {
	state.accounts = { ...state.accounts, [actorId]: { ...state.accounts[actorId], ...data } }
	// The follower and following lists used to be reset to [] here, so any
	// refresh of the account — saving the profile fields, uploading a banner,
	// a search result arriving — emptied an open followers list under the
	// reader. They are seeded by addFollowers/addFollowing, which is the only
	// thing that knows whether they have been loaded at all.
	if (state.accountsFollowers[actorId] === undefined) {
		state.accountsFollowers = { ...state.accountsFollowers, [actorId]: [] }
	}
	if (state.accountsFollowings[actorId] === undefined) {
		state.accountsFollowings = { ...state.accountsFollowings, [actorId]: [] }
	}
	if (!data.acct) return
	const accountId = (data.acct.indexOf('@') === -1) ? data.acct + '@' + new URL(data.url).hostname : data.acct
	state.accountIdMap = { ...state.accountIdMap, [accountId]: data.url }
}
const _getActorIdForAccount = (account) => state.accountIdMap[account]
const _keyForAccount = (account) => _getActorIdForAccount(account) || account

const mutations = {
	setCurrentAccount(state, account) {
		state.currentAccount = account
	},
	addAccount(state, { actorId, data }) {
		addAccount(state, { actorId, data })
	},
	addRelationship(state, { actorId, data }) {
		state.accountsRelationships = { ...state.accountsRelationships, [actorId]: data }
	},
	setFollowersLoading(state, { actorId, loading }) {
		state.accountsFollowersLoading = { ...state.accountsFollowersLoading, [actorId]: loading }
	},
	setFollowingsLoading(state, { actorId, loading }) {
		state.accountsFollowingsLoading = { ...state.accountsFollowingsLoading, [actorId]: loading }
	},
	setFollowersAllLoaded(state, { actorId, loaded }) {
		state.accountsFollowersAllLoaded = { ...state.accountsFollowersAllLoaded, [actorId]: loaded }
	},
	setFollowingsAllLoaded(state, { actorId, loaded }) {
		state.accountsFollowingsAllLoaded = { ...state.accountsFollowingsAllLoaded, [actorId]: loaded }
	},
	addFollowers(state, { account, data }) {
		const key = _keyForAccount(account)
		const users = []
		let lastId = 0
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
			lastId = actor.id
		}
		state.accountsFollowers = { ...state.accountsFollowers, [key]: users }
		state.accountsFollowersMaxId = { ...state.accountsFollowersMaxId, [key]: lastId }
		state.accountsFollowersAllLoaded = { ...state.accountsFollowersAllLoaded, [key]: false }
	},
	addFollowersAppend(state, { account, data }) {
		const key = _keyForAccount(account)
		const existing = [...(state.accountsFollowers[key] || [])]
		let lastId = 0
		for (const actor of data) {
			existing.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
			lastId = actor.id
		}
		state.accountsFollowers = { ...state.accountsFollowers, [key]: existing }
		state.accountsFollowersMaxId = { ...state.accountsFollowersMaxId, [key]: lastId }
	},
	addFollowing(state, { account, data }) {
		const key = _keyForAccount(account)
		const users = []
		let lastId = 0
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
			lastId = actor.id
		}
		state.accountsFollowings = { ...state.accountsFollowings, [key]: users }
		state.accountsFollowingsMaxId = { ...state.accountsFollowingsMaxId, [key]: lastId }
		state.accountsFollowingsAllLoaded = { ...state.accountsFollowingsAllLoaded, [key]: false }
	},
	addFollowingAppend(state, { account, data }) {
		const key = _keyForAccount(account)
		const existing = [...(state.accountsFollowings[key] || [])]
		let lastId = 0
		for (const actor of data) {
			existing.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
			lastId = actor.id
		}
		state.accountsFollowings = { ...state.accountsFollowings, [key]: existing }
		state.accountsFollowingsMaxId = { ...state.accountsFollowingsMaxId, [key]: lastId }
	},
	followAccount(state, accountToFollow) {
		const followingList = state.accountsFollowings[_getActorIdForAccount(accountToFollow)] || []
		state.accountsFollowings = { ...state.accountsFollowings, [_getActorIdForAccount(accountToFollow)]: [...followingList, accountToFollow] }
		const actorId = _getActorIdForAccount(accountToFollow)
		if (actorId && state.accounts[actorId]) {
			const relationshipId = state.accounts[actorId].id
			if (state.accountsRelationships[relationshipId]) {
				state.accountsRelationships = {
					...state.accountsRelationships,
					[relationshipId]: { ...state.accountsRelationships[relationshipId], following: true },
				}
			} else if (relationshipId) {
				state.accountsRelationships = {
					...state.accountsRelationships,
					[relationshipId]: {
						id: relationshipId,
						following: true,
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
					},
				}
			}
		}
	},
	unfollowAccount(state, accountToUnfollow) {
		const followingList = state.accountsFollowings[_getActorIdForAccount(accountToUnfollow)] || []
		const index = followingList.indexOf(accountToUnfollow)
		if (index !== -1) {
			const newList = [...followingList]
			newList.splice(index, 1)
			state.accountsFollowings = { ...state.accountsFollowings, [_getActorIdForAccount(accountToUnfollow)]: newList }
		}
		const actorId = _getActorIdForAccount(accountToUnfollow)
		if (actorId && state.accounts[actorId]) {
			const relationshipId = state.accounts[actorId].id
			if (state.accountsRelationships[relationshipId]) {
				state.accountsRelationships = {
					...state.accountsRelationships,
					[relationshipId]: { ...state.accountsRelationships[relationshipId], following: false },
				}
			} else if (relationshipId) {
				state.accountsRelationships = {
					...state.accountsRelationships,
					[relationshipId]: {
						id: relationshipId,
						following: false,
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
					},
				}
			}
		}
	},
}

const getters = {
	getAllAccounts(state) {
		return () => { return state.accounts }
	},
	getAccount(state, getters) {
		return (account) => {
			return state.accounts[_getActorIdForAccount(account)]
		}
	},
	getRelationshipWith(state, getters) {
		return (accountId) => {
			return state.accountsRelationships[accountId]
		}
	},
	currentAccount(state, getters) {
		return getters.getAccount(state.currentAccount)
	},
	accountLoaded(state) {
		return (account) => state.accounts[_getActorIdForAccount(account)]
	},
	getAccountFollowers(state) {
		return (id) => (state.accountsFollowers[_keyForAccount(id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
	},
	getAccountFollowing(state) {
		return (id) => (state.accountsFollowings[_keyForAccount(id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
	},
	getActorIdForAccount() {
		return _getActorIdForAccount
	},
	isFollowingUser(state) {
		return (followingAccount) => {
			// Relationships are keyed by the Mastodon numeric id (see addRelationship).
			// Callers pass either a handle, which resolves through the actor URL, or that
			// id directly.
			const actorId = _getActorIdForAccount(followingAccount)
			const relationshipId = (actorId && state.accounts[actorId]?.id) || followingAccount

			return state.accountsRelationships[relationshipId]?.following || false
		}
	},
}

/**
 * How long to let relationship requests pile up before sending them as one.
 * Long enough for a page of UserEntry components to mount, short enough that
 * the follow buttons do not visibly lag.
 */
const RELATIONSHIP_BATCH_MS = 30

let pendingRelationshipIds = new Set()
let pendingRelationshipBatch = null

const actions = {
	async fetchAccountInfo(context, account) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${account}`))
			context.commit('addAccount', { actorId: response.data.url, data: response.data })
			return response.data
		} catch (error) {
			// the account handle is somebody's identity: it belongs in the
			// app log, not in every reader's browser console
			logger.error('Failed to load account details', { error })
			context.dispatch('addAppError', {
				title: t('social', 'Account lookup failed'),
				message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account }),
			})
		}
	},
	async fetchAccountRelationshipInfo(context, ids) {
		const wanted = (Array.isArray(ids) ? ids : [ids]).filter((id) => id !== undefined && id !== null)
		if (wanted.length === 0) {
			return []
		}

		try {
			logger.debug('Loading relationships', { count: wanted.length })
			const response = await axios.get(generateUrl('apps/social/api/v1/accounts/relationships'), { params: { id: wanted } })
			response.data.forEach(account => {
				context.commit('addRelationship', { actorId: account.id, data: account })
			})
			return response.data
		} catch (error) {
			logger.error('Failed to load relationship info', { error })
			showError(t('social', 'Could not load the relationship with this account'))
		}
	},
	/**
	 * Asks for one account's relationship, together with everybody else who
	 * asked in the same moment.
	 *
	 * Twenty followers on a page used to be twenty round-trips, because each
	 * UserEntry dispatched its own single-id request on mount — and the guard
	 * that was supposed to prevent that could never hold. The endpoint takes
	 * an array, so the ids are collected and sent once.
	 *
	 * @param {object} context the store
	 * @param {string} id the account id to ask about
	 * @return {Promise<object[]>} the relationships in the batch this joined
	 */
	fetchRelationship(context, id) {
		if (id === undefined || id === null) {
			return Promise.resolve([])
		}

		if (context.getters.getRelationshipWith(id) !== undefined) {
			return Promise.resolve([])
		}

		pendingRelationshipIds.add(id)
		if (pendingRelationshipBatch === null) {
			pendingRelationshipBatch = new Promise((resolve) => {
				window.setTimeout(() => {
					const ids = [...pendingRelationshipIds]
					pendingRelationshipIds = new Set()
					pendingRelationshipBatch = null
					resolve(context.dispatch('fetchAccountRelationshipInfo', ids))
				}, RELATIONSHIP_BATCH_MS)
			})
		}

		return pendingRelationshipBatch
	},
	async fetchPublicAccountInfo(context, uid) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`))
			context.commit('addAccount', { actorId: response.data.url, data: response.data })
			return response.data
		} catch (error) {
			logger.error('Failed to load public account details', { error })
			context.dispatch('addAppError', {
				title: t('social', 'Account lookup failed'),
				message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account: uid }),
			})
		}
	},
	fetchCurrentAccountInfo({ commit, dispatch }, account) {
		commit('setCurrentAccount', account)
		dispatch('fetchAccountInfo', account)
	},
	async followAccount(context, { accountToFollow }) {
		try {
			const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToFollow))
			const response = await axios.put(url)
			if (response.data.status === -1) {
				logger.error('The server refused the follow', { status: response.data.status })
				return Promise.reject(response)
			}
			context.commit('followAccount', accountToFollow)
			return response
		} catch (error) {
			showError(t('social', 'Could not follow {account}', { account: accountToFollow }))
			logger.error(`Failed to follow user ${accountToFollow}`, { error })
		}
	},
	async unfollowAccount(context, { accountToUnfollow }) {
		try {
			const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToUnfollow))
			const response = await axios.delete(url)
			if (response.data.status === -1) {
				logger.error('The server refused the unfollow', { status: response.data.status })
				return Promise.reject(response)
			}
			context.commit('unfollowAccount', accountToUnfollow)
			return response
		} catch (error) {
			showError(t('social', 'Could not unfollow {account}', { account: accountToUnfollow }))
			logger.error(`Failed to unfollow user ${accountToUnfollow}`, { error })
			return error
		}
	},
	async blockAccount(context, { id }) {
		try {
			const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/block`))
			if (response.data?.id) {
				context.commit('addRelationship', { actorId: response.data.id, data: response.data })
				context.commit('removeStatusesByActor', response.data.id)
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Failed to block the account'))
			logger.error('Failed to block the account', { error })
		}
	},
	async unblockAccount(context, { id }) {
		try {
			const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unblock`))
			if (response.data?.id) {
				context.commit('addRelationship', { actorId: response.data.id, data: response.data })
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Failed to unblock the account'))
			logger.error('Failed to unblock the account', { error })
		}
	},
	async muteAccount(context, { id }) {
		try {
			// No body: the backend mutes notifications by default
			const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/mute`))
			if (response.data?.id) {
				context.commit('addRelationship', { actorId: response.data.id, data: response.data })
				context.commit('removeStatusesByActor', response.data.id)
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Failed to mute the account'))
			logger.error('Failed to mute the account', { error })
		}
	},
	async unmuteAccount(context, { id }) {
		try {
			const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unmute`))
			if (response.data?.id) {
				context.commit('addRelationship', { actorId: response.data.id, data: response.data })
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Failed to unmute the account'))
			logger.error('Failed to unmute the account', { error })
		}
	},
	async fetchAccountFollowers(context, { account, maxId } = {}) {
		const key = _keyForAccount(account)
		if (context.state.accountsFollowersLoading[key]) return
		context.commit('setFollowersLoading', { actorId: key, loading: true })
		try {
			const params = {}
			if (maxId) params.max_id = maxId
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/followers`), { params })
			if (!maxId) {
				context.commit('addFollowers', { account, data: response.data })
			} else {
				context.commit('addFollowersAppend', { account, data: response.data })
			}
			if (response.data.length < 20) {
				context.commit('setFollowersAllLoaded', { actorId: key, loaded: true })
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Could not load the list of followers'))
			logger.error(`Failed to fetch followers list for user ${account}`, { error })
		} finally {
			context.commit('setFollowersLoading', { actorId: key, loading: false })
		}
	},
	async fetchAccountFollowing(context, { account, maxId } = {}) {
		const key = _keyForAccount(account)
		if (context.state.accountsFollowingsLoading[key]) return
		context.commit('setFollowingsLoading', { actorId: key, loading: true })
		try {
			const params = {}
			if (maxId) params.max_id = maxId
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/following`), { params })
			if (!maxId) {
				context.commit('addFollowing', { account, data: response.data })
			} else {
				context.commit('addFollowingAppend', { account, data: response.data })
			}
			if (response.data.length < 20) {
				context.commit('setFollowingsAllLoaded', { actorId: key, loaded: true })
			}
			return response.data
		} catch (error) {
			showError(t('social', 'Could not load the list of followed accounts'))
			logger.error(`Failed to fetch following list for user ${account}`, { error })
		} finally {
			context.commit('setFollowingsLoading', { actorId: key, loading: false })
		}
	},
}

export default { state, mutations, getters, actions }
