/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia } from 'pinia'

export { useAccountStore } from './account.js'
export { useErrorsStore } from './errors.js'
export { useNotificationsStore } from './notifications.js'
export { useSettingsStore } from './settings.js'
export { useTimelineStore } from './timeline.js'

/**
 * The one Pinia every entry point installs.
 *
 * Each store registers itself the first time a component asks for it, so this
 * file no longer lists the modules — what it holds is whatever the page used.
 */
export default createPinia()
