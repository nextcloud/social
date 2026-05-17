import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

const state = {
	currentAccount: '',
	accounts: {},
	accountsFollowers: {},
	accountsFollowings: {},
	accountsRelationships: {},
	accountIdMap: {},
}

const addAccount = (state, { actorId, data }) => {
	state.accounts = { ...state.accounts, [actorId]: { ...state.accounts[actorId], ...data } }
	state.accountsFollowers = { ...state.accountsFollowers, [actorId]: [] }
	state.accountsFollowings = { ...state.accountsFollowings, [actorId]: [] }
	if (!data.acct) return
	const accountId = (data.acct.indexOf('@') === -1) ? data.acct + '@' + new URL(data.url).hostname : data.acct
	state.accountIdMap = { ...state.accountIdMap, [accountId]: data.url }
}
const _getActorIdForAccount = (account) => state.accountIdMap[account]

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
	addFollowers(state, { account, data }) {
		const users = []
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
		}
		state.accountsFollowers = { ...state.accountsFollowers, [_getActorIdForAccount(account)]: users }
	},
	addFollowing(state, { account, data }) {
		const users = []
		for (const actor of data) {
			users.push(actor.url)
			addAccount(state, {
				actorId: actor.url,
				data: actor,
			})
		}
		state.accountsFollowings = { ...state.accountsFollowings, [_getActorIdForAccount(account)]: users }
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
	accountFollowing(state) {
		return (account, isFollowing) => _getActorIdForAccount(isFollowing) in state.accounts[_getActorIdForAccount(account)]
	},
	accountLoaded(state) {
		return (account) => state.accounts[_getActorIdForAccount(account)]
	},
	getAccountFollowers(state) {
		return (id) => (state.accountsFollowers[_getActorIdForAccount(id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
	},
	getAccountFollowing(state) {
		return (id) => (state.accountsFollowings[_getActorIdForAccount(id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
	},
	getActorIdForAccount() {
		return _getActorIdForAccount
	},
	isFollowingUser(state) {
		return (followingAccount) => state.accountsRelationships[_getActorIdForAccount(followingAccount)]?.following || false
	},
}

const actions = {
	async fetchAccountInfo(context, account) {
		try {
			console.debug('[Social] fetchAccountInfo', { account })
			const response = await axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${account}`))
			console.debug('[Social] account info response', { url: response.data.url, id: response.data.id, acct: response.data.acct })
			context.commit('addAccount', { actorId: response.data.url, data: response.data })
			return response.data
		} catch (error) {
			console.error('[Social] fetchAccountInfo failed', account, error.response?.data || error.message || error)
			logger.error('Failed to load account details', { error })
			context.dispatch('addAppError', {
				title: t('social', 'Account lookup failed'),
				message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account }),
			})
		}
	},
	async fetchAccountRelationshipInfo(context, ids) {
		try {
			console.debug('[Social] fetchAccountRelationshipInfo', { ids })
			const response = await axios.get(generateUrl('apps/social/api/v1/accounts/relationships'), { params: { id: ids } })
			console.debug('[Social] relationships response', response.data)
			response.data.forEach(account => {
				console.debug('[Social] addRelationship', { actorId: account.id, following: account.following, data: account })
				context.commit('addRelationship', { actorId: account.id, data: account })
			})
			return response.data
		} catch (error) {
			console.error('[Social] fetchAccountRelationshipInfo failed', ids, error.response?.data || error.message || error)
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
			console.debug('[Social] followAccount action called', { accountToFollow })
			const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToFollow))
			console.debug('[Social] PUT', url)
			const response = await axios.put(url)
			console.debug('[Social] followAccount response', response.data)
			if (response.data.status === -1) {
				console.error('[Social] followAccount failed:', response.data.message, response.data.exception)
				return Promise.reject(response)
			}
			context.commit('followAccount', accountToFollow)
			console.debug('[Social] followAccount mutation committed, following=true')
			return response
		} catch (error) {
			console.error('[Social] Failed to follow user', accountToFollow, error.response?.data || error.message || error)
			showError(`Failed to follow user ${accountToFollow}`)
			logger.error(`Failed to follow user ${accountToFollow}`, { error })
		}
	},
	async unfollowAccount(context, { accountToUnfollow }) {
		try {
			console.debug('[Social] unfollowAccount action called', { accountToUnfollow })
			const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToUnfollow))
			console.debug('[Social] DELETE', url)
			const response = await axios.delete(url)
			console.debug('[Social] unfollowAccount response', response.data)
			if (response.data.status === -1) {
				console.error('[Social] unfollowAccount failed:', response.data.message, response.data.exception)
				return Promise.reject(response)
			}
			context.commit('unfollowAccount', accountToUnfollow)
			console.debug('[Social] unfollowAccount mutation committed, following=false')
			return response
		} catch (error) {
			console.error('[Social] Failed to unfollow user', accountToUnfollow, error.response?.data || error.message || error)
			showError(`Failed to unfollow user ${accountToUnfollow}`)
			logger.error(`Failed to unfollow user ${accountToUnfollow}`, { error })
			return error
		}
	},
	async fetchAccountFollowers(context, account) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/followers`))
			context.commit('addFollowers', { account, data: response.data })
		} catch (error) {
			showError('Failed to fetch followers list')
			logger.error(`Failed to fetch followers list for user ${account}`, { error })
		}
	},
	async fetchAccountFollowing(context, account) {
		try {
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/following`))
			context.commit('addFollowing', { account, data: response.data })
		} catch (error) {
			showError('Failed to fetch following list')
			logger.error(`Failed to fetch following list for user ${account}`, { error })
		}
	},
}

export default { state, mutations, getters, actions }
