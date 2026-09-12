/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The account browser of templates/settings/admin.php, and the button that
 * takes a reported post down.
 *
 * Until this, only a *reported* account could be acted on from the web: an
 * instance that had a problem with somebody nobody had filed a report about
 * needed a moderation client and a token, and `Moderation#statusRemove` had a
 * route, a controller and nothing to call it.
 *
 * Plain DOM and not a Vue component, like the rest of that page: the
 * administration settings are server-rendered PHP with a handful of buttons,
 * and mounting an app there to draw four columns would pull the Vue runtime
 * into a page that has none.
 *
 * Every cell is written with textContent. A handle and an instance name are
 * whatever a remote server sent, so innerHTML here would run another
 * instance's markup in a moderator's browser — on the page whose buttons
 * delete accounts.
 */
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * The administration routes of the moderation subsystem.
 *
 * @return {string} their base URL
 */
export function base() {
	return generateUrl('/apps/social/moderation')
}

/**
 * One call to those routes.
 *
 * @param {string} method HTTP verb
 * @param {string} path appended to the base URL
 * @param {object} [body] JSON body, for the calls that have one
 * @return {Promise<object>} what the route answered
 * @throws {Error} carrying the server's own message when it sent one
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

/** What one page of the browser holds, as ModerationController pages it. */
export const PAGE = 40

/** What the browser is currently showing, so "show more" can go on from it. */
const state = { accounts: [], cursor: 0 }

/**
 * What stands against an account, in the moderator's words.
 *
 * @param {string} level 'silence', 'suspend' or '' for nothing
 * @return {string} what the table shows
 */
export function stateOf(level) {
	if (level === 'suspend') {
		return t('social', 'Suspended')
	}

	return level === 'silence' ? t('social', 'Silenced') : t('social', 'Nothing')
}

/**
 * One button of the decision group.
 *
 * @param {string} level 'silence', 'suspend' or '' to lift
 * @param {string} label what it says
 * @param {string} current the decision standing against the account
 * @return {HTMLButtonElement} the button
 */
function decisionButton(level, label, current) {
	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'social-account-moderate'
	button.dataset.level = level
	button.textContent = label
	// the decision that already stands is the one there is no point taking,
	// and "lift" is pointless when nothing stands
	button.disabled = (level === current)

	return button
}

/**
 * Draws the accounts, appending when asked so "show more" does not redraw the
 * page under a moderator's cursor.
 *
 * @param {Array} accounts what the route sent
 * @param {boolean} [append] whether to keep what is already shown
 */
export function render(accounts, append = false) {
	const list = document.getElementById('social-accounts-list')
	const table = document.querySelector('table.social-accounts')
	const empty = document.getElementById('social-accounts-empty')

	if (!append) {
		list.textContent = ''
		state.accounts = []
	}
	state.accounts = state.accounts.concat(accounts)

	accounts.forEach((account) => {
		const row = document.createElement('tr')
		row.dataset.actorId = account.actor_id

		const name = document.createElement('td')
		name.textContent = account.handle || account.username
		row.appendChild(name)

		const domain = document.createElement('td')
		domain.textContent = account.local ? t('social', 'This instance') : (account.domain || '')
		row.appendChild(domain)

		const level = document.createElement('td')
		level.className = 'social-account-state'
		level.textContent = stateOf(account.level)
		row.appendChild(level)

		const actions = document.createElement('td')
		actions.appendChild(decisionButton('silence', t('social', 'Silence'), account.level))
		actions.appendChild(decisionButton('suspend', t('social', 'Suspend'), account.level))
		actions.appendChild(decisionButton('', t('social', 'Lift'), account.level))
		row.appendChild(actions)

		list.appendChild(row)
	})

	const shown = state.accounts.length
	table.hidden = shown === 0
	empty.hidden = shown > 0
	// a full page may have more behind it; a short one is the end of the list
	document.getElementById('social-accounts-more').hidden = accounts.length < PAGE
}

/**
 * Runs the search the form describes.
 *
 * @param {boolean} [more] whether to continue the current page rather than
 *   start again
 */
export async function search(more = false) {
	const params = new URLSearchParams({
		query: document.getElementById('social-accounts-query').value.trim(),
		origin: document.getElementById('social-accounts-origin').value,
		status: document.getElementById('social-accounts-status').value,
	})
	if (more && state.cursor > 0) {
		params.set('maxId', String(state.cursor))
	}

	try {
		const data = await call('GET', '/accounts?' + params.toString())
		const cursors = data.cursors || []
		state.cursor = cursors.length > 0 ? cursors[cursors.length - 1] : 0
		render(data.accounts || [], more)
	} catch {
		OC.Notification.showTemporary(t('social', 'Could not read the accounts'))
	}
}

/**
 * Silences, suspends or lifts, from the account browser.
 *
 * Suspending deletes, so it asks first and says what it will cost.
 *
 * @param {Event} event the click
 */
export async function moderate(event) {
	const button = event.target.closest('.social-account-moderate')
	if (button === null) {
		return
	}

	const row = button.closest('tr')
	const level = button.dataset.level
	if (level === 'suspend' && !window.confirm(t('social',
		'Suspending deletes every post this account has here and refuses anything it sends afterwards. '
		+ 'Lifting the suspension later will not bring the posts back. Continue?'))) {
		return
	}

	try {
		await call('POST', '/accounts', { actorId: row.dataset.actorId, level, comment: '' })
		row.querySelector('.social-account-state').textContent = stateOf(level)
		row.querySelectorAll('.social-account-moderate').forEach((other) => {
			other.disabled = other.dataset.level === level
		})
		OC.Notification.showTemporary(level === ''
			? t('social', 'The decision was lifted')
			: t('social', 'The decision was applied'))
	} catch {
		OC.Notification.showTemporary(t('social', 'Could not apply the decision'))
	}
}

/**
 * Takes one reported post down.
 *
 * The post goes and the account stays, which is the lightest thing a moderator
 * can do about a report and the one the panel could not do at all.
 *
 * @param {Event} event the click
 */
export async function takeDown(event) {
	const button = event.target.closest('.social-status-remove')
	if (button === null) {
		return
	}

	const status = button.closest('.social-report-status')
	if (!window.confirm(t('social', 'This deletes the post for everybody here. Continue?'))) {
		return
	}

	try {
		await call('POST', '/statuses/remove', { streamId: status.dataset.streamId })
		status.textContent = t('social', 'Taken down')
		OC.Notification.showTemporary(t('social', 'The post was taken down'))
	} catch {
		OC.Notification.showTemporary(t('social', 'Could not take the post down'))
	}
}

/**
 * Wires both up. Does nothing on a page that has no moderation panel, which is
 * every page but this one.
 *
 * @return {boolean} whether the panel was there
 */
export function mount() {
	const reports = document.querySelector('.social-reports')
	if (reports !== null) {
		reports.addEventListener('click', takeDown)
	}

	const section = document.getElementById('social-accounts')
	if (section === null) {
		return reports !== null
	}

	document.getElementById('social-accounts-search').addEventListener('click', () => search(false))
	document.getElementById('social-accounts-query').addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			search(false)
		}
	})
	document.getElementById('social-accounts-more').addEventListener('click', () => search(true))
	document.getElementById('social-accounts-list').addEventListener('click', moderate)
	search(false)

	return true
}

// the bundle is deferred, so DOMContentLoaded may already have gone by the
// time it runs; waiting for an event that has fired leaves the section empty
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount)
} else {
	mount()
}
