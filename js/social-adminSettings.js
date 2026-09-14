/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Hand-written companion of templates/settings/admin.php — not part of the
 * webpack build on purpose: the moderation panel stays dependency-free.
 *
 * `OC.generateUrl` is deprecated in favour of `@nextcloud/router`, which is an
 * import, and importing is the one thing this file cannot do. The global is
 * still what a non-bundled script has, so the rule is off here rather than
 * left to warn on every run.
 */
/* eslint-disable @nextcloud/no-deprecated-globals */
(function() {
	'use strict'

	const app = OC.generateUrl('/apps/social')
	const base = app + '/moderation'

	/**
	 * Send a JSON body somewhere below /apps/social and decode the answer,
	 * whether or not it was refused.
	 *
	 * The refusal matters for the Server card: the endpoint answers a 422 with
	 * the field it would not take, and swallowing that would leave an
	 * administrator with a form that says only "no".
	 *
	 * @param {string} url the whole URL
	 * @param {object} body what to send
	 * @return {Promise<{ok: boolean, data: object}>} how it went, and the body
	 */
	async function send(url, body) {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify(body),
		})
		let data = {}
		try {
			data = await response.json()
		} catch {
			// a refusal without a body is still a refusal
		}
		return { ok: response.ok, data }
	}

	/**
	 * POST a JSON body to a moderation route and return the decoded answer.
	 *
	 * @param {string} path route below /apps/social/moderation
	 * @param {object} body what to send
	 * @return {Promise<object>} the decoded response
	 */
	async function post(path, body) {
		const { ok, data } = await send(base + path, body)
		if (!ok) {
			throw new Error('request failed')
		}
		return data
	}

	/**
	 * Read a moderation route.
	 *
	 * @param {string} path route below /apps/social/moderation
	 * @param {object} params the query string, as an object
	 * @return {Promise<object>} the decoded response
	 */
	async function read(path, params) {
		const query = Object.entries(params)
			.map(([key, value]) => encodeURIComponent(key) + '=' + encodeURIComponent(value))
			.join('&')
		const response = await fetch(base + path + '?' + query, {
			headers: { requesttoken: OC.requestToken },
		})
		if (!response.ok) {
			throw new Error('request failed')
		}
		return response.json()
	}

	/**
	 * Resolve a report, or reopen one, from the button in its row.
	 *
	 * The row stays in the table it is in. Moving it to the other one would
	 * renumber the pages under the cursor of whoever is working through them;
	 * the next load puts it where it belongs.
	 *
	 * @param {Event} event the click
	 */
	function onToggleReport(event) {
		const button = event.target.closest('.social-report-toggle')
		if (button === null) {
			return
		}

		const row = button.closest('tr')
		const resolve = button.dataset.resolved !== '1'
		post('/reports/' + row.dataset.reportId + '/resolve', { resolved: resolve })
			.then(() => {
				button.dataset.resolved = resolve ? '1' : '0'
				button.textContent = resolve ? t('social', 'Reopen') : t('social', 'Resolve')
				row.querySelector('.social-report-state').textContent
					= resolve ? t('social', 'Resolved') : t('social', 'Open')
			})
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not update the report')))
	}

	/**
	 * Silence, suspend or lift, from the row of the report that prompted it.
	 * Suspending deletes, so it asks first and says what it will cost.
	 */
	/**
	 * @param {Event} event the click on a moderation button
	 */
	function onModerate(event) {
		const button = event.target.closest('.social-moderate')
		if (button === null) {
			return
		}

		const row = button.closest('tr')
		const actorId = row.dataset.actorId
		const level = button.dataset.level
		if (!actorId) {
			return
		}

		const warning = t(
			'social',
			'Suspending deletes every post this account has here and refuses anything it sends afterwards. '
			+ 'Lifting the suspension later will not bring the posts back. Continue?',
		)
		if (level === 'suspend' && !window.confirm(warning)) {
			return
		}

		post('/accounts', { actorId, level, comment: '' })
			.then(() => {
				row.querySelectorAll('.social-moderate').forEach((other) => {
					other.disabled = other.dataset.level === level && level !== ''
				})
				const state = row.querySelector('.social-moderation-state')
				if (state) {
					state.textContent = level === 'suspend'
						? t('social', 'Suspended')
						: (level === 'silence' ? t('social', 'Silenced') : '')
				}
				OC.Notification.showTemporary(level === ''
					? t('social', 'The decision was lifted')
					: t('social', 'The decision was applied'))
			})
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not apply the decision')))
	}

	/**
	 * A cell, because nine of them in a row is what most of a report is.
	 *
	 * @param {string} text what it holds
	 * @param {string} className the class to put on it, or ''
	 * @return {HTMLElement} the cell
	 */
	function cell(text, className = '') {
		const td = document.createElement('td')
		td.textContent = text
		if (className !== '') {
			td.className = className
		}
		return td
	}

	/**
	 * A button, with the data attributes the delegated handlers read.
	 *
	 * @param {string} text its label
	 * @param {string} className the class the handler is keyed on
	 * @param {object} data what to put in its dataset
	 * @return {HTMLElement} the button
	 */
	function button(text, className, data = {}) {
		const element = document.createElement('button')
		element.type = 'button'
		element.className = className
		element.textContent = text
		Object.entries(data).forEach(([key, value]) => {
			element.dataset[key] = value
		})
		return element
	}

	/**
	 * The date as the server-rendered rows carry it: UTC, to the minute.
	 *
	 * @param {number} creation seconds since the epoch, or 0
	 * @return {string} the date, or ''
	 */
	function moment(creation) {
		return creation > 0
			? new Date(creation * 1000).toISOString().slice(0, 16).replace('T', ' ')
			: ''
	}

	/**
	 * One row of a report table.
	 *
	 * The twin of `$reportRow` in templates/settings/admin.php: the first page
	 * is rendered by the server and everything after it here, so the two have
	 * to agree on the columns and on the hooks the click handlers read —
	 * `data-report-id`, `data-actor-id`, and the three button classes.
	 *
	 * @param {object} report one entry of /moderation/reports
	 * @return {HTMLElement} the row
	 */
	function reportRow(report) {
		const row = document.createElement('tr')
		row.dataset.reportId = String(report.id)
		row.dataset.actorId = report.account_id

		const account = document.createElement('td')
		if (report.account) {
			const link = document.createElement('a')
			link.href = report.account_id
			link.target = '_blank'
			link.rel = 'noreferrer noopener'
			link.textContent = report.account
			account.appendChild(link)
		} else {
			account.textContent = report.account_id
		}
		row.appendChild(account)

		const reporter = cell(report.reporter)
		if (!report.local) {
			const remote = document.createElement('em')
			remote.textContent = ' (' + t('social', 'remote') + ')'
			reporter.appendChild(remote)
		}
		row.appendChild(reporter)

		row.appendChild(cell(report.category))
		row.appendChild(cell(report.comment))

		const statuses = cell('', 'social-report-statuses')
		report.status_ids.forEach((statusId) => {
			const span = document.createElement('span')
			span.className = 'social-report-status'
			span.dataset.streamId = statusId
			if (statusId.startsWith('https://')) {
				const link = document.createElement('a')
				link.href = statusId
				link.target = '_blank'
				link.rel = 'noreferrer noopener'
				link.textContent = '↗'
				span.appendChild(link)
			} else {
				span.appendChild(document.createTextNode(statusId))
			}
			const remove = button(t('social', 'Take down'), 'social-status-remove')
			remove.title = t('social', 'Take this post down')
			span.appendChild(remove)
			statuses.appendChild(span)
		})
		row.appendChild(statuses)

		row.appendChild(cell(moment(report.creation)))
		row.appendChild(cell(
			report.resolved ? t('social', 'Resolved') : t('social', 'Open'),
			'social-report-state',
		))

		const decision = cell('', 'social-moderation')
		const state = document.createElement('span')
		state.className = 'social-moderation-state'
		state.textContent = report.level === 'suspend'
			? t('social', 'Suspended')
			: (report.level === 'silence' ? t('social', 'Silenced') : '')
		decision.appendChild(state)
		const silence = button(t('social', 'Silence'), 'social-moderate', { level: 'silence' })
		silence.disabled = report.level === 'silence'
		decision.appendChild(silence)
		const suspend = button(t('social', 'Suspend'), 'social-moderate', { level: 'suspend' })
		suspend.disabled = report.level === 'suspend'
		decision.appendChild(suspend)
		decision.appendChild(button(t('social', 'Lift'), 'social-moderate', { level: '' }))
		row.appendChild(decision)

		const toggle = cell('')
		toggle.appendChild(button(
			report.resolved ? t('social', 'Reopen') : t('social', 'Resolve'),
			'social-report-toggle',
			{ resolved: report.resolved ? '1' : '0' },
		))
		row.appendChild(toggle)

		return row
	}

	/** Which page of each of the two lists has been fetched so far. */
	const pages = { open: 1, resolved: 0 }

	/**
	 * Append the next page of the open reports, or of the resolved ones.
	 *
	 * @param {boolean} resolved the resolved ones instead of the open ones
	 * @return {Promise<void>} once the rows are in the table
	 */
	async function loadReports(resolved) {
		const which = resolved ? 'resolved' : 'open'
		const table = document.getElementById('social-reports-' + which)
		const more = document.getElementById(resolved ? 'social-reports-resolved-more' : 'social-reports-more')
		if (table === null) {
			return
		}

		// claimed before the request rather than after it: two clicks on
		// "Show more" while the first is in flight would otherwise both ask
		// for the same page and the table would hold each row twice
		const next = pages[which] + 1
		pages[which] = next

		let answer
		try {
			answer = await read('/reports', { resolved: resolved ? '1' : '0', page: next })
		} catch {
			pages[which] = next - 1
			OC.Notification.showTemporary(t('social', 'Could not load the reports'))
			return
		}

		const body = table.querySelector('tbody')
		answer.reports.forEach((report) => body.appendChild(reportRow(report)))
		table.hidden = body.children.length === 0

		if (more !== null) {
			more.hidden = next * answer.perPage >= answer.total
		}
	}

	/**
	 * Fill the resolved table the first time the fold is opened.
	 *
	 * Not on load: on an instance that has been moderated for a year the
	 * resolved reports are most of the table and none of what anybody came to
	 * the page for.
	 *
	 * @return {Promise<void>} once the first page is in
	 */
	async function onResolvedToggle() {
		const section = document.getElementById('social-reports-resolved-section')
		if (!section.open || pages.resolved > 0) {
			return
		}
		await loadReports(true)
	}

	/**
	 * Redraw the Fediverse access list in place.
	 *
	 * @param {string[]} list the addresses now on it
	 */
	function renderAccessList(list) {
		const ul = document.getElementById('social-access-list')
		ul.textContent = ''
		list.forEach((address) => {
			const li = document.createElement('li')
			li.dataset.address = address
			li.textContent = address + ' '
			li.appendChild(button(t('social', 'Remove'), 'social-access-remove'))
			ul.appendChild(li)
		})
	}

	/** Add the address in the input to the Fediverse access list. */
	function onAddAddress() {
		const input = document.getElementById('social-access-address')
		const address = input.value.trim()
		if (address === '') {
			return
		}
		post('/fediverse/add', { address })
			.then((data) => {
				input.value = ''
				renderAccessList(data.list)
			})
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not add the instance')))
	}

	/**
	 * Remove an address from the access list, from the button in its row.
	 *
	 * @param {Event} event the click, which may be on anything in the list
	 */
	function onListClick(event) {
		if (!event.target.classList.contains('social-access-remove')) {
			return
		}
		const li = event.target.closest('li')
		post('/fediverse/remove', { address: li.dataset.address })
			.then((data) => renderAccessList(data.list))
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not remove the instance')))
	}

	/**
	 * Switch the access list between an allow list and a block list.
	 *
	 * @param {Event} event the change on the select
	 */
	function onAccessTypeChange(event) {
		post('/fediverse/access', { type: event.target.value })
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not change the access mode')))
	}

	/** Save the retention window, ignoring anything that is not a day count. */
	function onSaveRetention() {
		const days = parseInt(document.getElementById('social-retention-days').value, 10)
		if (isNaN(days) || days < 0) {
			return
		}
		post('/retention', { days })
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not change the retention period')))
	}

	/**
	 * The value of one field of the Server card.
	 *
	 * @param {string} name the id below social-server-
	 * @return {HTMLElement|null} the field
	 */
	function field(name) {
		return document.getElementById('social-server-' + name)
	}

	/**
	 * Write the whole Server card, or none of it.
	 *
	 * The endpoint validates every field and writes all or nothing, so what is
	 * reported here is what stands: a card that saved six of its eight fields
	 * would leave an administrator guessing which.
	 *
	 * @return {Promise<boolean>} whether it was taken
	 */
	async function saveServer() {
		const message = document.getElementById('social-server-message')
		const { ok, data } = await send(app + '/admin/server', {
			contactEmail: field('contact-email').value.trim(),
			extendedDescription: field('extended-description').value,
			maxSize: parseInt(field('max-size').value, 10),
			maxVideoSize: parseInt(field('max-video-size').value, 10),
			inboxThrottle: parseInt(field('inbox-throttle').value, 10),
			secureMode: field('secure-mode').checked,
			publishBlocks: field('publish-blocks').checked,
			allowSelfSigned: field('allow-self-signed').checked,
		})

		if (ok) {
			message.textContent = t('social', 'Saved')
			OC.Notification.showTemporary(t('social', 'The server settings were saved'))
			return true
		}

		// the endpoint names the field it would not take; it is the one thing
		// that tells the administrator what to change
		message.textContent = data.error ?? t('social', 'Could not save the server settings')
		OC.Notification.showTemporary(t('social', 'Could not save the server settings'))
		return false
	}

	/**
	 * Wire the page up. Every listener is delegated from a container that is
	 * there from the start, so a row appended later is handled too.
	 */
	function mount() {
		const moderation = document.getElementById('social-moderation')
		if (moderation !== null) {
			moderation.addEventListener('click', onToggleReport)
			moderation.addEventListener('click', onModerate)
		}

		const more = document.getElementById('social-reports-more')
		if (more !== null) {
			more.addEventListener('click', () => loadReports(false))
		}

		const resolved = document.getElementById('social-reports-resolved-section')
		if (resolved !== null) {
			resolved.addEventListener('toggle', onResolvedToggle)
			document.getElementById('social-reports-resolved-more')
				.addEventListener('click', () => loadReports(true))
		}

		const retentionSave = document.getElementById('social-retention-save')
		if (retentionSave) {
			retentionSave.addEventListener('click', onSaveRetention)
		}

		const accessType = document.getElementById('social-access-type')
		if (accessType) {
			accessType.addEventListener('change', onAccessTypeChange)
			document.getElementById('social-access-add').addEventListener('click', onAddAddress)
			document.getElementById('social-access-list').addEventListener('click', onListClick)
		}

		const serverSave = document.getElementById('social-server-save')
		if (serverSave) {
			serverSave.addEventListener('click', () => saveServer())
		}
	}

	document.addEventListener('DOMContentLoaded', mount)

	// What tests/js/adminSettings.test.js drives. This file is outside the
	// webpack build and is loaded as a classic script, so there is nothing to
	// import from it; the app namespace is where a classic script can leave
	// something for somebody else to call.
	OCA.Social = OCA.Social || {}
	OCA.Social.adminSettings = { loadReports, mount, pages, reportRow, saveServer }
})()
