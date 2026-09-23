/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EditHistoryDialog from '../../../src/components/EditHistoryDialog.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['name', 'open', 'size'],
	template: '<div class="dialog-stub"><slot /></div>',
}

function mountDialog(props = {}) {
	return mount(EditHistoryDialog, {
		props: { nid: 101, ...props },
		global: { stubs: { NcDialog: NcDialogStub } },
	})
}

function version(content, overrides = {}) {
	return {
		content,
		created_at: '2026-09-01T10:00:00Z',
		spoiler_text: '',
		media_attachments: [],
		...overrides,
	}
}

describe('the edit history', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
	})

	it('asks for the versions of the post it was opened on', async () => {
		mountDialog()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/statuses/101/history'))
	})

	it('draws one entry per version, oldest first', async () => {
		get.mockResolvedValue({
			data: [version('<p>first go</p>'), version('<p>second go</p>')],
		})

		const wrapper = mountDialog()
		await flushPromises()

		const entries = wrapper.findAll('.history__version')
		expect(entries).toHaveLength(2)
		expect(entries[0].text()).toContain('As first posted')
		expect(entries[0].text()).toContain('first go')
		expect(entries[1].text()).toContain('Edited')
		expect(entries[1].text()).toContain('second go')
	})

	/**
	 * Each version is the post as it stood; rendering it as text would show
	 * the reader markup.
	 */
	it('renders the content as the HTML it is', async () => {
		get.mockResolvedValue({ data: [version('<p>a <strong>bold</strong> claim</p>')] })

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('.history__content strong').text()).toBe('bold')
	})

	it('puts a revision through the client sanitiser, as every other remote-HTML sink is', async () => {
		// safe only for as long as no remote revision is ever stored, which is
		// not a property of this dialog
		get.mockResolvedValue({
			data: [version('<p>hi<script>alert(1)</script><a href="javascript:alert(2)" onclick="x()">link</a></p>')],
		})

		const wrapper = mountDialog()
		await flushPromises()

		const content = wrapper.find('.history__content')
		expect(content.html()).not.toContain('<script')
		expect(content.html()).not.toContain('onclick')
		expect(content.find('a').attributes('href')).toBeUndefined()
		expect(content.text()).toContain('hi')
	})

	it('renders a version with no content at all as nothing', async () => {
		get.mockResolvedValue({ data: [version(undefined)] })

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('.history__content').text()).toBe('')
	})

	it('shows the content warning a version carried', async () => {
		get.mockResolvedValue({ data: [version('<p>hi</p>', { spoiler_text: 'spoilers' })] })

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('.history__warning').text()).toBe('spoilers')
	})

	it('names the pictures a version carried', async () => {
		get.mockResolvedValue({
			data: [version('<p>hi</p>', {
				media_attachments: [{ id: 'm1', description: 'a stairwell' }],
			})],
		})

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('.history__media').text()).toContain('a stairwell')
	})

	it('says so when there is nothing to show', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.text()).toContain('This post has not been edited.')
	})

	/** A history that would not load is not worth an error over the post. */
	it('explains that the history could not be loaded when the server refuses', async () => {
		get.mockRejectedValue(new Error('nope'))

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not load this post’s edit history.')
	})

	it('does not say a visibly edited post was never edited when no revisions exist', async () => {
		const wrapper = mountDialog({ editedAt: '2026-09-01T10:00:00Z' })
		await flushPromises()

		expect(wrapper.text()).toContain('This post is marked as edited, but its revision history is unavailable.')
		expect(wrapper.text()).not.toContain('This post has not been edited.')
	})
})
