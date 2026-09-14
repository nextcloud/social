/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import mitt from 'mitt'
export default mitt()

/**
 * The reader's lists have changed — one was made, renamed or deleted.
 *
 * The sidebar draws them from a fetch of its own, once per page, so the
 * settings page says so here rather than reaching into it. The name lives with
 * the bus because two unrelated components have to agree on it.
 */
export const LISTS_CHANGED = 'social:lists-changed'
