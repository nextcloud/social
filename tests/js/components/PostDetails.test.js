/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import PostDetails from '../../../src/components/PostDetails.vue'

/**
 * @param {object} overrides anything to change about the status
 * @return {object} a status as the API hands one over
 */
function post(overrides = {}) {
	return {
		id: '42',
		created_at: '2026-09-13T10:30:00.000Z',
		visibility: 'public',
		language: null,
		edited_at: null,
		local: true,
		url: 'https://cloud.example.org/@alice/42',
		uri: 'https://cloud.example.org/@alice/42',
		...overrides,
	}
}

const details = (overrides) => mount(PostDetails, { props: { status: post(overrides) } })
const items = (wrapper) => wrapper.findAll('.post-details__item').map((item) => item.text())

describe('PostDetails', () => {
	it('says when, in full, where the card said "14 hours ago"', () => {
		const wrapper = details()

		expect(wrapper.find('time').attributes('datetime')).toBe('2026-09-13T10:30:00.000Z')
		expect(wrapper.find('time').text()).toMatch(/2026/)
	})

	it('says who it went to, in the words the composer offers', () => {
		expect(items(details({ visibility: 'followers' }))).toContain('Followers')
		expect(items(details({ visibility: 'direct' }))).toContain('Direct message')
	})

	it('says something rather than nothing for an audience it does not know', () => {
		// a visibility this version has no name for is still a fact about the post
		expect(items(details({ visibility: 'sideways' }))).toContain('Unknown audience')
	})

	it('names the language rather than printing its code', () => {
		const text = items(details({ language: 'de' })).join(' ')

		expect(text).toMatch(/German/i)
		expect(text).not.toContain('de ')
	})

	it('says nothing about a language nobody declared', () => {
		expect(items(details()).join(' ')).not.toMatch(/German|English/i)
	})

	it('falls back to the code for a language nothing can name', () => {
		expect(items(details({ language: 'qqq' })).join(' ')).toContain('qqq')
	})

	it('says when it was last edited, and nothing when it never was', () => {
		expect(items(details({ edited_at: '2026-09-13T12:00:00.000Z' })).join(' ')).toMatch(/Edited/)
		expect(items(details()).join(' ')).not.toMatch(/Edited/)
	})

	it('links a remote post to where it actually lives', () => {
		const link = details({ local: false, url: 'https://remote.example/@bob/9' }).find('.post-details__original')

		expect(link.attributes('href')).toBe('https://remote.example/@bob/9')
		expect(link.attributes('rel')).toBe('noopener noreferrer')
	})

	it('offers no link for a local post, whose own address is this page', () => {
		expect(details().find('.post-details__original').exists()).toBe(false)
	})

	it('refuses an address that is not one', () => {
		// a remote id can be any URI; only something a browser can follow
		// belongs behind a link the reader is invited to press
		const wrapper = details({ local: false, url: '', uri: 'tag:example,2026:objectId=9' })

		expect(wrapper.find('.post-details__original').exists()).toBe(false)
	})
})
