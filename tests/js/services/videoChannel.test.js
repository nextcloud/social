/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { forgetChannels, isVideoChannel } from '../../../src/services/videoChannel.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

const video = (id) => ({ id, video_card: { title: id } })
const text = (id) => ({ id, video_card: null })

describe('whether a profile is a channel', () => {
	afterEach(() => {
		forgetChannels()
		vi.mocked(axios.get).mockReset()
	})

	it('asks for the account\'s newest posts of its own, once', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: [video('1'), video('2'), text('3')] })

		expect(await isVideoChannel('films@tube.example')).toBe(true)
		expect(await isVideoChannel('films@tube.example')).toBe(true)

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/accounts/films@tube.example/statuses'),
			{ params: { limit: 20, exclude_reblogs: true } },
		)
	})

	it('is no channel when it mostly writes', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: [video('1'), text('2'), text('3')] })

		expect(await isVideoChannel('bob')).toBe(false)
	})

	it('is no channel when the profile could not be read, and asks again next time', async () => {
		vi.mocked(axios.get).mockRejectedValueOnce(new Error('down'))
		expect(await isVideoChannel('bob')).toBe(false)

		vi.mocked(axios.get).mockResolvedValue({ data: [video('1'), video('2')] })
		expect(await isVideoChannel('bob')).toBe(true)
	})

	it('asks nothing for no account', async () => {
		expect(await isVideoChannel('')).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})
})
