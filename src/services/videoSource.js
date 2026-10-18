/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import logger from './logger.js'
import { videoAttachmentOf } from '../utils/videoCard.js'

/**
 * Where a video attachment plays from, for every player in the app: the
 * attachments in a post and the watch page's own player.
 *
 * Three cases. A plain file a browser opens itself. A remote video that is
 * nothing but an HLS playlist — a PeerTube transcoding to HLS, its default,
 * or a Bluesky video — which Safari plays natively and every other browser
 * only through hls.js. And a local video with a ladder beside its file
 * (`hls_url`), where the file plays first and the ladder is an addition.
 *
 * Every address here is this server's: a remote video and its playlist are
 * proxied, never mirrored and never fetched from the origin by the reader.
 */

/**
 * The attachment a video post is watched as: its first video, else the first
 * file served as one. The same rule the server's `video_card` follows.
 *
 * @param {object|null|undefined} post the video post
 * @return {object|null}
 */
export function playedAttachmentOf(post) {
	return videoAttachmentOf(post)
		?? (post?.media_attachments ?? []).find((item) => String(item?.media_type ?? '').startsWith('video/'))
		?? null
}

/**
 * @param {object|null|undefined} attachment the attachment
 * @return {boolean} whether it is an HLS playlist and nothing else
 */
export function isPlaylist(attachment) {
	const type = (attachment?.media_type || '').toLowerCase()

	return type === 'application/x-mpegurl' || type === 'application/vnd.apple.mpegurl'
}

/**
 * The master playlist of a *local* video's ladder, where there is one.
 *
 * Different from a playlist in the one way that matters: there is a plain
 * file beside it, so failing to load it is not failing to play the video.
 *
 * @param {object|null|undefined} attachment the attachment
 * @return {string} '' when there is none
 */
export function ladderSourceOf(attachment) {
	return (!isPlaylist(attachment) && attachment?.type === 'video')
		? (attachment?.hls_url || '')
		: ''
}

/**
 * The still to show before anything is played.
 *
 * `preview_url` is the file itself for a video uploaded here before posters
 * existed, which is no use as one: a browser handed a video for a poster
 * downloads it to find a frame. So only a preview that differs from the
 * source counts.
 *
 * @param {object|null|undefined} attachment the attachment
 * @return {string|undefined} undefined leaves the attribute off
 */
export function posterOf(attachment) {
	const preview = attachment?.preview_url
	if (typeof preview !== 'string' || preview === '' || preview === attachment?.url) {
		return undefined
	}

	return preview
}

/**
 * Points a video element at an HLS playlist, through hls.js where the
 * browser cannot open one itself.
 *
 * Safari plays a `.m3u8` natively and nothing else does, so everywhere else
 * the library is fetched — **lazily**, the first time somebody actually opens
 * such a video, because it is a few hundred kilobytes and almost every post is
 * not a video at all. Safari is left with the plain file of a laddered video:
 * pointing it at the master would throw away the fallback for an adaptive
 * stream of the same video.
 *
 * A ladder that cannot be loaded must not be the reason a video stops
 * playing, so on a fatal error the player is let go and the element goes
 * back to the plain file (`onFallback` is told). A remote playlist has nothing
 * to fall back to and is left to report itself.
 *
 * @param {HTMLVideoElement} element the element to play in
 * @param {object} attachment the video attachment
 * @param {object} [options] what to ask and tell the caller
 * @param {() => boolean} [options.isCurrent] whether the element is still wanted once the library has arrived
 * @param {() => void} [options.onFallback] called when a ladder gave way to the plain file
 * @param {(hls: object, Hls: object) => void} [options.onCreated] called with the player before it loads, to listen to it
 * @return {Promise<object|null>} the hls.js player, or null where none was needed or none could be made
 */
export async function attachHls(element, attachment, { isCurrent = () => true, onFallback = () => {}, onCreated = () => {} } = {}) {
	const playlist = isPlaylist(attachment)
	const source = playlist ? attachment.url : ladderSourceOf(attachment)
	if (!element || !source) {
		return null
	}

	if (element.canPlayType('application/vnd.apple.mpegurl')) {
		if (playlist) {
			element.src = source
		}

		return null
	}

	try {
		const { default: Hls } = await import(/* webpackChunkName: "hls" */'hls.js')
		if (!Hls.isSupported() || !isCurrent()) {
			return null
		}

		const hls = new Hls({ enableWorker: false })
		if (!playlist) {
			hls.on(Hls.Events.ERROR, (event, data) => {
				if (data?.fatal) {
					logger.debug('falling back to the plain file', { data })
					hls.destroy()
					element.src = attachment.url
					onFallback()
				}
			})
		}

		onCreated(hls, Hls)
		hls.loadSource(source)
		hls.attachMedia(element)

		return hls
	} catch (error) {
		logger.error('could not start an HLS video', { error })

		return null
	}
}
