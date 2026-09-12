/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { notificationSummary } from '../../../src/services/notifications.js'

function notification(type, acct = 'bob@remote.tld') {
	return {
		id: '1',
		type,
		created_at: '2026-09-07T10:00:00.000Z',
		account: { id: '22', acct, username: acct.split('@')[0] },
	}
}

describe('notificationSummary', () => {
	it.each([
		['mention', 'bob@remote.tld mentioned you'],
		['status', 'bob@remote.tld posted a status'],
		['reblog', 'bob@remote.tld boosted your post'],
		['follow', 'bob@remote.tld started to follow you'],
		['follow_request', 'bob@remote.tld requested to follow you'],
		['favourite', 'bob@remote.tld liked your post'],
		['poll', 'bob@remote.tld ended the poll'],
		['update', 'bob@remote.tld edited a status'],
		['admin.sign_up', 'bob@remote.tld signed up'],
		['admin.report', 'bob@remote.tld filed a report'],
	])('describes a %s notification with the acting account', (type, expected) => {
		expect(notificationSummary(notification(type))).toBe(expected)
	})

	it('uses the full handle of the acting account, local or remote', () => {
		expect(notificationSummary(notification('mention', 'alice'))).toBe('alice mentioned you')
	})

	it('returns an empty summary for unknown notification types', () => {
		expect(notificationSummary(notification('something.new'))).toBe('')
		expect(notificationSummary({ type: undefined, account: { acct: 'x' } })).toBe('')
	})

	it('does not let a handle inject markup into the summary', () => {
		expect(notificationSummary(notification('mention', '<b>evil</b>@remote.tld'))).toBe('&lt;b&gt;evil&lt;/b&gt;@remote.tld mentioned you')
	})
})
