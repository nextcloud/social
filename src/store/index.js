/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import Vuex, { Store } from 'vuex'
import timeline from './timeline.js'
import account from './account.js'
import settings from './settings.js'

Vue.use(Vuex)

const debug = process.env.NODE_ENV !== 'production'

const store = new Store({
	modules: {
		timeline,
		account,
		settings,
	},
	strict: debug,
})

store.subscribeAction({
	before: (action, state) => {
		if (action.type === 'fetchCurrentAccountInfo') {
			if (typeof OCA !== 'undefined' && OCA.Push && OCA.Push.isEnabled()) {
				OCA.Push.addCallback(store.dispatch('fromPushApp'), 'social')
			}
		}
	},
	after: (action, state) => {
		if (action.type === 'fetchCurrentAccountInfo') {
			if (typeof OCA !== 'undefined' && OCA.Push && OCA.Push.isEnabled()) {
				OCA.Push.addCallback(store.dispatch('fromPushApp'), 'social')
			}
		}
	},
})

export default store
