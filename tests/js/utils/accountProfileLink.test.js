/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { localProfileUrl, publicLocalStatusUrl } from '../../../src/utils/accountProfileLink.js'

describe('account profile links', () => {
	it('uses the native Nextcloud profile for a local account', () => {
		expect(localProfileUrl({ acct: 'alice', username: 'alice', nextcloud_user: 'alice' })).toBe('/index.php/u/alice')
		expect(localProfileUrl({ acct: 'a/b', username: 'a/b', nextcloud_user: 'a/b' }, '123')).toBe('/index.php/u/a%2Fb#social-profile-status-123')
	})

	/**
	 * The `/u/` page is the Nextcloud user's, and the username is chosen
	 * when the account is made: linked by the username, it was another
	 * user's page, or nobody's.
	 */
	it('links the page of the account\'s Nextcloud user, not of its username', () => {
		expect(localProfileUrl({ acct: 'aloha_al', username: 'aloha_al', nextcloud_user: 'alice' })).toBe('/index.php/u/alice')
	})

	it('links the account\'s own page where it has no Nextcloud user the page was told', () => {
		const url = 'https://cloud.example.org/apps/social/@aloha_al'
		expect(localProfileUrl({ acct: 'aloha_al', username: 'aloha_al', nextcloud_user: null, url })).toBe(url)
		expect(localProfileUrl({ acct: 'crew', username: 'crew', url }, '123')).toBe(url)
	})

	it('leaves remote accounts to Social or their ActivityPub address', () => {
		expect(localProfileUrl({ acct: 'alice@remote.example', username: 'alice' })).toBe('')
		expect(localProfileUrl({ acct: '', username: 'alice' })).toBe('')
	})

	it('links only public local statuses to the native profile anchor', () => {
		const status = { id: '456', visibility: 'public', account: { acct: 'alice', username: 'alice', nextcloud_user: 'alice' } }
		expect(publicLocalStatusUrl(status)).toBe('/index.php/u/alice#social-profile-status-456')
		expect(publicLocalStatusUrl({ ...status, visibility: 'private' })).toBe('')
		expect(publicLocalStatusUrl({ ...status, account: { acct: 'alice@remote.example', username: 'alice' } })).toBe('')
	})
})
