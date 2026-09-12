/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

import logger from '../services/logger.js'
import { useErrorsStore } from './errors.js'
import { useTimelineStore } from './timeline.js'

/**
 * An empty relationship, as the API shapes one, for an account the server has
 * not been asked about yet.
 *
 * @param {string} id the account's numeric id
 * @param {boolean} following whether the reader follows it
 * @return {import('../types/Mastodon.js').Relationship}
 */
function emptyRelationship(id, following) {
	return {
		id,
		following,
		// `note` and `languages` were missing here while `Relationship::jsonSerialize()`
		// has always sent both, so a component reading the placeholder saw a
		// different shape from the one the server answers with
		note: '',
		languages: [],
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
	}
}

/**
 * The actor URL an account handle resolves to, or undefined.
 *
 * This used to read a module-level `state` object rather than the store's own,
 * which worked only because there happened to be exactly one store.
 *
 * @param {object} state the store state
 * @param {string} account a handle, or an actor URL
 * @return {string|undefined}
 */
function actorIdFor(state, account) {
	return state.accountIdMap[account]
}

/**
 * @param {object} state the store state
 * @param {string} account a handle, or an actor URL
 * @return {string} what the follower and following lists are keyed by
 */
function keyFor(state, account) {
	return actorIdFor(state, account) || account
}

/**
 * Files an account under its actor URL, and remembers which handle names it.
 *
 * @param {object} state the store state
 * @param {object} payload the account
 * @param {string} payload.actorId its actor URL
 * @param {import('../types/Mastodon.js').Account} payload.data the account itself
 */
function indexAccount(state, { actorId, data }) {
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
	if (!data.acct) {
		return
	}
	const accountId = (data.acct.indexOf('@') === -1) ? data.acct + '@' + new URL(data.url).hostname : data.acct
	state.accountIdMap = { ...state.accountIdMap, [accountId]: data.url }
}

/**
 * Collects a page of actors into one of the two lists.
 *
 * @param {object} state the store state
 * @param {import('../types/Mastodon.js').Account[]} data the page
 * @return {{users: string[], lastId: string}} the actor URLs and the last id seen
 */
function collectActors(state, data) {
	const users = []
	// a string, like every id on the wire: `Relationship::jsonSerialize()` casts
	// it, and the only reader falls back to 0 for an empty page either way
	let lastId = ''
	for (const actor of data) {
		users.push(actor.url)
		indexAccount(state, { actorId: actor.url, data: actor })
		lastId = actor.id
	}

	return { users, lastId }
}

/**
 * How long to let relationship requests pile up before sending them as one.
 * Long enough for a page of UserEntry components to mount, short enough that
 * the follow buttons do not visibly lag.
 */
const RELATIONSHIP_BATCH_MS = 30

let pendingRelationshipIds = new Set()
let pendingRelationshipBatch = null

/**
 * Every account the page has heard of, and what the reader's relationship with
 * each of them is.
 */
export const useAccountStore = defineStore('account', {
	state: () => ({
		/** the handle of the account the reader is signed in as */
		currentAccountHandle: '',
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
	}),

	getters: {
		/**
		 * @param {object} state the store state
		 * @return {() => Record<string, import('../types/Mastodon.js').Account>} every account, keyed by actor URL
		 */
		getAllAccounts(state) {
			return () => {
				return state.accounts
			}
		},
		/**
		 * The account a handle names, or undefined while it is still unknown —
		 * which is also the only thing the old `accountLoaded` getter answered,
		 * so there is one query here rather than two.
		 *
		 * @param {object} state the store state
		 * @return {(account: string) => import('../types/Mastodon.js').Account|undefined} handle -> account
		 */
		getAccount(state) {
			return (account) => {
				return state.accounts[actorIdFor(state, account)]
			}
		},
		/**
		 * @param {object} state the store state
		 * @return {(accountId: string) => import('../types/Mastodon.js').Relationship|undefined} numeric account id -> relationship
		 */
		getRelationshipWith(state) {
			return (accountId) => {
				return state.accountsRelationships[accountId]
			}
		},
		/**
		 * @return {import('../types/Mastodon.js').Account|undefined} the signed-in account
		 */
		currentAccount() {
			return this.getAccount(this.currentAccountHandle)
		},
		/**
		 * @param {object} state the store state
		 * @return {(id: string) => import('../types/Mastodon.js').Account[]} handle -> the accounts following it
		 */
		getAccountFollowers(state) {
			return (id) => (state.accountsFollowers[keyFor(state, id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
		},
		/**
		 * @param {object} state the store state
		 * @return {(id: string) => import('../types/Mastodon.js').Account[]} handle -> the accounts it follows
		 */
		getAccountFollowing(state) {
			return (id) => (state.accountsFollowings[keyFor(state, id)] || []).map((actorId) => state.accounts[actorId]).filter(Boolean)
		},
		/**
		 * @param {object} state the store state
		 * @return {(account: string) => string|undefined} handle -> actor URL
		 */
		getActorIdForAccount(state) {
			return (account) => actorIdFor(state, account)
		},
		/**
		 * @param {object} state the store state
		 * @return {(followingAccount: string) => boolean} handle or numeric id -> whether the reader follows it
		 */
		isFollowingUser(state) {
			return (followingAccount) => {
				// Relationships are keyed by the Mastodon numeric id (see addRelationship).
				// Callers pass either a handle, which resolves through the actor URL, or that
				// id directly.
				const actorId = actorIdFor(state, followingAccount)
				const relationshipId = (actorId && state.accounts[actorId]?.id) || followingAccount

				return state.accountsRelationships[relationshipId]?.following || false
			}
		},
	},

	actions: {
		setCurrentAccount(account) {
			this.currentAccountHandle = account
		},
		addAccount({ actorId, data }) {
			indexAccount(this, { actorId, data })
		},
		addRelationship({ actorId, data }) {
			this.accountsRelationships = { ...this.accountsRelationships, [actorId]: data }
		},
		setFollowersLoading({ actorId, loading }) {
			this.accountsFollowersLoading = { ...this.accountsFollowersLoading, [actorId]: loading }
		},
		setFollowingsLoading({ actorId, loading }) {
			this.accountsFollowingsLoading = { ...this.accountsFollowingsLoading, [actorId]: loading }
		},
		setFollowersAllLoaded({ actorId, loaded }) {
			this.accountsFollowersAllLoaded = { ...this.accountsFollowersAllLoaded, [actorId]: loaded }
		},
		setFollowingsAllLoaded({ actorId, loaded }) {
			this.accountsFollowingsAllLoaded = { ...this.accountsFollowingsAllLoaded, [actorId]: loaded }
		},
		addFollowers({ account, data }) {
			const key = keyFor(this, account)
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowers = { ...this.accountsFollowers, [key]: users }
			this.accountsFollowersMaxId = { ...this.accountsFollowersMaxId, [key]: lastId }
			this.accountsFollowersAllLoaded = { ...this.accountsFollowersAllLoaded, [key]: false }
		},
		addFollowersAppend({ account, data }) {
			const key = keyFor(this, account)
			const existing = [...(this.accountsFollowers[key] || [])]
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowers = { ...this.accountsFollowers, [key]: [...existing, ...users] }
			this.accountsFollowersMaxId = { ...this.accountsFollowersMaxId, [key]: lastId }
		},
		addFollowing({ account, data }) {
			const key = keyFor(this, account)
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowings = { ...this.accountsFollowings, [key]: users }
			this.accountsFollowingsMaxId = { ...this.accountsFollowingsMaxId, [key]: lastId }
			this.accountsFollowingsAllLoaded = { ...this.accountsFollowingsAllLoaded, [key]: false }
		},
		addFollowingAppend({ account, data }) {
			const key = keyFor(this, account)
			const existing = [...(this.accountsFollowings[key] || [])]
			const { users, lastId } = collectActors(this, data)
			this.accountsFollowings = { ...this.accountsFollowings, [key]: [...existing, ...users] }
			this.accountsFollowingsMaxId = { ...this.accountsFollowingsMaxId, [key]: lastId }
		},
		/**
		 * Records locally that the reader now follows an account: the list the
		 * profile shows, and the relationship the follow button reads.
		 *
		 * Separate from the `followAccount` action that asks the server, which
		 * in Vuex could share the name because mutations and actions were two
		 * namespaces. Here there is one.
		 *
		 * @param {string} accountToFollow the handle
		 */
		markAccountFollowed(accountToFollow) {
			const actorId = actorIdFor(this, accountToFollow)
			const followingList = this.accountsFollowings[actorId] || []
			this.accountsFollowings = { ...this.accountsFollowings, [actorId]: [...followingList, accountToFollow] }
			if (actorId && this.accounts[actorId]) {
				const relationshipId = this.accounts[actorId].id
				if (this.accountsRelationships[relationshipId]) {
					this.accountsRelationships = {
						...this.accountsRelationships,
						[relationshipId]: { ...this.accountsRelationships[relationshipId], following: true },
					}
				} else if (relationshipId) {
					this.accountsRelationships = {
						...this.accountsRelationships,
						[relationshipId]: emptyRelationship(relationshipId, true),
					}
				}
			}
		},
		/**
		 * The other half of markAccountFollowed.
		 *
		 * @param {string} accountToUnfollow the handle
		 */
		markAccountUnfollowed(accountToUnfollow) {
			const actorId = actorIdFor(this, accountToUnfollow)
			const followingList = this.accountsFollowings[actorId] || []
			const index = followingList.indexOf(accountToUnfollow)
			if (index !== -1) {
				const newList = [...followingList]
				newList.splice(index, 1)
				this.accountsFollowings = { ...this.accountsFollowings, [actorId]: newList }
			}
			if (actorId && this.accounts[actorId]) {
				const relationshipId = this.accounts[actorId].id
				if (this.accountsRelationships[relationshipId]) {
					this.accountsRelationships = {
						...this.accountsRelationships,
						[relationshipId]: { ...this.accountsRelationships[relationshipId], following: false },
					}
				} else if (relationshipId) {
					this.accountsRelationships = {
						...this.accountsRelationships,
						[relationshipId]: emptyRelationship(relationshipId, false),
					}
				}
			}
		},
		async fetchAccountInfo(account) {
			try {
				const response = await axios.get(generateUrl(`apps/social/api/v1/global/account/info?account=${account}`))
				this.addAccount({ actorId: response.data.url, data: response.data })
				return response.data
			} catch (error) {
				// the account handle is somebody's identity: it belongs in the
				// app log, not in every reader's browser console
				logger.error('Failed to load account details', { error })
				useErrorsStore().addAppError({
					title: t('social', 'Account lookup failed'),
					message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account }),
				})
			}
		},
		async fetchAccountRelationshipInfo(ids) {
			const wanted = (Array.isArray(ids) ? ids : [ids]).filter((id) => id !== undefined && id !== null)
			if (wanted.length === 0) {
				return []
			}

			try {
				logger.debug('Loading relationships', { count: wanted.length })
				const response = await axios.get(generateUrl('apps/social/api/v1/accounts/relationships'), { params: { id: wanted } })
				response.data.forEach((account) => {
					this.addRelationship({ actorId: account.id, data: account })
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
		 * @param {string} id the account id to ask about
		 * @return {Promise<object[]>} the relationships in the batch this joined
		 */
		fetchRelationship(id) {
			if (id === undefined || id === null) {
				return Promise.resolve([])
			}

			if (this.getRelationshipWith(id) !== undefined) {
				return Promise.resolve([])
			}

			pendingRelationshipIds.add(id)
			if (pendingRelationshipBatch === null) {
				pendingRelationshipBatch = new Promise((resolve) => {
					window.setTimeout(() => {
						const ids = [...pendingRelationshipIds]
						pendingRelationshipIds = new Set()
						pendingRelationshipBatch = null
						resolve(this.fetchAccountRelationshipInfo(ids))
					}, RELATIONSHIP_BATCH_MS)
				})
			}

			return pendingRelationshipBatch
		},
		async fetchPublicAccountInfo(uid) {
			try {
				const response = await axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`))
				this.addAccount({ actorId: response.data.url, data: response.data })
				return response.data
			} catch (error) {
				logger.error('Failed to load public account details', { error })
				useErrorsStore().addAppError({
					title: t('social', 'Account lookup failed'),
					message: t('social', 'Could not load account {account}. The remote server may be unreachable.', { account: uid }),
				})
			}
		},
		fetchCurrentAccountInfo(account) {
			this.setCurrentAccount(account)
			this.fetchAccountInfo(account)
		},
		async followAccount({ accountToFollow }) {
			try {
				const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToFollow))
				const response = await axios.put(url)
				if (response.data.status === -1) {
					// thrown rather than returned: a rejected thenable returned
					// from inside the try resolves only after this frame has
					// popped, so the catch below never saw it — a refusal was a
					// silent unhandled rejection, with no toast and no error state
					throw new Error('The server refused the follow')
				}
				this.markAccountFollowed(accountToFollow)
				return response
			} catch (error) {
				showError(t('social', 'Could not follow {account}', { account: accountToFollow }))
				logger.error(`Failed to follow user ${accountToFollow}`, { error })
			}
		},
		async unfollowAccount({ accountToUnfollow }) {
			try {
				const url = generateUrl('/apps/social/api/v1/current/follow?account=' + encodeURIComponent(accountToUnfollow))
				const response = await axios.delete(url)
				if (response.data.status === -1) {
					// see followAccount: returning a rejection from inside the try
					// escapes this function's own catch
					throw new Error('The server refused the unfollow')
				}
				this.markAccountUnfollowed(accountToUnfollow)
				return response
			} catch (error) {
				showError(t('social', 'Could not unfollow {account}', { account: accountToUnfollow }))
				logger.error(`Failed to unfollow user ${accountToUnfollow}`, { error })
				return error
			}
		},
		async blockAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/block`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
					useTimelineStore().removeStatusesByActor(response.data.id)
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to block the account'))
				logger.error('Failed to block the account', { error })
			}
		},
		async unblockAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unblock`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to unblock the account'))
				logger.error('Failed to unblock the account', { error })
			}
		},
		async muteAccount({ id }) {
			try {
				// No body: the backend mutes notifications by default
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/mute`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
					useTimelineStore().removeStatusesByActor(response.data.id)
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to mute the account'))
				logger.error('Failed to mute the account', { error })
			}
		},
		async unmuteAccount({ id }) {
			try {
				const response = await axios.post(generateUrl(`apps/social/api/v1/accounts/${id}/unmute`))
				if (response.data?.id) {
					this.addRelationship({ actorId: response.data.id, data: response.data })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Failed to unmute the account'))
				logger.error('Failed to unmute the account', { error })
			}
		},
		/** @param {{account?: string, maxId?: string}} options */
		async fetchAccountFollowers({ account, maxId } = {}) {
			const key = keyFor(this, account)
			if (this.accountsFollowersLoading[key]) {
				return
			}
			this.setFollowersLoading({ actorId: key, loading: true })
			try {
				const params = {}
				if (maxId) {
					params.max_id = maxId
				}
				const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/followers`), { params })
				if (!maxId) {
					this.addFollowers({ account, data: response.data })
				} else {
					this.addFollowersAppend({ account, data: response.data })
				}
				if (response.data.length < 20) {
					this.setFollowersAllLoaded({ actorId: key, loaded: true })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Could not load the list of followers'))
				logger.error(`Failed to fetch followers list for user ${account}`, { error })
			} finally {
				this.setFollowersLoading({ actorId: key, loading: false })
			}
		},
		/** @param {{account?: string, maxId?: string}} options */
		async fetchAccountFollowing({ account, maxId } = {}) {
			const key = keyFor(this, account)
			if (this.accountsFollowingsLoading[key]) {
				return
			}
			this.setFollowingsLoading({ actorId: key, loading: true })
			try {
				const params = {}
				if (maxId) {
					params.max_id = maxId
				}
				const response = await axios.get(generateUrl(`apps/social/api/v1/accounts/${account}/following`), { params })
				if (!maxId) {
					this.addFollowing({ account, data: response.data })
				} else {
					this.addFollowingAppend({ account, data: response.data })
				}
				if (response.data.length < 20) {
					this.setFollowingsAllLoaded({ actorId: key, loaded: true })
				}
				return response.data
			} catch (error) {
				showError(t('social', 'Could not load the list of followed accounts'))
				logger.error(`Failed to fetch following list for user ${account}`, { error })
			} finally {
				this.setFollowingsLoading({ actorId: key, loading: false })
			}
		},
	},
})
