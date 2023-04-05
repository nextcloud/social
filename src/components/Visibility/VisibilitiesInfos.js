import { translate as t } from '@nextcloud/l10n'

/**
 * @typedef {object} Visibility
 * @property {string} id - One of 'public', 'followers', 'direct', 'unlisted'
 * @property {string} text - Short label of the visibility
 * @property {string} description - Description of the visibility
 */

/** @type {Visibility[]} */
export default [
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
		text: t('social', 'Direct'),
		description: t('social', 'Visible to mentioned users only'),
	},
]
