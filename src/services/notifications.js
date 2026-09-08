/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate } from '@nextcloud/l10n'

/**
 * @param {import("../types/Mastodon").Notification} notification
 * @return {string}
 */
export function notificationSummary(notification) {
	switch (notification.type) {
	case 'mention':
		return translate('social', '{account} mentioned you', { account: notification.account.acct })
	case 'status':
		return translate('social', '{account} posted a status', { account: notification.account.acct })
	case 'reblog':
		return translate('social', '{account} boosted your post', { account: notification.account.acct })
	case 'follow':
		return translate('social', '{account} started to follow you', { account: notification.account.acct })
	case 'follow_request':
		return translate('social', '{account} requested to follow you', { account: notification.account.acct })
	case 'favourite':
		return translate('social', '{account} liked your post', { account: notification.account.acct })
	case 'poll':
		return translate('social', '{account} ended the poll', { account: notification.account.acct })
	case 'update':
		return translate('social', '{account} edited a status', { account: notification.account.acct })
	case 'admin.sign_up':
		return translate('social', '{account} signed up', { account: notification.account.acct })
	case 'admin.report':
		return translate('social', '{account} filed a report', { account: notification.account.acct })
	default:
		return ''
	}
}
