/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '../../../src/services/toast.js'

import Migration from '../../../src/components/MigrationSettings.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

function mountPage() {
	return mount(Migration, { attachTo: document.body })
}

/** A file input cannot be filled by hand, so the change is dispatched with one. */
async function choose(wrapper, ref, name = 'social-alice.zip') {
	const input = wrapper.vm.$refs[ref]
	const file = new File(['zip bytes'], name)
	Object.defineProperty(input, 'files', { value: [file], configurable: true })
	await input.dispatchEvent(new Event('change'))
	await flushPromises()

	return file
}

describe('Migration', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('says what the page is for', () => {
		const wrapper = mountPage()

		expect(wrapper.text()).toContain('Export your data')
		expect(wrapper.text()).toContain('Import an archive')
		expect(wrapper.text()).toContain('Coming from another network')
	})

	// export

	it('asks the server for the archive and saves it under the name it was given', async () => {
		const blob = new Blob(['zip bytes'])
		axios.get.mockResolvedValue({
			data: blob,
			headers: { 'content-disposition': 'attachment; filename="social-alice-2026-09-13.zip"' },
		})
		// the click is stubbed rather than `createElement`: the component is
		// rendered with the same function, so a mocked one would hand Vue the
		// anchor as well and the mount would fall apart
		let savedAs = ''
		const click = vi.fn(function saved() {
			savedAs = this.getAttribute('download')
		})
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(click)
		URL.createObjectURL = vi.fn(() => 'blob:archive')
		URL.revokeObjectURL = vi.fn()

		const wrapper = mountPage()
		await wrapper.find('.migration__card:not(.migration__card--lead) button').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/migration/export`, { responseType: 'blob' })
		expect(click).toHaveBeenCalled()
		expect(savedAs).toBe('social-alice-2026-09-13.zip')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:archive')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('says so when the export fails rather than downloading nothing', async () => {
		axios.get.mockRejectedValue(new Error('no room on the disk'))

		const wrapper = mountPage()
		await wrapper.find('.migration__card:not(.migration__card--lead) button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not export your data')
	})

	// import

	it('uploads the chosen archive and shows what the import did', async () => {
		axios.post.mockResolvedValue({ data: { imported: true, log: ['Importing the Social profile…'] } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/import`, expect.any(FormData))
		expect(wrapper.find('.migration__log').text()).toContain('Importing the Social profile…')
		expect(showSuccess).toHaveBeenCalled()
	})

	/** The server names what was wrong — the wrong zip, most likely — so say that. */
	it('shows the reason the server gave for refusing an archive', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'this archive holds no Social data' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(showError).toHaveBeenCalledWith('this archive holds no Social data')
	})

	/** Choosing the same file twice fires no change unless the input is cleared. */
	it('clears the file input so the same archive can be chosen again', async () => {
		axios.post.mockResolvedValue({ data: { imported: true, log: [] } })

		const wrapper = mountPage()
		await choose(wrapper, 'archive')

		expect(wrapper.vm.$refs.archive.value).toBe('')
	})

	// follows from another network

	it('uploads a follows CSV and counts what came of it', async () => {
		axios.post.mockResolvedValue({ data: { followed: 12, skipped: 1, failed: { 'gone@dead.example': 'unknown host' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'following_accounts.csv')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/follows`, expect.any(FormData))
		expect(wrapper.find('.migration__result').text()).toContain('12')
		expect(wrapper.find('.migration__result').text()).toContain('1')
	})

	it('takes Pixelfed\'s JSON export too, since that is the only follows file Pixelfed writes', async () => {
		axios.post.mockResolvedValue({ data: { followed: 3, skipped: 0, failed: {} } })

		const wrapper = mountPage()
		expect(wrapper.find('input[ref="follows"], input[accept*="json"]').exists()).toBe(true)
		await choose(wrapper, 'follows', 'pixelfed-following.json')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/follows`, expect.any(FormData))
		expect(wrapper.find('.migration__result').text()).toContain('3')
		expect(wrapper.text()).toContain('pixelfed-following.json')
	})

	// the posts themselves, which is what moving has never carried

	it('uploads an export, says it may fetch the pictures, and counts what came of it', async () => {
		axios.post.mockResolvedValue({ data: { imported: 240, media: 96, already: 0, skipped: 12, failed: 1, capped: false } })

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'outbox.json')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/posts`, expect.any(FormData))
		const form = axios.post.mock.calls[0][1]
		expect(form.get('fetch_media')).toBe('1')
		const result = wrapper.findAll('.migration__result').at(-1).text()
		expect(result).toContain('240')
		expect(result).toContain('96')
		expect(result).toContain('12')
	})

	it('sends the fetch off when the reader turns it off', async () => {
		axios.post.mockResolvedValue({ data: { imported: 3, media: 0, already: 0, skipped: 0, failed: 0 } })

		const wrapper = mountPage()
		await wrapper.find('.migration__media-switch input').setValue(false)
		await choose(wrapper, 'posts', 'pixelfed-statuses.json')

		expect(axios.post.mock.calls[0][1].get('fetch_media')).toBe('0')
	})

	it('says when the run stopped at its limit, so the reader knows to go again', async () => {
		axios.post.mockResolvedValue({ data: { imported: 2000, media: 500, already: 0, skipped: 0, failed: 0, capped: true } })

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'outbox.json')

		expect(wrapper.findAll('.migration__result').at(-1).text()).toContain('again')
	})

	it('says so when the export cannot be read', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'this file is not the JSON an export is written in' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'posts', 'notes.txt')

		expect(showError).toHaveBeenCalledWith('this file is not the JSON an export is written in')
	})

	it('says so when the follows cannot be read', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not a CSV' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'follows', 'notes.txt')

		expect(showError).toHaveBeenCalledWith('that is not a CSV')
	})

	/**
	 * What a person actually needs to know: where the file is in each of the
	 * other apps, and that the ones which do not federate cannot be imported.
	 */
	it('says where to find the file in the other networks', () => {
		const text = mountPage().text()

		for (const network of ['Mastodon', 'Pixelfed', 'GoToSocial', 'Bluesky']) {
			expect(text).toContain(network)
		}
	})

	/** A move federates and cannot be undone, so it is not a button here. */
	/**
	 * Naming the old account federates nothing and is the person's own to do;
	 * moving the followers cannot be taken back, and stays an administrator's.
	 */
	it('offers the alias but sends the move to an administrator', () => {
		const text = mountPage().text()

		expect(text).toContain('Accounts you also answer to')
		expect(text).toContain('social:account:move')
		expect(text).not.toContain('social:account:alias')
	})

	it('reads the aliases when the page opens', async () => {
		axios.get.mockResolvedValue({ data: { aliases: ['https://pixelfed.social/users/you'] } })
		const wrapper = mountPage()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/migration/aliases')
		expect(wrapper.text()).toContain('https://pixelfed.social/users/you')
	})

	it('adds one and shows the list the server answers with', async () => {
		const wrapper = mountPage()
		await flushPromises()
		axios.post.mockResolvedValue({ data: { aliases: ['https://old.example/users/me'] } })

		wrapper.vm.aliasInput = 'https://old.example/users/me'
		await wrapper.vm.addAlias()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/social/api/v1/migration/aliases',
			{ alias: 'https://old.example/users/me' },
		)
		expect(wrapper.text()).toContain('https://old.example/users/me')
		// the box is cleared, so a second press cannot add the same one twice
		expect(wrapper.vm.aliasInput).toBe('')
	})

	// the other three lists, in and out

	it('reads a blocks CSV through its own route and reports what it came to', async () => {
		axios.post.mockResolvedValue({ data: { blocked: 3, skipped: 1, failed: { 'dave@x.example': 'gone' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'blocks', 'blocked_accounts.csv')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/blocks`, expect.any(FormData))
		expect(wrapper.text()).toContain('3 applied, 1 skipped, 1 could not be reached')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('reads a mutes CSV through the mutes route', async () => {
		axios.post.mockResolvedValue({ data: { muted: 2, skipped: 0, failed: {} } })

		const wrapper = mountPage()
		await choose(wrapper, 'mutes', 'muted_accounts.csv')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/mutes`, expect.any(FormData))
		expect(wrapper.text()).toContain('2 applied, 0 skipped, 0 could not be reached')
	})

	/**
	 * A list here can only hold accounts the owner follows, so the count of
	 * the ones left out has to say why — otherwise a half-filled list looks
	 * like a failure nobody can act on.
	 */
	it('says why the accounts left out of a list were left out', async () => {
		axios.post.mockResolvedValue({ data: { lists: 2, added: 5, skipped: 4, failed: {} } })

		const wrapper = mountPage()
		await choose(wrapper, 'lists', 'lists.csv')

		expect(axios.post).toHaveBeenCalledWith(`${API}/migration/lists`, expect.any(FormData))
		expect(wrapper.text()).toContain('2 lists made, 5 accounts added, 4 skipped because you do not follow them')
	})

	it('shows the server\'s reason when one of those files is refused', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not a CSV' } } })

		const wrapper = mountPage()
		await choose(wrapper, 'blocks', 'blocked_accounts.csv')

		expect(showError).toHaveBeenCalledWith('that is not a CSV')
	})

	it('downloads one list at a time under the name the server gave it', async () => {
		axios.get.mockResolvedValue({
			data: new Blob(['carol@remote.example\n']),
			headers: { 'content-disposition': 'attachment; filename="blocked_accounts.csv"' },
		})
		let savedAs = ''
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function saved() {
			savedAs = this.getAttribute('download')
		})
		URL.createObjectURL = vi.fn(() => 'blob:csv')
		URL.revokeObjectURL = vi.fn()

		const wrapper = mountPage()
		await flushPromises()
		const button = wrapper.findAll('.migration__csv-buttons button')
			.find((one) => one.text() === 'Blocks')
		await button.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/migration/export/blocks`, { responseType: 'blob' })
		expect(savedAs).toBe('blocked_accounts.csv')
	})

	/** An address that is not an account's own is refused, and the server says why. */
	it('shows the reason the server gave for refusing an address', async () => {
		const wrapper = mountPage()
		await flushPromises()
		axios.post.mockRejectedValue({ response: { data: { error: 'that is not an actor id' } } })

		wrapper.vm.aliasInput = 'pixelfed.social'
		await wrapper.vm.addAlias()
		await flushPromises()

		const { showError } = await import('../../../src/services/toast.js')
		expect(showError).toHaveBeenCalledWith('that is not an actor id')
	})
})
