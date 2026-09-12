/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { showError } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { add, formatDate, load, mount, remove, render } from '../../src/adminAnnouncements.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

const SECTION = `
	<div id="social-announcements">
		<table><tbody id="social-announcements-list"></tbody></table>
		<textarea id="social-announcement-text"></textarea>
		<input type="datetime-local" id="social-announcement-starts">
		<input type="datetime-local" id="social-announcement-ends">
		<input type="checkbox" id="social-announcement-all-day">
		<button id="social-announcement-add"></button>
	</div>
`

const announcement = (overrides = {}) => ({
	id: '1',
	text: 'Maintenance on Sunday',
	starts_at: null,
	ends_at: null,
	all_day: false,
	published_at: '2026-09-11T10:00:00.000Z',
	active: true,
	...overrides,
})

/**
 * Answers every fetch with one body.
 *
 * @param {object} body what the route sends back
 * @param {boolean} ok whether it answered 2xx
 * @return {object} the mocked fetch
 */
const answering = (body, ok = true) => {
	const fetch = vi.fn().mockResolvedValue({ ok, json: () => Promise.resolve(body) })
	globalThis.fetch = fetch

	return fetch
}

const rows = () => Array.from(document.querySelectorAll('#social-announcements-list tr'))
const cells = (row) => Array.from(row.querySelectorAll('td')).map((cell) => cell.textContent)

describe('the announcements section of the admin settings', () => {
	beforeEach(() => {
		document.body.innerHTML = SECTION
		vi.restoreAllMocks()
		showError.mockClear()
	})

	it('lists what the instance is telling everybody', async () => {
		answering({ announcements: [announcement(), announcement({ id: '2', text: 'Over', active: false })] })

		await load()

		expect(rows()).toHaveLength(2)
		expect(cells(rows()[0])[0]).toBe('Maintenance on Sunday')
		expect(cells(rows()[0])[3]).toBe('Shown now')
		expect(cells(rows()[1])[3]).toBe('Not shown')
		expect(rows()[1].dataset.announcementId).toBe('2')
	})

	it('writes the text rather than rendering it', () => {
		// the announcement is the admin's own words and the API sends them
		// back unchanged; innerHTML here would run what an earlier admin typed
		render([announcement({ text: '<img src=x onerror=alert(1)>' })])

		expect(rows()[0].querySelector('td').innerHTML)
			.toBe('&lt;img src=x onerror=alert(1)&gt;')
	})

	it('says so rather than showing an empty table', () => {
		render([])

		expect(rows()).toHaveLength(1)
		expect(rows()[0].textContent).toBe('No announcements.')
	})

	it('shows a whole-day window as days and any other as minutes', () => {
		expect(formatDate('2026-09-12T00:00:00.000Z', true)).toBe('2026-09-12')
		expect(formatDate('2026-09-12T10:30:00.000Z', false)).toBe('2026-09-12 10:30')
		expect(formatDate(null, false)).toBe('—')
	})

	it('posts what the form holds and redraws from the answer', async () => {
		const fetch = answering({ announcements: [announcement({ text: 'Posted' })] })
		document.getElementById('social-announcement-text').value = 'Maintenance on Sunday'
		document.getElementById('social-announcement-starts').value = '2026-09-12T10:00'
		document.getElementById('social-announcement-ends').value = '2026-09-13T10:00'
		document.getElementById('social-announcement-all-day').checked = true

		await add()

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain('/apps/social/admin/announcements')
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({
			text: 'Maintenance on Sunday',
			starts_at: '2026-09-12T10:00',
			ends_at: '2026-09-13T10:00',
			all_day: true,
		})
		// the server decides the window a whole-day announcement ends up with
		expect(cells(rows()[0])[0]).toBe('Posted')
		expect(document.getElementById('social-announcement-text').value).toBe('')
		expect(document.getElementById('social-announcement-all-day').checked).toBe(false)
	})

	it('posts nothing when nothing was typed', async () => {
		const fetch = answering({ announcements: [] })

		await add()

		expect(fetch).not.toHaveBeenCalled()
	})

	it('repeats why an announcement was refused instead of "request failed"', async () => {
		answering({ error: 'starts_at and ends_at are given together or not at all' }, false)
		document.getElementById('social-announcement-text').value = 'Maintenance'

		await add()

		expect(showError).toHaveBeenCalledWith(
			expect.stringContaining('starts_at and ends_at are given together or not at all'),
		)
		// what was typed is still there to correct
		expect(document.getElementById('social-announcement-text').value).toBe('Maintenance')
	})

	it('asks before removing one, because it goes for everybody', async () => {
		const fetch = answering({ announcements: [] })
		render([announcement()])
		vi.spyOn(window, 'confirm').mockReturnValue(false)

		await remove({ target: rows()[0].querySelector('.social-announcement-remove') })

		expect(fetch).not.toHaveBeenCalled()
		expect(rows()).toHaveLength(1)
	})

	it('removes the one whose row was clicked', async () => {
		const fetch = answering({ announcements: [] })
		render([announcement({ id: '7' })])
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		await remove({ target: rows()[0].querySelector('.social-announcement-remove') })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain('/apps/social/admin/announcements/7')
		expect(options.method).toBe('DELETE')
		expect(rows()[0].textContent).toBe('No announcements.')
	})

	it('ignores a click that is not on a Remove button', async () => {
		const fetch = answering({ announcements: [] })
		render([announcement()])

		await remove({ target: rows()[0].querySelector('td') })

		expect(fetch).not.toHaveBeenCalled()
	})

	it('says the list could not be read rather than leaving the table blank', async () => {
		globalThis.fetch = vi.fn().mockRejectedValue(new Error('offline'))

		await load()

		expect(showError).toHaveBeenCalledWith('Could not read the announcements')
	})

	it('does nothing on a page that has no announcements section', () => {
		document.body.innerHTML = ''

		expect(mount()).toBe(false)
	})

	it('wires the section up and fills it in', async () => {
		const fetch = answering({ announcements: [announcement()] })

		expect(mount()).toBe(true)
		await vi.waitFor(() => expect(cells(rows()[0])[0]).toBe('Maintenance on Sunday'))
		expect(fetch.mock.calls[0][1].method).toBe('GET')

		vi.spyOn(window, 'confirm').mockReturnValue(true)
		rows()[0].querySelector('.social-announcement-remove').click()
		await vi.waitFor(() => expect(fetch.mock.calls[1][1].method).toBe('DELETE'))
	})
})
