/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useFollowByHandle } from '../../../src/composables/useFollowByHandle.js'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

const jens = { acct: 'jens@chaos.social' }

/**
 * Following somebody nobody here follows yet.
 *
 * Every list of strangers — search results, graph suggestions — needs the same
 * three answers, and used to carry its own copy of them.
 */
describe('useFollowByHandle', () => {
	let followAccount

	beforeEach(() => {
		setActivePinia(createPinia())
		followAccount = vi.spyOn(useAccountStore(), 'followAccount').mockResolvedValue(true)
	})

	it('follows by handle, which is all a row of strangers has', async () => {
		const { follow } = useFollowByHandle()

		await follow(jens)

		expect(followAccount).toHaveBeenCalledWith({ accountToFollow: 'jens@chaos.social' })
	})

	it('marks only the row that was asked for', async () => {
		const { follow, isFollowed } = useFollowByHandle()

		await follow(jens)

		expect(isFollowed(jens)).toBe(true)
		expect(isFollowed({ acct: 'bea@example.org' })).toBe(false)
	})

	it('spins on the one row while its request is in flight', async () => {
		let settle
		followAccount.mockReturnValue(new Promise((resolve) => {
			settle = resolve
		}))
		const { follow, isPending } = useFollowByHandle()

		const pending = follow(jens)
		expect(isPending(jens)).toBe(true)
		expect(isPending({ acct: 'bea@example.org' })).toBe(false)

		settle(true)
		await pending
		expect(isPending(jens)).toBe(false)
	})

	/** A spinner left turning is worse than the failure that left it. */
	it('stops spinning when the server refuses', async () => {
		followAccount.mockRejectedValue(new Error('unreachable'))
		const { follow, isPending, isFollowed } = useFollowByHandle()

		await expect(follow(jens)).rejects.toThrow('unreachable')

		expect(isPending(jens)).toBe(false)
		expect(isFollowed(jens)).toBe(false)
	})

	/** The store answering falsely is a refusal, not a follow. */
	it('does not claim a follow the store did not make', async () => {
		followAccount.mockResolvedValue(false)
		const { follow, isFollowed } = useFollowByHandle()

		await follow(jens)

		expect(isFollowed(jens)).toBe(false)
	})

	it('ignores a second click while the first is still waiting', async () => {
		let settle
		followAccount.mockReturnValue(new Promise((resolve) => {
			settle = resolve
		}))
		const { follow } = useFollowByHandle()

		const pending = follow(jens)
		await follow(jens)

		expect(followAccount).toHaveBeenCalledTimes(1)
		settle(true)
		await pending
	})

	it('has nothing to follow when a row carries no handle', async () => {
		const { follow, isPending } = useFollowByHandle()

		await follow({ display_name: 'Nobody' })

		expect(followAccount).not.toHaveBeenCalled()
		expect(isPending({ display_name: 'Nobody' })).toBe(false)
	})
})
