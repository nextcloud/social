/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcPopoverMenu from '@nextcloud/vue/dist/Components/NcPopoverMenu.js'

export default {
	components: {
		NcPopoverMenu,
	},
	data() {
		return {
			menuOpened: false,
		}
	},
	methods: {
		togglePopoverMenu() {
			this.menuOpened = !this.menuOpened
		},
		hidePopoverMenu() {
			this.menuOpened = false
		},
	},
}
