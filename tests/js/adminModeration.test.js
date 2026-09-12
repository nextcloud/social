/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PAGE, mount, render, search, stateOf } from '../../src/adminModeration.js'

const SECTION = `
	<table class="social-reports"><tbody>
		<tr data-report-id="1" data-actor-id="https://remote.example/users/bob">
			<td class="social-report-statuses">
				<span class="social-report-status" data-stream-id="https://remote.example/notes/1">
					<a href="#">↗</a>
					<button class="social-status-remove">Take down</button>
				</span>
			</td>
		</tr>
	</tbody></table>
	<div id="social-accounts">
		<input type="search" id="social-accounts-query">
		<select id="social-accounts-origin"><option value="" selected></option><option value="local"></option></select>
		<select id="social-accounts-status"><option value="" selected></option><option value="silenced"></option></select>
		<button id="social-accounts-search"></button>
		<p id="social-accounts-empty" hidden></p>
		<table class="social-accounts" hidden><tbody id="social-accounts-list"></tbody></table>
		<button id="social-accounts-more" hidden></button>
	</div>
`

const account = (overrides = {}) => ({
	actor_id: 'https://remote.example/users/bob',
	handle: 'bob@remote.example',
	username: 'bob',
	domain: 'remote.example',
	local: false,
	level: '',
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

const rows = () => Array.from(document.querySelectorAll('#social-accounts-list tr'))
const cells = (row) => Array.from(row.querySelectorAll('td')).map((cell) => cell.textContent)
const buttons = (row) => Array.from(row.querySelectorAll('.social-account-moderate'))

describe('the account browser of the admin settings', () => {
	beforeEach(() => {
		document.body.innerHTML = SECTION
		OC.Notification.showTemporary = vi.fn()
		vi.restoreAllMocks()
	})

	it('lists every account the instance knows, not only the reported ones', async () => {
		answering({ accounts: [account(), account({ handle: 'alice', local: true, domain: null })], cursors: [9, 7] })

		await search()

		expect(rows()).toHaveLength(2)
		expect(cells(rows()[0])[0]).toBe('bob@remote.example')
		expect(cells(rows()[1])[1]).toBe('This instance')
	})

	it('asks the route for what the form says', async () => {
		const fetch = answering({ accounts: [], cursors: [] })
		document.getElementById('social-accounts-query').value = '  bob@remote.example '
		document.getElementById('social-accounts-origin').value = 'local'
		document.getElementById('social-accounts-status').value = 'silenced'

		await search()

		const url = fetch.mock.calls[0][0]
		expect(url).toContain('query=bob%40remote.example')
		expect(url).toContain('origin=local')
		expect(url).toContain('status=silenced')
	})

	it('says so rather than showing an empty table when nothing matches', async () => {
		answering({ accounts: [], cursors: [] })

		await search()

		expect(document.querySelector('table.social-accounts').hidden).toBe(true)
		expect(document.getElementById('social-accounts-empty').hidden).toBe(false)
	})

	it('shows what stands against each account', async () => {
		answering({ accounts: [account({ level: 'silence' })], cursors: [9] })

		await search()

		expect(cells(rows()[0])[2]).toBe('Silenced')
		// the decision that already stands is the one there is no point taking
		expect(buttons(rows()[0]).map((button) => button.disabled)).toEqual([true, false, false])
	})

	it('offers more only when the page was full', async () => {
		answering({ accounts: Array.from({ length: PAGE }, () => account()), cursors: [1] })
		await search()
		expect(document.getElementById('social-accounts-more').hidden).toBe(false)

		answering({ accounts: [account()], cursors: [1] })
		await search()
		expect(document.getElementById('social-accounts-more').hidden).toBe(true)
	})

	/** A redraw under the cursor loses the row somebody was about to act on. */
	it('appends the next page rather than redrawing the list', async () => {
		answering({ accounts: [account()], cursors: [9] })
		await search()

		const fetch = answering({ accounts: [account({ handle: 'carol@remote.example' })], cursors: [7] })
		await search(true)

		expect(rows()).toHaveLength(2)
		expect(fetch.mock.calls[0][0]).toContain('maxId=9')
	})

	it('starts a new search from the top', async () => {
		answering({ accounts: [account()], cursors: [9] })
		await search()

		const fetch = answering({ accounts: [account({ handle: 'carol@remote.example' })], cursors: [7] })
		await search(false)

		expect(rows()).toHaveLength(1)
		expect(fetch.mock.calls[0][0]).not.toContain('maxId')
	})

	it('keeps a remote handle out of the markup', async () => {
		answering({ accounts: [account({ handle: '<img src=x onerror=alert(1)>' })], cursors: [9] })

		await search()

		expect(rows()[0].querySelector('img')).toBeNull()
		expect(cells(rows()[0])[0]).toBe('<img src=x onerror=alert(1)>')
	})

	it('says so when the accounts cannot be read', async () => {
		answering({ error: 'nope' }, false)

		await search()

		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Could not read the accounts')
	})
})

describe('moderating from the account browser', () => {
	beforeEach(async () => {
		document.body.innerHTML = SECTION
		OC.Notification.showTemporary = vi.fn()
		vi.restoreAllMocks()
		answering({ accounts: [account()], cursors: [9] })
		mount()
		await vi.waitFor(() => expect(rows()).toHaveLength(1))
	})

	it('takes the decision against the account in the row', async () => {
		const fetch = answering({ actor_id: 'https://remote.example/users/bob', level: 'silence' })

		buttons(rows()[0])[0].click()
		await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
			actorId: 'https://remote.example/users/bob',
			level: 'silence',
			comment: '',
		})
		await vi.waitFor(() => expect(cells(rows()[0])[2]).toBe('Silenced'))
	})

	/** Suspending deletes, and lifting it later brings nothing back. */
	it('asks before suspending, and does nothing if the answer is no', async () => {
		const fetch = answering({})
		vi.spyOn(window, 'confirm').mockReturnValue(false)

		buttons(rows()[0])[1].click()

		expect(window.confirm).toHaveBeenCalled()
		expect(fetch).not.toHaveBeenCalled()
	})

	it('suspends when the answer is yes', async () => {
		const fetch = answering({ actor_id: 'https://remote.example/users/bob', level: 'suspend' })
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		buttons(rows()[0])[1].click()
		await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

		await vi.waitFor(() => expect(cells(rows()[0])[2]).toBe('Suspended'))
	})

	it('leaves the row alone when the decision could not be taken', async () => {
		answering({ error: 'nope' }, false)

		buttons(rows()[0])[0].click()
		await vi.waitFor(() =>
			expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Could not apply the decision'),
		)

		expect(cells(rows()[0])[2]).toBe('Nothing')
	})
})

describe('taking a reported post down', () => {
	beforeEach(() => {
		document.body.innerHTML = SECTION
		OC.Notification.showTemporary = vi.fn()
		vi.restoreAllMocks()
		answering({ accounts: [], cursors: [] })
		mount()
	})

	/**
	 * The route existed and had no button: the lightest thing a moderator can
	 * do about a report was the one thing the panel could not do.
	 */
	it('removes the post the report named', async () => {
		const fetch = answering({ stream_id: 'https://remote.example/notes/1' })
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		document.querySelector('.social-status-remove').click()
		await vi.waitFor(() =>
			expect(document.querySelector('.social-report-status').textContent).toBe('Taken down'),
		)

		expect(fetch.mock.calls[0][0]).toContain('/moderation/statuses/remove')
		expect(JSON.parse(fetch.mock.calls[0][1].body))
			.toEqual({ streamId: 'https://remote.example/notes/1' })
	})

	it('asks first, because the post goes for everybody', async () => {
		const fetch = answering({})
		vi.spyOn(window, 'confirm').mockReturnValue(false)

		document.querySelector('.social-status-remove').click()

		expect(fetch).not.toHaveBeenCalled()
	})

	it('leaves the post shown when it could not be taken down', async () => {
		answering({ error: 'nope' }, false)
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		document.querySelector('.social-status-remove').click()
		await vi.waitFor(() =>
			expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Could not take the post down'),
		)

		expect(document.querySelector('.social-report-status').textContent).not.toBe('Taken down')
	})
})

describe('what stands against an account', () => {
	it.each([
		['silence', 'Silenced'],
		['suspend', 'Suspended'],
		['', 'Nothing'],
	])('reads %s as %s', (level, expected) => {
		expect(stateOf(level)).toBe(expected)
	})
})

describe('mounting', () => {
	it('does nothing on a page that has no moderation panel', () => {
		document.body.innerHTML = '<div></div>'

		expect(mount()).toBe(false)
	})

	it('draws the first page as soon as the section is there', async () => {
		document.body.innerHTML = SECTION
		const fetch = answering({ accounts: [account()], cursors: [9] })

		mount()
		await vi.waitFor(() => expect(rows()).toHaveLength(1))

		expect(fetch).toHaveBeenCalled()
	})
})

describe('rendering', () => {
	it('is asked for by the search button and by Enter in the field', async () => {
		document.body.innerHTML = SECTION
		const fetch = answering({ accounts: [], cursors: [] })
		mount()
		await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))

		document.getElementById('social-accounts-search').click()
		await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

		document.getElementById('social-accounts-query')
			.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
		await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(3))
	})

	it('draws nothing for an empty answer', () => {
		document.body.innerHTML = SECTION
		render([])

		expect(rows()).toHaveLength(0)
	})
})
