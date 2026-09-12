/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { computed } from 'vue'

import { useServerData } from './useServerData.js'

/**
 * Who is reading, and the two names this app calls them by.
 *
 * @return {{currentUser: import('vue').ComputedRef<object>, cloudId: import('vue').ComputedRef<string>, socialId: import('vue').ComputedRef<string>}}
 */
export function useCurrentUser() {
	const { hostname } = useServerData()

	/** the Nextcloud user, as @nextcloud/auth reads it off the page */
	const currentUser = computed(() => getCurrentUser())

	/** the reader's own handle, `user@host` */
	const cloudId = computed(() => currentUser.value.uid + '@' + hostname.value)

	/** the same handle as a Fediverse mention */
	const socialId = computed(() => '@' + cloudId.value)

	return { currentUser, cloudId, socialId }
}
