/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The shape of the box a picture is given before it has loaded.
 *
 * The space has to be reserved from the metadata alone: a timeline that lets
 * every image size itself jumps under the reader's thumb as each one arrives.
 *
 * The box is the shape the media actually is. There used to be a floor of 3:4
 * and a ceiling of 16:9 here, and anything outside them was reshaped to fit: a
 * 9:16 short -- which is what a phone records, and what the comment beside the
 * floor claimed to allow -- was given a 3:4 box and had its top and bottom cut
 * off by the `cover` the frame is drawn with. A tall frame still must not take
 * over a timeline, so `GalleryMedia` caps how tall one may be *drawn*. That
 * bounds the frame without reshaping the picture: what will not fit is made
 * smaller, never cropped.
 */

/**
 * What an attachment that never reported its size is given.
 *
 * Only a picture whose dimensions are unknown gets a shape it did not ask
 * for. Everything else keeps its own: a short shot on a phone is 9:16, and
 * it is shown 9:16.
 */
export const DEFAULT_RATIO = 4 / 3

/**
 * How wide an attachment is for its height.
 *
 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the attachment
 * @param {number} [fallback] what to use when its size is not known
 * @return {number} width divided by height
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

	return width / height
}

/**
 * The tallest shape the photo feed gives a picture: 4:5, portrait.
 *
 * The photo feed is the one place a picture is cut to fit. Every picture there
 * is drawn the full width of the column, one after another, so a skyscraper
 * would be the only thing on the screen and a panorama a strip; Instagram's
 * feed holds them between these same two shapes, and people posting from
 * there frame their pictures for it.
 */
export const FEED_TALLEST = 4 / 5

/** The widest shape the photo feed gives a picture: 1.91:1, landscape. */
export const FEED_WIDEST = 1.91

/**
 * The shape of a picture's frame in the photo feed: its own, held between
 * `FEED_TALLEST` and `FEED_WIDEST`. What is outside is cropped, around the
 * focal point the poster chose.
 *
 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the attachment
 * @return {number} width divided by height
 */
export function feedRatioOf(attachment) {
	return Math.min(FEED_WIDEST, Math.max(FEED_TALLEST, ratioOf(attachment)))
}

/**
 * How wide a frame of the given shape can be drawn before the picture
 * filling it is stretched past its own pixels.
 *
 * A picture fills its frame (`cover`), so it is scaled by whichever of the
 * two sides has further to go: the frame's width against the picture's, or
 * its height against the picture's. Neither may be more than the picture
 * has, which is what makes the answer the smaller of the picture's width and
 * its height times the frame's shape.
 *
 * The size is the original's: a picture is small when it was taken or
 * posted small. The preview the browser is given is the server's copy at
 * most 800 pixels on its long side, and holding an upright picture to that
 * would draw every one of them narrower than the column. The preview's size
 * stands in only where the original's is not known.
 *
 * @param {import('../types/Mastodon.js').MediaAttachment} attachment the attachment
 * @param {number} ratio the frame's width divided by its height
 * @return {number} pixels, or Infinity when the picture's size is not known
 */
export function unscaledWidth(attachment, ratio) {
	const size = attachment?.meta?.original ?? attachment?.meta?.small ?? null
	const width = Number(size?.width)
	const height = Number(size?.height)

	if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0 || !(ratio > 0)) {
		return Infinity
	}

	return Math.floor(Math.min(width, height * ratio))
}
