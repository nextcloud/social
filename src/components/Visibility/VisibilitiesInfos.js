/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @typedef {object} Visibility
 * @property {string} id - One of 'public', 'followers', 'direct', 'unlisted'
 * @property {string} text - Short label of the visibility
 * @property {string} description - Description of the visibility
 */

/** @type {Visibility[]} */
const visibilities = [
	{
		id: 'public',
		text: t('social', 'Public'),
		description: t('social', 'Visible for all'),
	},
	{
		id: 'unlisted',
		text: t('social', 'Unlisted'),
		description: t('social', 'Visible for all, but opted-out of discovery features'),
	},
	{
		id: 'followers',
		text: t('social', 'Followers'),
		description: t('social', 'Visible to followers only'),
	},
	{
		id: 'direct',
		text: t('social', 'Direct message'),
		description: t('social', 'Visible to mentioned users only'),
	},
]

export default visibilities

/**
 * Whether anything in the app knows what to do with this id. A visibility read
 * back from localStorage — a draft or the last-used one — was written by some
 * version of this app, not necessarily this one.
 *
 * @param {string} id the id to check
 * @return {boolean}
 */
export function isKnownVisibility(id) {
	return visibilities.some((visibility) => visibility.id === id)
}
