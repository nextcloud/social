/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { nextTick } from 'vue'

export default {
	bind(el) {
		nextTick(() => {
			el.focus()
		})
	},
}
