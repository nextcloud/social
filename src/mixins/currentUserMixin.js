/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'

import serverData from './serverData.js'

export default {
	mixins: [
		serverData,
	],
	computed: {
		currentUser() {
			return getCurrentUser()
		},
		socialId() {
			return '@' + this.cloudId
		},
		cloudId() {
			return this.currentUser.uid + '@' + this.hostname
		},
	},
}
