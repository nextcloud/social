/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearDraft, loadDraft, saveDraft } from '../../../src/services/draft.js'

const KEY = 'social.composer.draft'

describe('the composer draft', () => {
	beforeEach(() => {
		localStorage.clear()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('keeps what was typed and gives it back', () => {
		expect(saveDraft({ text: 'half a thought', spoilerText: 'spoilers', visibility: 'followers' })).toBe(true)

		expect(loadDraft()).toMatchObject({
			text: 'half a thought',
			spoilerText: 'spoilers',
			visibility: 'followers',
		})
	})

	it('has nothing to give back when nothing was kept', () => {
		expect(loadDraft()).toBeNull()
	})

	it('replaces the previous draft rather than adding to it', () => {
		saveDraft({ text: 'first' })
		saveDraft({ text: 'second' })

		expect(loadDraft().text).toBe('second')
	})

	it('treats an empty composer as nothing to keep', () => {
		saveDraft({ text: 'something' })
		saveDraft({ text: '   ' })

		expect(loadDraft()).toBeNull()
	})

	it('keeps a warning even when the body is still empty', () => {
		saveDraft({ text: '', spoilerText: 'spoilers' })

		expect(loadDraft()).toMatchObject({ text: '', spoilerText: 'spoilers' })
	})

	it('forgets the draft on request', () => {
		saveDraft({ text: 'off it goes' })
		clearDraft()

		expect(loadDraft()).toBeNull()
	})

	it('drops a draft nobody came back for in a week', () => {
		localStorage.setItem(KEY, JSON.stringify({
			text: 'last month',
			savedAt: Date.now() - 8 * 24 * 3600 * 1000,
		}))

		expect(loadDraft()).toBeNull()
		expect(localStorage.getItem(KEY)).toBeNull()
	})

	it('discards anything that is not a draft', () => {
		localStorage.setItem(KEY, 'not json at all')
		expect(loadDraft()).toBeNull()

		localStorage.setItem(KEY, JSON.stringify({ nothing: 'useful' }))
		expect(loadDraft()).toBeNull()
	})

	it('says so rather than throwing when the store cannot be written', () => {
		vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(saveDraft({ text: 'a private window' })).toBe(false)
	})

	it('reads as empty rather than throwing when the store cannot be read', () => {
		vi.spyOn(localStorage, 'getItem').mockImplementation(() => {
			throw new Error('denied')
		})

		expect(loadDraft()).toBeNull()
	})
})
