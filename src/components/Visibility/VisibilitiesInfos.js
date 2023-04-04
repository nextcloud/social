import { translate as t } from '@nextcloud/l10n'


/**
 * @typedef {object} Visibility
 * @property {string} id - One of 'public', 'followers', 'direct', 'unlisted'
 * @property {string} text - Short label of the visibility
 * @property {string} longtext - Description of the visibility
 */

/** @type {Visibility[]} */
export default [
	{
		id: 'public',
		text: t('social', 'Public'),
		longtext: t('social', 'Post to public timelines'),
	},
	{
		id: 'unlisted',
		text: t('social', 'Unlisted'),
		longtext: t('social', 'Do not post to public timelines'),
	},
	{
		id: 'followers',
		text: t('social', 'Followers'),
		longtext: t('social', 'Post to followers only'),
	},
	{
		id: 'direct',
		text: t('social', 'Direct'),
		longtext: t('social', 'Post to mentioned users only'),
	},
]
