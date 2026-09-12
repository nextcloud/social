/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'

import logger from '../services/logger.js'

let nextErrorId = 0

/**
 * The errors the app is currently showing the reader, newest last.
 */
export const useErrorsStore = defineStore('errors', {
	state: () => ({
		errors: [],
	}),

	getters: {
		/**
		 * @param {object} state the store state
		 * @return {object[]} every error still on screen
		 */
		appErrors(state) {
			return state.errors
		},
		/**
		 * @param {object} state the store state
		 * @return {boolean} whether there is anything to show
		 */
		hasErrors(state) {
			return state.errors.length > 0
		},
	},

	actions: {
		addError({ title, message }) {
			this.errors = [...this.errors, { id: ++nextErrorId, title, message }]
		},
		dismissError(id) {
			this.errors = this.errors.filter((e) => e.id !== id)
		},
		clearErrors() {
			this.errors = []
		},
		/**
		 * Records an error and shows it. The log line is the one with the detail.
		 *
		 * @param {object} error what went wrong
		 * @param {string} error.title its heading
		 * @param {string} error.message what to tell the reader
		 */
		addAppError({ title, message }) {
			logger.error('App error', { title, message })
			this.addError({ title, message })
		},
		dismissAppError(id) {
			this.dismissError(id)
		},
	},
})
