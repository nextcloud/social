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

export default new Store({
	modules: {
		timeline,
		account,
		settings,
	},
	strict: debug,
})
