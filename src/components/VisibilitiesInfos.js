import { translate as t } from '@nextcloud/l10n'

const visibilityToClass = {
	public: 'icon-link',
	followers: 'icon-contacts-dark',
	direct: 'icon-external',
	unlisted: 'icon-password',
}

export default [
	{
		id: 'public',
		icon: visibilityToClass.public,
		text: t('social', 'Public'),
		longtext: t('social', 'Post to public timelines'),
	},
	{
		id: 'unlisted',
		icon: visibilityToClass.unlisted,
		text: t('social', 'Unlisted'),
		longtext: t('social', 'Do not post to public timelines'),
	},
	{
		id: 'followers',
		icon: visibilityToClass.followers,
		text: t('social', 'Followers'),
		longtext: t('social', 'Post to followers only'),
	},
	{
		id: 'direct',
		icon: visibilityToClass.direct,
		text: t('social', 'Direct'),
		longtext: t('social', 'Post to mentioned users only'),
	},
]
