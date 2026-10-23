/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** @param {string} path API suffix @return {string} generated API URL */
function url(path) {
	return generateUrl('apps/social/api/v1/recommendations' + path)
}

/** @param {string|number} postId a local stream identifier @return {Promise<object>} */
export async function fetchRecommendationContext(postId) {
	const { data } = await axios.get(url('/context/' + encodeURIComponent(postId)))
	return data
}

/** @return {string} a random event identifier suitable for idempotent retries */
function eventId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	if (typeof crypto === 'undefined' || typeof crypto.getRandomValues !== 'function') {
		throw new Error('Secure random values are unavailable')
	}
	const bytes = new Uint8Array(16)
	crypto.getRandomValues(bytes)
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/**
 * @param {string|number} postId a local stream identifier
 * @param {string} kind one of the server's explicit feedback operations
 * @return {Promise<object>} the saved action and its undo token
 */
export async function recordRecommendationFeedback(postId, kind) {
	const { data } = await axios.post(url('/feedback'), {
		event_id: eventId(),
		post_id: String(postId),
		kind,
	})
	return data
}

/** @param {string} feedbackId the owner-bound undo token @return {Promise<void>} */
export async function undoRecommendationFeedback(feedbackId) {
	await axios.delete(url('/feedback/' + encodeURIComponent(feedbackId)))
}
