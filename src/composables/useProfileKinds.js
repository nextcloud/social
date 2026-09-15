/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import IconFolderMultipleImage from 'vue-material-design-icons/FolderMultipleImage.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconTextBoxMultiple from 'vue-material-design-icons/TextBoxMultiple.vue'
import { t } from '@nextcloud/l10n'

/**
 * What of an account can be read: its posts three ways, and its collections.
 *
 * One list for the two views that draw the switcher, so that a profile's tabs
 * cannot differ depending on which of them is on screen. The three kinds of
 * post stay on the profile route with the kind in the query, as they were;
 * collections are a page of their own under the account, because they are
 * not a filter of the posts but a thing the account made out of them.
 *
 * @param {string} account the handle in the route
 * @return {Array<{value: string, label: string, icon: object, to: object}>}
 */
export function profileKinds(account) {
	const to = (media) => ({
		name: 'profile',
		params: { account },
		query: media === '' ? {} : { media },
	})

	return [
		{ value: '', label: t('social', 'Posts'), icon: IconTextBoxMultiple, to: to('') },
		{ value: 'image', label: t('social', 'Photos'), icon: IconImageMultiple, to: to('image') },
		{ value: 'video', label: t('social', 'Videos'), icon: IconPlayBoxMultiple, to: to('video') },
		{
			value: 'collections',
			label: t('social', 'Collections'),
			icon: IconFolderMultipleImage,
			to: { name: 'profile.collections', params: { account } },
		},
	]
}
