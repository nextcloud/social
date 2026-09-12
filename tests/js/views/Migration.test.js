/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

import Migration from '../../../src/views/Migration.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
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
		await wrapper.find('.migration__card button').trigger('click')
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
		await wrapper.find('.migration__card button').trigger('click')
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
	it('sends a whole-account move to an administrator rather than offering it', () => {
		const text = mountPage().text()

		expect(text).toContain('social:account:alias')
		expect(text).toContain('social:account:move')
	})
})
