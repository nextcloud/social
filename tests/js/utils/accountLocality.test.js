/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { isBlueskyAccount, isBlueskyHandle, isHandleOf, isLocalAccount, qualifiedHandle } from '../../../src/utils/accountLocality.js'

const local = { acct: 'alice', username: 'alice', bluesky: null }
const localOnBluesky = { acct: 'alice', username: 'alice', bluesky: { handle: 'alice.cloud.example', did: 'did:plc:a', url: 'https://bsky.app/profile/alice.cloud.example', native: false, active: true } }
const remote = { acct: 'bob@remote.example', username: 'bob', bluesky: null }
const bluesky = { acct: 'carol.bsky.social', username: 'carol.bsky.social', bluesky: { handle: 'carol.bsky.social', did: 'did:plc:c', url: 'https://bsky.app/profile/carol.bsky.social', native: true } }

describe('isLocalAccount', () => {
	it('is true for an account of this server, with or without a Bluesky presence', () => {
		expect(isLocalAccount(local)).toBe(true)
		expect(isLocalAccount(localOnBluesky)).toBe(true)
		expect(isLocalAccount({ acct: 'alice' })).toBe(true)
	})

	it('is false for an account on another server', () => {
		expect(isLocalAccount(remote)).toBe(false)
	})

	it('is false for a Bluesky account, whose handle has no @ either', () => {
		expect(isLocalAccount(bluesky)).toBe(false)
	})

	it('is false without an account', () => {
		expect(isLocalAccount(null)).toBe(false)
		expect(isLocalAccount(undefined)).toBe(false)
	})
})

describe('isBlueskyAccount', () => {
	it('is true only for an account native to Bluesky', () => {
		expect(isBlueskyAccount(bluesky)).toBe(true)
		expect(isBlueskyAccount(localOnBluesky)).toBe(false)
		expect(isBlueskyAccount(local)).toBe(false)
		expect(isBlueskyAccount(remote)).toBe(false)
		expect(isBlueskyAccount(undefined)).toBe(false)
	})
})

describe('isBlueskyHandle', () => {
	it('is true for a handle with a dot and no host', () => {
		expect(isBlueskyHandle('carol.bsky.social')).toBe(true)
	})

	it('is false for a bare user id and for a handle with its host', () => {
		expect(isBlueskyHandle('alice')).toBe(false)
		expect(isBlueskyHandle('bob@remote.example')).toBe(false)
		expect(isBlueskyHandle('')).toBe(false)
		expect(isBlueskyHandle(undefined)).toBe(false)
	})
})

describe('qualifiedHandle', () => {
	it('writes a local account with this host, as Mastodon sends its acct bare', () => {
		expect(qualifiedHandle(local, 'cloud.example')).toBe('@alice@cloud.example')
		expect(qualifiedHandle(localOnBluesky, 'cloud.example')).toBe('@alice@cloud.example')
	})

	it('writes everybody else as their acct', () => {
		expect(qualifiedHandle(remote, 'cloud.example')).toBe('@bob@remote.example')
		expect(qualifiedHandle(bluesky, 'cloud.example')).toBe('@carol.bsky.social')
		expect(qualifiedHandle(null, 'cloud.example')).toBe('')
	})
})

describe('isHandleOf', () => {
	it('matches the handle of an account elsewhere as it is', () => {
		expect(isHandleOf('bob@remote.example', remote)).toBe(true)
		expect(isHandleOf('@Bob@Remote.example', remote)).toBe(true)
		expect(isHandleOf('carol.bsky.social', bluesky)).toBe(true)
		expect(isHandleOf('bob@other.example', remote)).toBe(false)
	})

	it('matches a local account, whose acct carries no host, by its username', () => {
		expect(isHandleOf('alice@cloud.example', local)).toBe(true)
		expect(isHandleOf('alicia@cloud.example', local)).toBe(false)
	})

	it('does not take a remote handle for a Bluesky account of the same name', () => {
		expect(isHandleOf('carol.bsky.social@remote.example', bluesky)).toBe(false)
		expect(isHandleOf('', remote)).toBe(false)
		expect(isHandleOf('bob@remote.example', null)).toBe(false)
	})
})
