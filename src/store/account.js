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
	accountsFollowersMaxId: {},
	accountsFollowingsMaxId: {},
	accountsFollowersLoading: {},
	accountsFollowingsLoading: {},
	accountsFollowersAllLoaded: {},
	accountsFollowingsAllLoaded: {},
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
	accountFollowing(state) {
		return (account, isFollowing) => _getActorIdForAccount(isFollowing) in state.accounts[_getActorIdForAccount(account)]
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
	async fetchAccountFollowers(context, { account, max_id } = {}) {
		const key = _keyForAccount(account)
		if (context.state.accountsFollowersLoading[key]) return
		context.commit('setFollowersLoading', { actorId: key, loading: true })
		try {
			const params = {}
			if (max_id) params.max_id = max_id
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/followers`), { params })
			if (!max_id) {
				context.commit('addFollowers', { account, data: response.data })
			} else {
				context.commit('addFollowersAppend', { account, data: response.data })
			}
			if (response.data.length < 20) {
				context.commit('setFollowersAllLoaded', { actorId: key, loaded: true })
			}
			return response.data
		} catch (error) {
			showError('Failed to fetch followers list')
			logger.error(`Failed to fetch followers list for user ${account}`, { error })
		} finally {
			context.commit('setFollowersLoading', { actorId: key, loading: false })
		}
	},
	async fetchAccountFollowing(context, { account, max_id } = {}) {
		const key = _keyForAccount(account)
		if (context.state.accountsFollowingsLoading[key]) return
		context.commit('setFollowingsLoading', { actorId: key, loading: true })
		try {
			const params = {}
			if (max_id) params.max_id = max_id
			const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/following`), { params })
			if (!max_id) {
				context.commit('addFollowing', { account, data: response.data })
			} else {
				context.commit('addFollowingAppend', { account, data: response.data })
			}
			if (response.data.length < 20) {
				context.commit('setFollowingsAllLoaded', { actorId: key, loaded: true })
			}
			return response.data
		} catch (error) {
			showError('Failed to fetch following list')
			logger.error(`Failed to fetch following list for user ${account}`, { error })
		} finally {
			context.commit('setFollowingsLoading', { actorId: key, loading: false })
		}
	},
}

export default { state, mutations, getters, actions }
