/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The announcements section of templates/settings/admin.php: what the instance
 * is telling everybody, and the two things an admin does about it.
 *
 * Plain DOM and not a Vue component, like the rest of that page: the
 * administration settings are server-rendered PHP with a handful of buttons,
 * and mounting an app there to draw five columns would pull the Vue runtime
 * into a page that has none.
 *
 * Every cell is written with textContent. The text is the admin's own and the
 * API sends it back unchanged, so innerHTML here would run whatever an earlier
 * admin typed in the next admin's browser.
 */
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * Says something went wrong, in the toast the rest of the app uses.
 *
 * Imported when there is something to say rather than at the top: the package
 * declares no `sideEffects`, so a static import drops all of it — file picker,
 * Vue runtime and all — into a bundle that otherwise has none of that, and 17
 * KiB became 761 KiB. This way it arrives in a chunk of its own, the first
 * time a request fails.
 *
 * @param {string} message what to tell the administrator
 * @return {Promise<void>}
 */
async function report(message) {
	const { showError } = await import('@nextcloud/dialogs')
	showError(message)
}

/**
 * The administration routes of the announcements subsystem.
 *
 * @return {string} their base URL
 */
export function base() {
	return generateUrl('/apps/social/admin/announcements')
}

/**
 * One call to those routes.
 *
 * @param {string} method HTTP verb
 * @param {string} path appended to the base URL
 * @param {object} [body] JSON body, for the calls that have one
 * @return {Promise<object>} what the route answered
 * @throws {Error} carrying the server's own message when it sent one, so a
 *   refused announcement says why rather than "request failed"
 */
export async function call(method, path, body) {
	const response = await fetch(base() + path, {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: getRequestToken(),
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})

	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || ('request failed: ' + response.status))
	}

	return data
}

/**
 * A window as the table shows one: the date the admin gave, or a dash.
 *
 * @param {string|null} value an ISO 8601 datetime, or null for no bound
 * @param {boolean} allDay whether the window is whole days
 * @return {string}
 */
export function formatDate(value, allDay) {
	if (!value) {
		return '—'
	}

	// the API dates in UTC; an all-day window is a date and showing its time
	// would say 22:00 to half of Europe
	return allDay ? value.slice(0, 10) : value.slice(0, 16).replace('T', ' ')
}

/**
 * Draws the table of what exists.
 *
 * @param {Array<object>} announcements as the administration routes send them
 */
export function render(announcements) {
	const tbody = document.getElementById('social-announcements-list')
	if (tbody === null) {
		return
	}

	tbody.textContent = ''

	if (announcements.length === 0) {
		const row = tbody.insertRow()
		const cell = row.insertCell()
		cell.colSpan = 5
		cell.textContent = t('social', 'No announcements.')

		return
	}

	announcements.forEach((announcement) => {
		const row = tbody.insertRow()
		row.dataset.announcementId = announcement.id

		row.insertCell().textContent = announcement.text
		row.insertCell().textContent = formatDate(announcement.starts_at, announcement.all_day)
		row.insertCell().textContent = formatDate(announcement.ends_at, announcement.all_day)
		row.insertCell().textContent = announcement.active
			? t('social', 'Shown now')
			: t('social', 'Not shown')

		const remove = document.createElement('button')
		remove.type = 'button'
		remove.className = 'social-announcement-remove'
		remove.textContent = t('social', 'Remove')
		row.insertCell().appendChild(remove)
	})
}

/**
 * Reads the list and draws it.
 *
 * @return {Promise<void>}
 */
export async function load() {
	try {
		render((await call('GET', '')).announcements)
	} catch {
		await report(t('social', 'Could not read the announcements'))
	}
}

/**
 * Posts what the form holds, and redraws from what came back rather than from
 * what was typed: the server decides the window an all-day announcement ends
 * up with.
 *
 * @return {Promise<void>}
 */
export async function add() {
	const text = document.getElementById('social-announcement-text')
	const starts = document.getElementById('social-announcement-starts')
	const ends = document.getElementById('social-announcement-ends')
	const allDay = document.getElementById('social-announcement-all-day')

	if (text.value.trim() === '') {
		return
	}

	try {
		const data = await call('POST', '', {
			text: text.value,
			starts_at: starts.value,
			ends_at: ends.value,
			all_day: allDay.checked,
		})

		text.value = ''
		starts.value = ''
		ends.value = ''
		allDay.checked = false
		render(data.announcements)
	} catch (error) {
		await report(t('social', 'Could not post the announcement') + ': ' + error.message)
	}
}

/**
 * Removes the announcement of the row a Remove button was clicked in. It is
 * gone for everybody, including the accounts that have read it, so it asks.
 *
 * @param {Event} event the click
 * @return {Promise<void>}
 */
export async function remove(event) {
	const button = event.target.closest('.social-announcement-remove')
	if (button === null) {
		return
	}

	const row = button.closest('tr')
	if (!window.confirm(t('social', 'Remove this announcement? Everybody stops seeing it.'))) {
		return
	}

	try {
		render((await call('DELETE', '/' + row.dataset.announcementId)).announcements)
	} catch {
		await report(t('social', 'Could not remove the announcement'))
	}
}

/**
 * Wires the section up and fills it in. Does nothing on a page that has no
 * announcements section, which is every page but this one.
 *
 * @return {boolean} whether the section was there
 */
export function mount() {
	const section = document.getElementById('social-announcements')
	if (section === null) {
		return false
	}

	document.getElementById('social-announcement-add').addEventListener('click', add)
	document.getElementById('social-announcements-list').addEventListener('click', remove)
	load()

	return true
}

// the bundle is deferred, so DOMContentLoaded may already have gone by the
// time it runs; waiting for an event that has fired leaves the section empty
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
