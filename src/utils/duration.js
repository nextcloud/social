/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translatePlural as n } from '@nextcloud/l10n'

/**
 * A length of time in the largest unit that still says something.
 *
 * @param {number} seconds a length of time
 * @return {string} e.g. "3 hours"
 */
export function duration(seconds) {
	if (seconds >= 86400) {
		return n('social', '%n day', '%n days', Math.round(seconds / 86400))
	}
	if (seconds >= 3600) {
		return n('social', '%n hour', '%n hours', Math.round(seconds / 3600))
	}
	if (seconds >= 60) {
		return n('social', '%n minute', '%n minutes', Math.round(seconds / 60))
	}

	return n('social', '%n second', '%n seconds', seconds)
}
