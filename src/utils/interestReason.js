/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * @param {{tag: string|null, reason?: string, text: string, label: string}} reason base visible and spoken explanation
 * @param {{also?: string[], protocol?: string, rerank_note?: string}|null|undefined} snapshot immutable server-side explanation
 * @return {{tag: string|null, reason?: string, text: string, label: string}} the localized explanation
 */
function explainSnapshot(reason, snapshot) {
	if (!snapshot || typeof snapshot !== 'object') {
		return reason
	}

	const details = []
	const labels = {
		FOLLOWED_AUTHOR: t('social', 'From an account you follow'),
		BEHAVIORAL_PROFILE: t('social', 'Your recent activity also influenced this ranking'),
		SIMILARITY_FEEDBACK: t('social', 'Your feedback about similar posts also influenced this ranking'),
		AUTHOR_FEEDBACK: t('social', 'Your feedback about this author also influenced this ranking'),
		FORMAT_FEEDBACK: t('social', 'Your format preference also influenced this ranking'),
		CACHED_CANDIDATE: t('social', 'Selected from posts available on this server'),
	}
	for (const key of Array.isArray(snapshot.also) ? snapshot.also : []) {
		if (Object.hasOwn(labels, key) && !details.includes(labels[key])) {
			details.push(labels[key])
		}
	}
	if (snapshot.protocol === 'atproto') {
		details.push(t('social', 'Bluesky post'))
	} else if (snapshot.protocol === 'activitypub') {
		details.push(t('social', 'Fediverse post'))
	}
	if (snapshot.rerank_note === 'DIVERSITY_PLACEMENT') {
		details.push(t('social', 'Placed here to mix authors and topics'))
	} else if (snapshot.rerank_note === 'EXPLORATION_SLOT' && reason.reason !== 'exploration') {
		details.push(t('social', 'Included to broaden the sources in your feed'))
	}
	if (details.length === 0) {
		return reason
	}

	const detailsLabel = `${details.join('. ')}. ${t('social', 'Explanation recorded when the feed was loaded')}.`
	const visibleDetail = details[0]
	return {
		...reason,
		text: `${reason.text} · ${visibleDetail}`,
		label: `${reason.label}. ${detailsLabel}`,
	}
}

/**
 * What the chip over a post in For you says: why the post is there.
 *
 * One matching hashtag is said as a reason ("Because you follow #film"); two
 * or more are listed instead, because a sentence naming three tags is longer
 * than the post it sits over. The accessible name always gives the reason, so
 * a screen reader hears why rather than a list of tags.
 *
 * A `popular` post matched none of the reader's hashtags: it filled a ranking
 * that was too short, from what is trending in the same kind.
 * Its chip says exactly that and links nowhere, since no tag put it there.
 * An `exploration` item was selected from outside the leading predictions;
 * its topic match is shown without implying an established author affinity.
 *
 * @param {{tags?: string[], reason?: string, topics?: {id?: string, labels?: Record<string, string>}[], explanation?: {also?: string[], protocol?: string, rerank_note?: string}}|null|undefined} interest the post's `interest` field
 * @return {{tag: string|null, reason?: string, text: string, label: string}|null} the tag the chip links to
 *                                                                (null for none) and its
 *                                                                words, or null for no chip
 */
export function interestReason(interest) {
	if (interest?.reason === 'topic') {
		const locale = typeof document === 'undefined'
			? 'en'
			: (document.documentElement.lang || 'en').split(/[-_]/)[0].toLowerCase()
		const topics = (interest.topics ?? [])
			.map((topic) => topic?.labels?.[locale] ?? topic?.labels?.en ?? topic?.id)
			.filter((topic) => typeof topic === 'string' && topic !== '')
		if (topics.length === 0) {
			return null
		}
		const listed = topics.slice(0, 2).join(', ')
		const rest = topics.length - 2
		const text = topics.length === 1
			? t('social', 'Because you chose {topic}', { topic: topics[0] })
			: t('social', 'Topics you chose: {topics}', { topics: rest > 0 ? `${listed} +${rest}` : listed })
		return explainSnapshot({
			tag: null,
			reason: 'topic',
			text,
			label: t('social', "Why you're seeing this: it matches your chosen topic {topics}", { topics: topics.join(', ') }),
		}, interest.explanation)
	}

	if (interest?.reason === 'popular') {
		return {
			tag: null,
			text: t('social', 'Popular right now'),
			label: t('social', 'Why you\'re seeing this: popular right now'),
		}
	}

	if (interest?.reason === 'exploration') {
		const locale = typeof document === 'undefined'
			? 'en'
			: (document.documentElement.lang || 'en').split(/[-_]/)[0].toLowerCase()
		const topics = (interest.topics ?? [])
			.map((topic) => topic?.labels?.[locale] ?? topic?.labels?.en ?? topic?.id)
			.filter((topic) => typeof topic === 'string' && topic !== '')
		if (topics.length === 0) {
			return null
		}
		const listed = topics.slice(0, 2).join(', ')
		const rest = topics.length - 2
		return explainSnapshot({
			tag: null,
			reason: 'exploration',
			text: t('social', 'Discover another voice on {topics}', { topics: rest > 0 ? `${listed} +${rest}` : listed }),
			label: t('social', "Why you're seeing this: included to broaden your sources, related to {topics}", { topics: topics.join(', ') }),
		}, interest.explanation)
	}

	const tags = (interest?.tags ?? []).map((tag) => String(tag).replace(/^#/, '')).filter((tag) => tag !== '')
	if (tags.length === 0) {
		return null
	}

	const first = '#' + tags[0]
	const reason = ['interest', 'followed', 'related', 'trending'].includes(interest.reason) ? interest.reason : 'interest'
	const rest = tags.length - 2
	const listed = tags.slice(0, 2).map((tag) => '#' + tag).join(', ')

	let text
	let spoken
	if (tags.length === 1) {
		text = {
			interest: t('social', 'Because you\'re interested in {tag}', { tag: first }),
			followed: t('social', 'Because you follow {tag}', { tag: first }),
			related: t('social', 'Related to {tag}', { tag: first }),
			trending: t('social', 'Trending here: {tag}', { tag: first }),
		}[reason]
		spoken = first
	} else if (rest > 0) {
		text = t('social', '{tags} +{count}', { tags: listed, count: rest })
		spoken = n('social', '{tags} and %n more', '{tags} and %n more', rest, { tags: listed })
	} else {
		text = listed
		spoken = listed
	}

	const why = {
		interest: t('social', 'Why you\'re seeing this: interested in {tags}', { tags: spoken }),
		followed: t('social', 'Why you\'re seeing this: you follow {tags}', { tags: spoken }),
		related: t('social', 'Why you\'re seeing this: related to {tags}', { tags: spoken }),
		trending: t('social', 'Why you\'re seeing this: trending here, {tags}', { tags: spoken }),
	}[reason]

	return {
		tag: tags[0],
		text,
		label: why,
	}
}
