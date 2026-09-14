/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import '../../js/social-adminSettings.js'

// The panel's script is a classic script loaded by templates/settings/admin.php
// rather than a bundled module, so it has nothing to export. What it leaves on
// the app namespace is the seam.
const { loadReports, mount, pages, reportRow, saveServer } = OCA.Social.adminSettings

const REPORTS = `
	<div id="social-moderation">
		<p id="social-reports-none" hidden></p>
		<table class="social-reports" id="social-reports-open"><tbody></tbody></table>
		<p class="social-reports-paging" data-per-page="50">
			<span id="social-reports-open-count" data-total="120"></span>
			<button id="social-reports-more"></button>
		</p>
		<details id="social-reports-resolved-section">
			<summary></summary>
			<table class="social-reports" id="social-reports-resolved"><tbody></tbody></table>
			<p><button id="social-reports-resolved-more" hidden></button></p>
		</details>
	</div>
`

const SERVER = `
	<div id="social-server">
		<input type="email" id="social-server-contact-email" value=" admin@instance.example ">
		<textarea id="social-server-extended-description">a friendly place</textarea>
		<input type="number" id="social-server-max-size" value="20">
		<input type="number" id="social-server-max-video-size" value="4096">
		<input type="number" id="social-server-inbox-throttle" value="0">
		<input type="checkbox" id="social-server-secure-mode" checked>
		<input type="checkbox" id="social-server-publish-blocks">
		<input type="checkbox" id="social-server-allow-self-signed">
		<button id="social-server-save"></button>
		<span id="social-server-message"></span>
	</div>
`

function report(overrides = {}) {
	return {
		id: 7,
		account_id: 'https://spam.example/users/spammer',
		account: 'spammer@spam.example',
		reporter: 'https://cloud.example/users/alice',
		local: true,
		category: 'spam',
		comment: 'endless crypto',
		status_ids: ['https://spam.example/notes/1'],
		creation: 1_700_000_000,
		resolved: false,
		level: '',
		...overrides,
	}
}

/**
 * Answers every fetch with one body.
 *
 * @param {object} body what the route sends back
 * @param {boolean} ok whether it answered 2xx
 * @return {object} the mocked fetch
 */
function answering(body, ok = true) {
	const fetch = vi.fn().mockResolvedValue({ ok, json: () => Promise.resolve(body) })
	globalThis.fetch = fetch

	return fetch
}

/**
 * A page of reports as /moderation/reports answers it.
 *
 * @param {object[]} reports the rows
 * @param {number} total how many there are in all
 * @return {object} the body
 */
function page(reports, total = reports.length) {
	return { reports, total, page: 1, perPage: 50 }
}

const rows = (which) => Array.from(document.querySelectorAll('#social-reports-' + which + ' tbody tr'))

describe('the reports table of the admin settings', () => {
	beforeEach(() => {
		document.body.innerHTML = REPORTS
		OC.Notification.showTemporary = vi.fn()
		pages.open = 1
		pages.resolved = 0
	})

	it('builds a row the click handlers can read, whoever rendered the page', () => {
		const row = reportRow(report())

		// the server renders the first page and this renders the rest; both
		// have to carry the hooks the delegated listeners key on
		expect(row.dataset.reportId).toBe('7')
		expect(row.dataset.actorId).toBe('https://spam.example/users/spammer')
		expect(row.querySelectorAll('td')).toHaveLength(9)
		expect(row.querySelector('.social-report-state').textContent).toBe('Open')
		expect(row.querySelector('.social-report-toggle').dataset.resolved).toBe('0')
		expect(row.querySelector('.social-report-status').dataset.streamId)
			.toBe('https://spam.example/notes/1')
		expect(row.querySelector('.social-status-remove')).not.toBeNull()
	})

	it('shows the date the way the server-rendered rows show it', () => {
		expect(reportRow(report()).querySelectorAll('td')[5].textContent).toBe('2023-11-14 22:13')
		expect(reportRow(report({ creation: 0 })).querySelectorAll('td')[5].textContent).toBe('')
	})

	it('says what already stands against the reported account', () => {
		const row = reportRow(report({ level: 'suspend' }))

		expect(row.querySelector('.social-moderation-state').textContent).toBe('Suspended')
		expect(row.querySelector('[data-level="suspend"]').disabled).toBe(true)
		expect(row.querySelector('[data-level="silence"]').disabled).toBe(false)
	})

	it('names the reporter as remote when the complaint came from another instance', () => {
		const row = reportRow(report({ local: false }))

		expect(row.querySelectorAll('td')[1].textContent).toContain('(remote)')
	})

	it('asks for the next page of the open reports, not for the whole list again', async () => {
		const fetch = answering(page([report()], 120))

		await loadReports(false)

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/social/moderation/reports?resolved=0&page=2')
		expect(rows('open')).toHaveLength(1)
	})

	it('stops offering more once the last page is in', async () => {
		answering(page([report()], 60))

		await loadReports(false)

		expect(document.getElementById('social-reports-more').hidden).toBe(true)
	})

	it('keeps offering more while there are pages left', async () => {
		answering(page([report()], 300))

		await loadReports(false)

		expect(document.getElementById('social-reports-more').hidden).toBe(false)
	})

	it('reads the resolved reports only when the fold is opened', async () => {
		const fetch = answering(page([report({ resolved: true })], 1))
		mount()

		expect(fetch).not.toHaveBeenCalled()

		const section = document.getElementById('social-reports-resolved-section')
		section.open = true
		section.dispatchEvent(new Event('toggle'))
		await vi.waitFor(() => expect(rows('resolved')).toHaveLength(1))

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/social/moderation/reports?resolved=1&page=1')
		expect(rows('open')).toHaveLength(0)
	})

	it('does not read the resolved reports twice for one fold', async () => {
		const fetch = answering(page([report({ resolved: true })], 1))
		mount()

		const section = document.getElementById('social-reports-resolved-section')
		section.open = true
		section.dispatchEvent(new Event('toggle'))
		await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
		section.open = false
		section.dispatchEvent(new Event('toggle'))
		section.open = true
		section.dispatchEvent(new Event('toggle'))

		expect(fetch).toHaveBeenCalledTimes(1)
	})

	it('says so rather than leaving the table half filled when a page cannot be read', async () => {
		answering({}, false)

		await loadReports(false)

		expect(rows('open')).toHaveLength(0)
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Could not load the reports')
	})

	it('resolves a report from a row it rendered itself', async () => {
		const fetch = answering(page([report()], 1))
		mount()
		await loadReports(false)

		fetch.mockClear()
		rows('open')[0].querySelector('.social-report-toggle').click()
		await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/social/moderation/reports/7/resolve')
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ resolved: true })
		await vi.waitFor(() => expect(rows('open')[0].querySelector('.social-report-state').textContent).toBe('Resolved'))
	})
})

describe('the server card of the admin settings', () => {
	beforeEach(() => {
		document.body.innerHTML = SERVER
		OC.Notification.showTemporary = vi.fn()
	})

	it('writes every field at once, because the endpoint takes them all or none', async () => {
		const fetch = answering({ contact_email: 'admin@instance.example' })

		expect(await saveServer()).toBe(true)
		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/social/admin/server')
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
			contactEmail: 'admin@instance.example',
			extendedDescription: 'a friendly place',
			maxSize: 20,
			maxVideoSize: 4096,
			inboxThrottle: 0,
			secureMode: true,
			publishBlocks: false,
			allowSelfSigned: false,
		})
		expect(document.getElementById('social-server-message').textContent).toBe('Saved')
	})

	it('repeats the field the server would not take', async () => {
		answering({ error: 'max_size must be between 1 and 10240 MB' }, false)

		expect(await saveServer()).toBe(false)
		expect(document.getElementById('social-server-message').textContent)
			.toBe('max_size must be between 1 and 10240 MB')
	})

	it('still says something when the refusal carries no reason', async () => {
		answering({}, false)

		expect(await saveServer()).toBe(false)
		expect(document.getElementById('social-server-message').textContent)
			.toBe('Could not save the server settings')
	})
})
