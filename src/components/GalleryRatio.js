/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The shape of the box a picture is given before it has loaded.
 *
 * The space has to be reserved from the metadata alone: a timeline that lets
 * every image size itself jumps under the reader's thumb as each one arrives.
 */

/** a phone portrait is 9:16; anything taller than this would fill the viewport */
export const MIN_RATIO = 3 / 4

/** a panorama is 3:1 or worse, and a sliver that tall shows nothing */
export const MAX_RATIO = 16 / 9

/** what an attachment that never reported its size is given */
export const DEFAULT_RATIO = 4 / 3

/**
 * @param {?object} attachment a Mastodon MediaAttachment
 * @param {number} [fallback] the ratio for media that carries no dimensions
 * @return {number} width divided by height, inside the range above
 */
export function ratioOf(attachment, fallback = DEFAULT_RATIO) {
	// `meta` is absent for a remote attachment this instance has not cached
	// yet, and AttachmentMeta::jsonSerialize() drops zero width and height
	const size = attachment?.meta?.original ?? attachment?.meta?.small ?? null
	const width = Number(size?.width)
	const height = Number(size?.height)

	if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
		return fallback
	}

	return Math.min(MAX_RATIO, Math.max(MIN_RATIO, width / height))
}
