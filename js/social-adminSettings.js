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

	const base = OC.generateUrl('/apps/social/moderation')

	/**
	 * POST a JSON body to a moderation route and return the decoded answer.
	 *
	 * @param {string} path route below /apps/social/moderation
	 * @param {object} body what to send
	 * @return {Promise<object>} the decoded response
	 */
	async function post(path, body) {
		const response = await fetch(base + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify(body),
		})
		if (!response.ok) {
			throw new Error('request failed: ' + response.status)
		}
		return response.json()
	}

	/**
	 * Resolve a report, or reopen one, from the button in its row.
	 *
	 * @param {Event} event the click
	 */
	function onToggleReport(event) {
		const button = event.target
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

		if (level === 'suspend' && !window.confirm(t('social',
			'Suspending deletes every post this account has here and refuses anything it sends afterwards. '
			+ 'Lifting the suspension later will not bring the posts back. Continue?'))) {
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
			const remove = document.createElement('button')
			remove.type = 'button'
			remove.className = 'social-access-remove'
			remove.textContent = t('social', 'Remove')
			li.appendChild(remove)
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

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('.social-report-toggle')
			.forEach((button) => button.addEventListener('click', onToggleReport))

		const reports = document.querySelector('.social-reports')
		if (reports) {
			reports.addEventListener('click', onModerate)
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
	})
})()
