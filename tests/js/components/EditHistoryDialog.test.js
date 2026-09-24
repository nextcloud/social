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

const NcButtonStub = {
	props: ['variant'],
	template: '<button v-bind="$attrs"><slot /></button>',
}

function mountDialog(props = {}) {
	return mount(EditHistoryDialog, {
		props: { nid: 101, ...props },
		global: { stubs: { NcDialog: NcDialogStub, NcButton: NcButtonStub } },
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

	it('accepts the Nextcloud wrapped API response as well as the raw Mastodon array', async () => {
		get.mockResolvedValue({ data: { result: [version('<p>wrapped version</p>')] } })

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.find('.history__version').text()).toContain('wrapped version')
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
		expect(wrapper.get('[role="alert"] button').text()).toBe('Try again')
	})

	it('lets the reader retry a failed request and clears the error after success', async () => {
		get.mockRejectedValueOnce(new Error('temporary failure'))
		const wrapper = mountDialog()
		await flushPromises()
		get.mockResolvedValueOnce({ data: [version('<p>recovered version</p>')] })

		await wrapper.get('[role="alert"] button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.text()).toContain('recovered version')
		expect(wrapper.text()).not.toContain('Could not load this post’s edit history.')
	})

	it('reloads when the displayed post changes and ignores the earlier response', async () => {
		let resolveFirst
		get.mockImplementationOnce(() => new Promise((resolve) => {
			resolveFirst = resolve
		}))
		const wrapper = mountDialog()
		get.mockResolvedValueOnce({ data: [version('<p>second post</p>')] })
		await wrapper.setProps({ nid: '202' })
		await flushPromises()
		resolveFirst({ data: [version('<p>first post</p>')] })
		await flushPromises()

		expect(get).toHaveBeenNthCalledWith(2, expect.stringContaining('/api/v1/statuses/202/history'))
		expect(wrapper.find('.history__content').text()).toBe('second post')
	})

	it('shows malformed API data as a load error instead of claiming no edits exist', async () => {
		get.mockResolvedValue({ data: { unexpected: true } })

		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not load this post’s edit history.')
		expect(wrapper.text()).not.toContain('This post has not been edited.')
	})

	it('does not say a visibly edited post was never edited when no revisions exist', async () => {
		const wrapper = mountDialog({ editedAt: '2026-09-01T10:00:00Z' })
		await flushPromises()

		expect(wrapper.text()).toContain('This post is marked as edited, but its revision history is unavailable.')
		expect(wrapper.text()).not.toContain('This post has not been edited.')
	})
})
