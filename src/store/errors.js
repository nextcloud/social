import logger from '../services/logger.js'

const state = {
	errors: [],
}

const mutations = {
	addError(state, { title, message }) {
		state.errors = [...state.errors, { id: Date.now(), title, message }]
	},
	dismissError(state, id) {
		state.errors = state.errors.filter(e => e.id !== id)
	},
	clearErrors(state) {
		state.errors = []
	},
}

const getters = {
	appErrors(state) {
		return state.errors
	},
	hasErrors(state) {
		return state.errors.length > 0
	},
}

const actions = {
	addAppError({ commit }, { title, message }) {
		logger.error('App error', { title, message })
		commit('addError', { title, message })
	},
	dismissAppError({ commit }, id) {
		commit('dismissError', id)
	},
}

export default { state, mutations, getters, actions }
