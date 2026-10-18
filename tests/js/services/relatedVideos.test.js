/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { loadRelated } from '../../../src/services/relatedVideos.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

describe('what to watch next', () => {
	afterEach(() => {
		vi.mocked(axios.get).mockReset()
	})

	it('asks the server what follows a video', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: [{ id: '2' }] })

		expect(await loadRelated('17', 8)).toEqual([{ id: '2' }])
		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/videos/17/related'), { params: { limit: 8 } })
	})

	it('is nothing when the server would not say', async () => {
		vi.mocked(axios.get).mockRejectedValue(new Error('down'))
		expect(await loadRelated('17')).toEqual([])

		vi.mocked(axios.get).mockResolvedValue({ data: { error: 'odd' } })
		expect(await loadRelated('17')).toEqual([])
	})
})
