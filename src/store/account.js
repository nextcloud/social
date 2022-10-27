/*
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
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
import Vue from 'vue'
import { generateUrl } from '@nextcloud/router'

const state = {
	currentAccount: {},
	accounts: {},
	accountIdMap: {},
}
const addAccount = (state, { actorId, data }) => {
	Vue.set(state.accounts, actorId, Object.assign({ followersList: [], followingList: [], details: { following: false, follower: false } }, state.accounts[actorId], data))
	Vue.set(state.accountIdMap, data.account, data.id)
}
const _getActorIdForAccount = (account) => state.accountIdMap[account]

const mutations = {
	setCurrentAccount(state, account) {
		state.currentAccount = account
	},
	addAccount(state, { actorId, data }) {
		addAccount(state, { actorId, data })
	},
	addFollowers(state, { account, data }) {
		const users = []
		for (const index in data) {
			const actor = data[index].actor_info
			addAccount(state, {
				actorId: actor.id,
				data: actor,
			})
		}
		Vue.set(state.accounts[_getActorIdForAccount(account)], 'followersList', users)
	},
	addFollowing(state, { account, data }) {
		const users = []
		for (const index in data) {
			const actor = data[index].actor_info
			if (typeof actor !== 'undefined' && account !== actor.account) {
				users.push(actor.id)
				addAccount(state, {
					actorId: actor.id,
					data: actor,
				})
			}
		}
		Vue.set(state.accounts[_getActorIdForAccount(account)], 'followingList', users)
	},
	followAccount(state, accountToFollow) {
		Vue.set(state.accounts[_getActorIdForAccount(accountToFollow)].details, 'following', true)
	},
	unfollowAccount(state, accountToUnfollow) {
		Vue.set(state.accounts[_getActorIdForAccount(accountToUnfollow)].details, 'following', false)
	},
}

const getters = {
	getAllAccounts(state) {
		return (account) => { return state.accounts }
	},
	getAccount(state, getters) {
		return (account) => {
			return state.accounts[_getActorIdForAccount(account)]
		}
	},
	accountFollowing(state) {
		return (account, isFollowing) => _getActorIdForAccount(isFollowing) in state.accounts[_getActorIdForAccount(account)]
	},
	accountLoaded(state) {
		return (account) => state.accounts[_getActorIdForAccount(account)]
	},
	getAccountFollowers(state) {
		return (id) => state.accounts[_getActorIdForAccount(id)].followersList.map((actorId) => state.accounts[actorId])
	},
	getAccountFollowing(state) {
		return (id) => state.accounts[_getActorIdForAccount(id)].followingList.map((actorId) => state.accounts[actorId])
	},
	getActorIdForAccount() {
		return _getActorIdForAccount
	},
	isFollowingUser(state) {
		return (followingAccount) => {
			const account = state.accounts[_getActorIdForAccount(followingAccount)]
			return account && account.details ? account.details.following : false
		}
	},
}

const actions = {
	fetchAccountInfo(context, account) {
		return axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${account}`)).then((response) => {
			context.commit('addAccount', { actorId: response.data.result.account.id, data: response.data.result.account })
			return response.data.result.account
		}).catch(() => {
			OC.Notification.showTemporary(`Failed to load account details ${account}`)
		})
	},
	fetchPublicAccountInfo(context, uid) {
		return axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`)).then((response) => {
			context.commit('addAccount', { actorId: response.data.result.account.id, data: response.data.result.account })
			return response.data.result.account
		}).catch(() => {
			OC.Notification.showTemporary(`Failed to load account details ${uid}`)
		})
	},
	fetchCurrentAccountInfo({ commit, dispatch }, account) {
		commit('setCurrentAccount', account)
		dispatch('fetchAccountInfo', account)
	},
	followAccount(context, { currentAccount, accountToFollow }) {
		return axios.put(generateUrl('/apps/social/api/v1/current/follow?account=' + accountToFollow)).then((response) => {
			if (response.data.status === -1) {
				return Promise.reject(response)
			}
			context.commit('followAccount', accountToFollow)
			return Promise.resolve(response)
		}).catch((error) => {
			OC.Notification.showTemporary(`Failed to follow user ${accountToFollow}`)
			console.error(`Failed to follow user ${accountToFollow}`, error)
		})

	},
	unfollowAccount(context, { currentAccount, accountToUnfollow }) {
		return axios.delete(generateUrl('/apps/social/api/v1/current/follow?account=' + accountToUnfollow)).then((response) => {
			if (response.data.status === -1) {
				return Promise.reject(response)
			}
			context.commit('unfollowAccount', accountToUnfollow)
			return Promise.resolve(response)
		}).catch((error) => {
			OC.Notification.showTemporary(`Failed to unfollow user ${accountToUnfollow}`)
			console.error(`Failed to unfollow user ${accountToUnfollow}`, error.response.data)
			return Promise.reject(error.response.data)
		})
	},
	fetchAccountFollowers(context, account) {
		// TODO: fetching followers/following information of remotes is currently not supported
		const parts = account.split('@')
		const uid = (parts.length === 2 ? parts[0] : account)
		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/followers`)).then((response) => {
			context.commit('addFollowers', { account, data: response.data.result })
		})
	},
	fetchAccountFollowing(context, account) {
		// TODO: fetching followers/following information of remotes is currently not supported
		const parts = account.split('@')
		const uid = (parts.length === 2 ? parts[0] : account)
		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/following`)).then((response) => {
			context.commit('addFollowing', { account, data: response.data.result })
		})
	},
}

export default { state, mutations, getters, actions }
