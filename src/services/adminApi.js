/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The routes the administration page reads and writes.
 *
 * They are the same routes the page used before it was a Vue application, with
 * the same bodies: everything below is where a URL is spelled, so that a
 * section cannot drift from the endpoint it is about.
 */

/**
 * A moderation route.
 *
 * @param {string} [path] appended below /apps/social/moderation
 * @return {string} the whole URL
 */
export function moderationUrl(path = '') {
	return generateUrl('/apps/social/moderation' + path)
}

/**
 * An administration route of the announcements.
 *
 * @param {string} [path] appended below /apps/social/admin/announcements
 * @return {string} the whole URL
 */
export function announcementsUrl(path = '') {
	return generateUrl('/apps/social/admin/announcements' + path)
}

/**
 * The Server card's one endpoint.
 *
 * @return {string} the whole URL
 */
export function serverUrl() {
	return generateUrl('/apps/social/admin/server')
}

/**
 * What a refused request said, when it said anything.
 *
 * The endpoints of this page answer a 422 with the field they would not take,
 * and swallowing that would leave an administrator with a form that says only
 * "no".
 *
 * @param {object} error what axios threw
 * @param {string} fallback what to say when the server said nothing useful
 * @return {string} the message to show
 */
export function errorMessage(error, fallback) {
	const said = error?.response?.data?.error

	return typeof said === 'string' && said !== '' ? said : fallback
}
