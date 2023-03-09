/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import axios from '@nextcloud/axios'
import { set } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import logger from '../services/logger.js'

const state = {
	currentAccount: '',
	/** @type {Object<string, import('../types/Mastodon.js').Account>} */
	accounts: {},
	/** @type {Object<string, string[]>} */
	accountsFollowers: {},
	/** @type {Object<string, string[]>} */
	accountsFollowings: {},
	/** @type {Object<string, Partial<import('../types/Mastodon.js').Relationship>>} */
	accountsRelationships: {},
	/** @type {Object<string, string>} */
	accountIdMap: {},
}

/**
 * @param {typeof state} state
 * @param {object} payload
 * @param {string} payload.actorId
 * @param {import('../types/Mastodon').Account} payload.data
 */
const addAccount = (state, { actorId, data }) => {
	set(state.accounts, actorId, { ...state.accounts[actorId], ...data })
	set(state.accountsFollowers, actorId, [])
	set(state.accountsFollowings, actorId, [])
	const accountId = (data.acct.indexOf('@') === -1) ? data.acct + '@' + new URL(data.url).hostname : data.acct
	set(state.accountIdMap, accountId, data.url)
}
const _getActorIdForAccount = (account) => state.accountIdMap[account]

/** @type {import('vuex').MutationTree<state, any>} */
const mutations = {
	/**
	 * @param state
	 * @param {string} account
	 */
	setCurrentAccount(state, account) {
		state.currentAccount = account
	},
	/**
	 * @param state
	 * @param {object} payload
	 * @param {string} payload.actorId
	 * @param {import('../types/Mastodon').Account} payload.data
	 */
	addAccount(state, { actorId, data }) {
		addAccount(state, { actorId, data })
	},
	/**
	 * @param state
	 * @param {object} payload
	 * @param {string} payload.actorId
	 * @param {import('../types/Mastodon').Relationship} payload.data
	 */
	addRelationship(state, { actorId, data }) {
		set(state.accountsRelationships, actorId, data)
	},
	/**
	 * @param  state
	 * @param {object} root
	 * @param {string} root.account
	 * @param {import('../types/Mastodon.js').Account[]} root.data
	 */
	addFollowers(state, { account, data }) {
		const users = []
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
		}
		set(state.accountsFollowers, _getActorIdForAccount(account), users)
	},
	/**
	 * @param  state
	 * @param {object} root
	 * @param {string} root.account
	 * @param {import('../types/Mastodon.js').Account[]} root.data
	 */
	addFollowing(state, { account, data }) {
		const users = []
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
		}
		set(state.accountsFollowings, _getActorIdForAccount(account), users)
	},
	followAccount(state, accountToFollow) {
		state.accountsFollowings[_getActorIdForAccount(accountToFollow)].push(accountToFollow)
		set(state.accountsRelationships[state.accounts[_getActorIdForAccount(accountToFollow)].id], 'following', true)
	},
	unfollowAccount(state, accountToUnfollow) {
		const followingList = state.accountsFollowings[_getActorIdForAccount(accountToUnfollow)]
		followingList.splice(followingList.indexOf(accountToUnfollow), 1)
		set(state.accountsRelationships[state.accounts[_getActorIdForAccount(accountToUnfollow)].id], 'following', false)
	},
}

/** @type {import('vuex').GetterTree<state, any>} */
const getters = {
	getAllAccounts(state) {
		return () => { return state.accounts }
	},
	getAccount(state, getters) {
		return (/** @type {string} */ account) => {
			return state.accounts[_getActorIdForAccount(account)]
		}
	},
	getRelationshipWith(state, getters) {
		return (/** @type {string} */ accountId) => {
			return state.accountsRelationships[accountId]
		}
	},
	currentAccount(state, getters) {
		return getters.getAccount(state.currentAccount)
	},
	accountFollowing(state) {
		return (/** @type {string} */ account, /** @type {boolean} */ isFollowing) => _getActorIdForAccount(isFollowing) in state.accounts[_getActorIdForAccount(account)]
	},
	accountLoaded(state) {
		return (/** @type {string} */ account) => state.accounts[_getActorIdForAccount(account)]
	},
	getAccountFollowers(state) {
		return (/** @type {string} */ id) => state.accountsFollowers[_getActorIdForAccount(id)].map((actorId) => state.accounts[actorId])
	},
	getAccountFollowing(state) {
		return (/** @type {string} */ id) => state.accountsFollowings[_getActorIdForAccount(id)].map((actorId) => state.accounts[actorId])
	},
	getActorIdForAccount() {
		return _getActorIdForAccount
	},
	isFollowingUser(state) {
		return (/** @type {string} */ followingAccount) => state.accountsRelationships[_getActorIdForAccount(followingAccount)]?.following || false
	},
}

/** @type {import('vuex').ActionTree<state, any>} */
const actions = {
	async fetchAccountInfo(context, account) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${account}`))
			context.commit('addAccount', { actorId: response.data.url, data: response.data })
			return response.data
		} catch (error) {
			logger.error('Failed to load local account details', { error })
			showError(`Failed to load local account details ${account}`)
		}
	},
	async fetchAccountRelationshipInfo(context, ids) {
		try {
			const response = await axios.get(generateUrl('apps/social/api/v1/accounts/relationships'), { params: { id: ids } })
			response.data.forEach(account => context.commit('addRelationship', { actorId: account.id, data: account }))
			return response.data
		} catch (error) {
			logger.error('Failed to load relationship info', { error })
			showError('Failed to load relationship info')
		}
	},
	async fetchPublicAccountInfo(context, uid) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`))
			context.commit('addAccount', { actorId: response.data.url, data: response.data })
			return response.data
		} catch (error) {
			logger.error('Failed to load public account details', { error })
			showError(`Failed to load public account details ${uid}`)
		}
	},
	fetchCurrentAccountInfo({ commit, dispatch }, account) {
		commit('setCurrentAccount', account)
		dispatch('fetchAccountInfo', account)
	},
	async followAccount(context, { currentAccount, accountToFollow }) {
		try {
			const response = await axios.put(generateUrl('/apps/social/api/v1/current/follow?account=' + accountToFollow))
			if (response.data.status === -1) {
				return Promise.reject(response)
			}
			context.commit('followAccount', accountToFollow)
			return response
		} catch (error) {
			showError(`Failed to follow user ${accountToFollow}`)
			logger.error(`Failed to follow user ${accountToFollow}`, { error })
		}
	},
	async unfollowAccount(context, { currentAccount, accountToUnfollow }) {
		try {
			const response = await axios.delete(generateUrl('/apps/social/api/v1/current/follow?account=' + accountToUnfollow))
			if (response.data.status === -1) {
				return Promise.reject(response)
			}
			context.commit('unfollowAccount', accountToUnfollow)
			return response
		} catch (error) {
			showError(`Failed to unfollow user ${accountToUnfollow}`)
			logger.error(`Failed to unfollow user ${accountToUnfollow}`, { error })
			return error
		}
	},
	async fetchAccountFollowers(context, account) {
		// TODO: fetching followers/following information of remotes is currently not supported
		const parts = account.split('@')
		const uid = (parts.length === 2 ? parts[0] : account)
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${uid}/followers`))
			context.commit('addFollowers', { account, data: response.data })
		} catch (error) {
			showError('Failed to fetch followers list')
			logger.error(`Failed to fetch followers list for user ${account}`, { error })
		}
	},
	async fetchAccountFollowing(context, account) {
		// TODO: fetching followers/following information of remotes is currently not supported
		const parts = account.split('@')
		const uid = (parts.length === 2 ? parts[0] : account)
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${uid}/following`))
			context.commit('addFollowing', { account, data: response.data })
		} catch (error) {
			showError('Failed to fetch following list')
			logger.error(`Failed to fetch following list for user ${account}`, { error })
		}
	},
}

export default { state, mutations, getters, actions }
