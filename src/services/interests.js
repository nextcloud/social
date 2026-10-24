/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * The server's side of For you: the reader's list, the settings that go
 * with it, and the signals the web UI reports about what was read.
 *
 * Every mutation answers the whole state — settings, the ranked list, the
 * candidates — so a caller replaces what it holds with the answer rather than
 * working out what changed.
 */

/**
 * @param {string} path what follows `api/v1/interests`
 * @return {string} the URL
 */
function url(path = '') {
	return generateUrl('apps/social/api/v1/interests' + path)
}

/** @param {string} path what follows `api/v1/recommendations` */
function recommendationsUrl(path = '') {
	return generateUrl('apps/social/api/v1/recommendations' + path)
}

/**
 * Whether signals are collected for this reader right now.
 *
 * All three have to agree: the administrators have the feature on, the reader
 * has not opted out, and learning is not paused. Absent — an older server, or
 * a page with nobody signed in — is off.
 *
 * @param {{enabled?: boolean, learning?: boolean, paused?: boolean}|null|undefined} interests `serverData.interests`
 * @return {boolean}
 */
export function isTracking(interests) {
	return interests?.enabled === true && interests?.learning === true && interests?.paused !== true
}

/**
 * Whether the reader has the feed at all: the tab, the post menu item. Pausing
 * stops the learning, not the feed.
 *
 * @param {{enabled?: boolean, learning?: boolean, paused?: boolean}|null|undefined} interests `serverData.interests`
 * @return {boolean}
 */
export function hasInterestsFeed(interests) {
	return interests?.enabled === true && interests?.learning === true
}

/** @return {Promise<object>} the whole state */
export async function fetchInterests() {
	const { data } = await axios.get(url())

	return data
}

/**
 * @param {object} settings any of `learning`, `paused`, `languages`, `noticeAcknowledged`
 * @return {Promise<object>} the whole state, as saved
 */
export async function saveInterestSettings(settings) {
	const { data } = await axios.put(url('/settings'), settings)

	return data
}

/** @return {Promise<object>} the versioned profile generation and consent state */
export async function fetchRecommendationPreferences() {
	return (await axios.get(recommendationsUrl('/preferences'))).data
}

/** @param {string} profileId the profile's protocol identity */
export async function recordRecommendationProfileVisit(profileId) {
	if (typeof profileId !== 'string' || profileId.trim() === '' || profileId.length > 2048) {
		return
	}
	const preferences = await fetchRecommendationPreferences()
	if (preferences.learning_mode !== 'on' || !Number.isInteger(preferences.generation)) {
		return
	}
	const random = globalThis.crypto?.randomUUID?.()
	const eventId = random ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const value = Math.floor(Math.random() * 16)
		return (char === 'x' ? value : (value & 0x3) | 0x8).toString(16)
	})
	await axios.post(recommendationsUrl('/signals'), { events: [{
		event_id: eventId,
		generation: preferences.generation,
		kind: 'profile_visit',
		context: 'profile',
		profile_id: profileId,
	}] })
}

/**
 * Reports what the reader did, in one request.
 *
 * On the way out of the page there is no time for a request to be answered, so
 * the beacon carries it instead. A beacon cannot set a header, which is where
 * the CSRF token normally travels, so it rides in the query; and it has to be
 * a Blob to be sent as JSON rather than as text.
 *
 * @param {object[]} events at most 100, as the contract describes them
 * @param {object} [options] how to send it
 * @param {boolean} [options.beacon] the page is going away
 * @return {Promise<void>}
 */
export async function sendSignals(events, { beacon = false } = {}) {
	if (events.length === 0) {
		return
	}

	const learningEvents = events.filter((event) => typeof event.event_id === 'string' && Number.isInteger(event.generation))
	let legacyQueued = false
	let learningQueued = false
	if (beacon && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
		const target = url('/signals') + '?requesttoken=' + encodeURIComponent(getRequestToken() ?? '')
		legacyQueued = navigator.sendBeacon(target, new Blob([JSON.stringify({ events })], { type: 'application/json' }))
		if (learningEvents.length > 0) {
			const learningTarget = recommendationsUrl('/signals') + '?requesttoken=' + encodeURIComponent(getRequestToken() ?? '')
			learningQueued = navigator.sendBeacon(learningTarget, new Blob([JSON.stringify({ events: learningEvents })], { type: 'application/json' }))
		}
	}

	const requests = []
	if (!legacyQueued) {
		requests.push(axios.post(url('/signals'), { events }))
	}
	if (learningEvents.length > 0 && !learningQueued) {
		requests.push(axios.post(recommendationsUrl('/signals'), { events: learningEvents }))
	}
	await Promise.all(requests)
}

/**
 * "Less like this": lowers the post's hashtags and keeps it out of the feed.
 *
 * @param {string} statusId the post
 * @return {Promise<void>}
 */
export async function lessLikeThis(statusId) {
	await axios.post(url('/less/' + encodeURIComponent(statusId)))
}

/**
 * Takes a "less like this" back.
 *
 * @param {string} statusId the post
 * @return {Promise<void>}
 */
export async function undoLessLikeThis(statusId) {
	await axios.delete(url('/less/' + encodeURIComponent(statusId)))
}

/** @return {Promise<object>} the state, empty */
export async function resetInterests() {
	return (await axios.post(url('/reset'))).data
}
