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

/**
 * Somebody pressed "add a reaction" on a post.
 *
 * The payload is `{ react }`, where `react` takes the chosen emoji. The picker
 * is mounted once for the page rather than once per card, and the card keeps
 * the request: the bus carries the callback so neither has to know the other.
 *
 * It goes through the bus rather than the card owning a picker because
 * `@nextcloud/vue` must not be imported anywhere under `TimelinePost` — that
 * component is in both the app's entry and the dashboard's, and a framework
 * import beneath it moves the shared l10n chunk out of `social-framework` and
 * copies it into each entry. See `tests/js/bundles.test.js`, which pins the
 * sizes this would have broken.
 */
export const REACTION_PICK = 'social:reaction-pick'
