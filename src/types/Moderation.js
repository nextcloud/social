/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A row of the moderation table, as `Report::moderationRow()` sends it.
 *
 * @typedef {object} ModerationReport
 * @property {number} id - the report's own id
 * @property {string} account_id - the reported actor's id
 * @property {string} account - their handle, '' when the actor could not be loaded
 * @property {string} reporter - the reporting actor's id
 * @property {boolean} local - whether the report was made on this instance
 * @property {string} category - spam, legal, violation or other
 * @property {string} comment - what the reporter wrote
 * @property {string[]} status_ids - the posts it is about
 * @property {number} creation - when it was made, in seconds since the epoch
 * @property {boolean} resolved - whether a moderator has dealt with it
 * @property {string} level - the decision standing against the account, '' for none
 */

export default {}
