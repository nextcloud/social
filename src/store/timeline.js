import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import logger from '../services/logger.js'

const state = {
	statuses: {},
	timeline: [],
	parentsTimeline: [],
	type: 'home',
	params: {},
	account: '',
	composerDisplayStatus: false,
	searchQuery: '',
}

function addToStatuses(state, status) {
	state.statuses = { ...state.statuses, [status.id]: status }
	if (status.reblog !== undefined && status.reblog !== null) {
		state.statuses = { ...state.statuses, [status.reblog.id]: status.reblog }
	}
}

const mutations = {
	addToStatuses(state, status) {
		addToStatuses(state, status)
	},
	addToTimeline(state, data) {
		if (Array.isArray(data)) {
			data.forEach(status => addToStatuses(state, status))
			data
				.filter(status => state.timeline.indexOf(status.id) === -1)
				.forEach(status => state.timeline.push(status.id))
		} else {
			data.descendants.forEach(status => addToStatuses(state, status))
			data.ancestors.forEach(status => addToStatuses(state, status))

			data.descendants
				.filter(status => state.timeline.indexOf(status.id) === -1)
				.forEach(status => state.timeline.push(status.id))
			data.ancestors
				.filter(status => state.parentsTimeline.indexOf(status.id) === -1)
				.forEach(status => state.parentsTimeline.push(status.id))
		}
	},
	removeStatus(state, status) {
		const timelineIndex = state.timeline.indexOf(status.id)
		if (timelineIndex !== -1) {
			state.timeline.splice(timelineIndex, 1)
		}
		const parentsTimelineIndex = state.parentsTimeline.indexOf(status.id)
		if (timelineIndex !== -1) {
			state.parentsTimeline.splice(parentsTimelineIndex, 1)
		}
	},
	resetTimeline(state) {
		state.timeline = []
		state.parentsTimeline = []
	},
	setTimelineType(state, type) {
		state.type = type
	},
	setTimelineParams(state, params) {
		state.params = params
	},
	setComposerDisplayStatus(state, status) {
		state.composerDisplayStatus = status
	},
	setAccount(state, account) {
		state.account = account
	},
	setSearchQuery(state, query) {
		state.searchQuery = query
	},
	likeStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], favourited: true }
			state.statuses[status.id].favourites_count++
		}
	},
	unlikeStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], favourited: false }
			state.statuses[status.id].favourites_count--
		}
	},
	boostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], reblogged: true }
			state.statuses[status.id].reblogs_count++
		}
	},
	unboostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], reblogged: false }
			state.statuses[status.id].reblogs_count--
		}
	},
}

const getters = {
	getComposerDisplayStatus(state) {
		return state.composerDisplayStatus
	},
	getTimeline(state) {
		let items = state.timeline
			.map(statusId => state.statuses[statusId])
			.filter(Boolean)
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

		if (state.searchQuery) {
			const q = state.searchQuery.toLowerCase()
			items = items.filter(item => {
				const content = item.content ? item.content.toLowerCase() : ''
				const displayName = item.account?.display_name?.toLowerCase() || ''
				const acct = item.account?.acct?.toLowerCase() || ''
				return content.includes(q) || displayName.includes(q) || acct.includes(q)
			})
		}

		return items
	},
	getParentsTimeline(state) {
		let items = state.parentsTimeline
			.map(statusId => state.statuses[statusId])
			.filter(Boolean)
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

		if (state.searchQuery) {
			const q = state.searchQuery.toLowerCase()
			items = items.filter(item => {
				const content = item.content ? item.content.toLowerCase() : ''
				const displayName = item.account?.display_name?.toLowerCase() || ''
				const acct = item.account?.acct?.toLowerCase() || ''
				return content.includes(q) || displayName.includes(q) || acct.includes(q)
			})
		}

		return items
	},
	getSearchQuery(state) {
		return state.searchQuery
	},
	getStatus(state) {
		return (statusId) => state.statuses[statusId]
	},
	getSinglePost(state) {
		return state.statuses[state.params.singlePost]
	},
	getPostFromTimeline(state) {
		return (statusId) => {
			if (state.statuses[statusId] !== undefined) {
				return state.statuses[statusId]
			} else {
				logger.warn('Could not find status in timeline', { statusId })
			}
		}
	},
}

const actions = {
	changeTimelineType(context, { type, params }) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', type)
		context.commit('setTimelineParams', params)
		context.commit('setAccount', '')
	},
	changeTimelineTypeAccount(context, account) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', 'account')
		context.commit('setAccount', account)
	},
	async createMedia(context, file) {
		try {
			const formData = new FormData()
			formData.append('file', file)
			const { data } = await axios.post(
				generateUrl('apps/social/api/v1/media'),
				formData,
				{
					headers: {
						'Content-Type': 'multipart/form-data',
					},
				}
			)
			logger.info('Media created with id ' + data.id)
			return data
		} catch (error) {
			showError('Failed to create a media')
			logger.error('Failed to create a media', { error })
		}
	},
	async post(context, status) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
			logger.info('Post created', data.id)
		} catch (error) {
			showError('Failed to create a status')
			logger.error('Failed to create a status', { error })
		}
	},
	async postDelete(context, status) {
		try {
			context.commit('removeStatus', status)
			const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
			logger.info('Post deleted with token ' + response.data.result.token)
		} catch (error) {
			context.commit('addToStatuses', status)
			showError('Failed to delete the status')
			logger.error('Failed to delete the status', { error })
		}
	},
	async postLike(context, { status }) {
		try {
			context.commit('likeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/favourite`))
			logger.info('Post liked')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unlikeStatus', { status })
			showError('Failed to like status')
			logger.error('Failed to like status', { error })
		}
	},
	async postUnlike(context, { status }) {
		try {
			if (state.type === 'liked') {
				context.commit('removeStatus', status)
			}
			context.commit('unlikeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unfavourite`))
			logger.info('Post unliked')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			if (state.type === 'liked') {
				context.commit('addToTimeline', [status])
			}
			context.commit('likeStatus', { status })
			showError('Failed to unlike status')
			logger.error('Failed to unlike status', { error })
		}
	},
	async postBoost(context, { status }) {
		try {
			context.commit('boostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/reblog`))
			logger.info('Post boosted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unboostStatus', { status })
			showError('Failed to create a boost status')
			logger.error('Failed to create a boost status', { error })
		}
	},
	async postUnBoost(context, { status }) {
		try {
			context.commit('unboostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unreblog`))
			logger.info('Boost deleted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('boostStatus', { status })
			showError('Failed to delete the boost')
			logger.error('Failed to delete the boost', { error })
		}
	},
	refreshTimeline(context) {
		return this.dispatch('fetchTimeline')
	},
	async fetchTimeline(context, params = {}) {
		if (params.limit === undefined) {
			params.limit = 15
		}

		let url = ''
		switch (state.type) {
		case 'account':
			url = generateUrl(`apps/social/api/v1/accounts/${state.account}/statuses`)
			break
		case 'tags':
			url = generateUrl(`apps/social/api/v1/timelines/tag/${state.params.tag}`)
			break
		case 'single-post':
			url = generateUrl(`apps/social/api/v1/statuses/${state.params.id}/context`)
			break
		case 'timeline':
			url = generateUrl('apps/social/api/v1/timelines/public')
			params.local = true
			break
		case 'federated':
			url = generateUrl('apps/social/api/v1/timelines/public')
			break
		case 'notifications':
			url = generateUrl('apps/social/api/v1/notifications')
			break
		default:
			url = generateUrl(`apps/social/api/v1/timelines/${state.type}`)
		}

		const response = await axios.get(url, { params })

		context.commit('addToTimeline', response.data)

		return response.data
	},
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	},
}

export default { state, mutations, getters, actions }
