/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import logger from '../services/logger.js'

let nextErrorId = 0

const state = {
	errors: [],
}

const mutations = {
	addError(state, { title, message }) {
		state.errors = [...state.errors, { id: ++nextErrorId, title, message }]
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
