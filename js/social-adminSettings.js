/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Hand-written companion of templates/settings/admin.php — not part of the
 * webpack build on purpose: the moderation panel stays dependency-free.
 */
(function() {
	'use strict'

	const base = OC.generateUrl('/apps/social/moderation')

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

	function onListClick(event) {
		if (!event.target.classList.contains('social-access-remove')) {
			return
		}
		const li = event.target.closest('li')
		post('/fediverse/remove', { address: li.dataset.address })
			.then((data) => renderAccessList(data.list))
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not remove the instance')))
	}

	function onAccessTypeChange(event) {
		post('/fediverse/access', { type: event.target.value })
			.catch(() => OC.Notification.showTemporary(t('social', 'Could not change the access mode')))
	}

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
