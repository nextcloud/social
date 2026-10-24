/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { interestReason } from '../../../src/utils/interestReason.js'

describe('why a post is in For you', () => {
	it.each([
		['interest', 'Because you\'re interested in #photography', 'Why you\'re seeing this: interested in #photography'],
		['followed', 'Because you follow #photography', 'Why you\'re seeing this: you follow #photography'],
		['related', 'Related to #photography', 'Why you\'re seeing this: related to #photography'],
		['trending', 'Trending here: #photography', 'Why you\'re seeing this: trending here, #photography'],
	])('says %s in its own words', (reason, text, label) => {
		expect(interestReason({ tags: ['photography'], reason })).toEqual({ tag: 'photography', text, label })
	})

	it('lists two tags', () => {
		expect(interestReason({ tags: ['photography', 'analog'], reason: 'interest' })).toMatchObject({
			tag: 'photography',
			text: '#photography, #analog',
			label: 'Why you\'re seeing this: interested in #photography, #analog',
		})
	})

	it('lists two and counts the rest', () => {
		expect(interestReason({ tags: ['photography', 'analog', 'film', 'darkroom'], reason: 'followed' })).toMatchObject({
			tag: 'photography',
			text: '#photography, #analog +2',
			label: 'Why you\'re seeing this: you follow #photography, #analog and 2 more',
		})
	})

	it('takes a tag written with its #, and an unknown reason as interest', () => {
		expect(interestReason({ tags: ['#film'], reason: 'mystery' }).text).toBe('Because you\'re interested in #film')
	})

	it('says a post no hashtag brought is popular, and links nowhere', () => {
		expect(interestReason({ tags: [], reason: 'popular' })).toEqual({
			tag: null,
			text: 'Popular right now',
			label: 'Why you\'re seeing this: popular right now',
		})
	})

	it('explains exploration by topic without claiming a prior author relationship', () => {
		expect(interestReason({
			tags: [],
			reason: 'exploration',
			topics: [{ id: 'tech.apple', labels: { en: 'Apple', de: 'Apple' } }],
		})).toEqual({
			tag: null,
			reason: 'exploration',
			text: 'Discover another voice on Apple',
			label: "Why you're seeing this: included to broaden your sources, related to Apple",
		})
	})

	it('explains an automatic match using the localized topic label', () => {
		const originalLanguage = document.documentElement.lang
		document.documentElement.lang = 'de-DE'
		expect(interestReason({
			tags: [],
			reason: 'automatic',
			topics: [{ id: 'oss.nextcloud', labels: { en: 'Nextcloud', de: 'Nextcloud' } }],
		})).toEqual({
			tag: null,
			reason: 'automatic',
			text: 'Based on your interests in Nextcloud',
			label: "Why you're seeing this: Based on your interests in Nextcloud",
		})
		document.documentElement.lang = originalLanguage
	})

	it('explains the saved selection stage, protocol, and actual additional signals', () => {
		expect(interestReason({
			tags: [],
			reason: 'automatic',
			topics: [{ id: 'tech.apple', labels: { en: 'Apple', de: 'Apple' } }],
			explanation: {
				also: ['FOLLOWED_AUTHOR', 'BEHAVIORAL_PROFILE', 'CACHED_CANDIDATE'],
				protocol: 'atproto',
				rerank_note: 'DIVERSITY_PLACEMENT',
				created_at: 2_000_000_000,
			},
		})).toEqual({
			tag: null,
			reason: 'automatic',
			text: 'Based on your interests in Apple · From an account you follow',
			label: "Why you're seeing this: Based on your interests in Apple. From an account you follow. Your recent activity also influenced this ranking. Selected from posts available on this server. Bluesky post. Placed here to mix authors and topics. Explanation recorded when the feed was loaded.",
		})
	})

	it('has nothing to say without a tag', () => {
		expect(interestReason(null)).toBeNull()
		expect(interestReason({ tags: [], reason: 'interest' })).toBeNull()
	})
})
