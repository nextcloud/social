/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import PhotoComments from '../../../src/components/PhotoComments.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

const bob = { id: '2', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: 'https://remote.example/bob.png' }
const dana = { id: '4', acct: 'dana', username: 'dana', display_name: 'Dana', avatar: 'https://cloud.example.org/dana.png' }

const post = {
	id: '101',
	account: bob,
	content: '<p>Sunset over the dunes</p>',
	visibility: 'public',
	created_at: '2026-09-01T10:00:00Z',
}

/**
 * @param {string} id the reply's id
 * @param {string} parent what it answers
 * @param {string} text what it says
 * @return {object} a status
 */
function reply(id, parent, text) {
	return { id, in_reply_to_id: parent, account: dana, content: `<p>${text}</p>`, created_at: '2026-09-01T11:00:00Z' }
}

const ComposerStub = {
	name: 'Composer',
	props: ['inReplyTo', 'emojiPickerContainer'],
	emits: ['posted'],
	template: '<div class="composer-stub" @click="$emit(\'posted\')" />',
}

function mountComments(item = post) {
	return mount(PhotoComments, {
		props: { item },
		attachTo: document.body,
		global: {
			stubs: {
				Composer: ComposerStub,
				RouterLink: RouterLinkStub,
				AccountHoverCard: { template: '<span><slot /></span>' },
				teleport: true,
			},
		},
	})
}

describe('PhotoComments', () => {
	afterEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	it('reads the thread from the post\'s context, not into the feed under it', async () => {
		axios.get.mockResolvedValue({ data: { ancestors: [], descendants: [reply('201', '101', 'Gorgeous')] } })
		const wrapper = mountComments()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/statuses/101/context')
		expect(wrapper.find('.photo-comments__thread').text()).toContain('Gorgeous')
		expect(wrapper.find('.photo-comments__thread').text()).toContain('Dana')
	})

	it('opens with the caption, as the first thing said about the picture', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments()
		await flushPromises()

		expect(wrapper.find('.photo-comments__comment--caption').text()).toContain('Sunset over the dunes')
	})

	it('sets an answer to a comment in under it', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [reply('201', '101', 'Gorgeous'), reply('202', '201', 'Agreed')] } })
		const wrapper = mountComments()
		await flushPromises()

		const comments = wrapper.findAll('.photo-comments__thread > li')
		expect(comments[0].classes()).not.toContain('photo-comments__comment--answer')
		expect(comments[1].classes()).toContain('photo-comments__comment--answer')
	})

	it('says so when there are no comments yet', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments()
		await flushPromises()

		expect(wrapper.text()).toContain('No comments yet')
	})

	it('says so when the thread could not be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mountComments()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Could not load comments')
	})

	it('is a dialog named by its heading, and takes the focus', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		mount(PhotoComments, {
			props: { item: post },
			attachTo: document.body,
			global: { stubs: { Composer: ComposerStub, AccountHoverCard: { template: '<span><slot /></span>' } } },
		})
		await flushPromises()

		// out of the timeline, where no row's transform can pin it
		const panel = document.body.querySelector(':scope > .photo-comments .photo-comments__panel')
		expect(panel.getAttribute('role')).toBe('dialog')
		expect(document.getElementById(panel.getAttribute('aria-labelledby')).textContent.trim()).toBe('Comments')
		expect(document.activeElement).toBe(panel)
	})

	it.each([
		['Escape', (wrapper) => wrapper.find('.photo-comments__panel').trigger('keydown', { key: 'Escape' })],
		['the close button', (wrapper) => wrapper.find('button[aria-label="Close"]').trigger('click')],
		['a tap on the feed behind the sheet', (wrapper) => wrapper.find('.photo-comments__scrim').trigger('click')],
	])('closes on %s', async (_name, close) => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments()
		await flushPromises()

		await close(wrapper)

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('answers in the composer every reply is written in, and reads the thread again once it is sent', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments()
		await flushPromises()
		const composer = wrapper.findComponent(ComposerStub)
		expect(composer.props('inReplyTo')).toEqual(post)

		axios.get.mockResolvedValue({ data: { descendants: [reply('203', '101', 'Mine')] } })
		await composer.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.photo-comments__thread').text()).toContain('Mine')
	})

	it('offers no reply field where the author takes no replies', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments({ ...post, interaction_policy: { reply: false } })
		await flushPromises()

		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
	})

	it('has the feed make room for it while it is open', async () => {
		axios.get.mockResolvedValue({ data: { descendants: [] } })
		const wrapper = mountComments()
		await flushPromises()
		expect(document.documentElement.classList.contains('photo-comments-open')).toBe(true)

		wrapper.unmount()

		expect(document.documentElement.classList.contains('photo-comments-open')).toBe(false)
	})

	// beside the column on a wide screen, a sheet from the bottom on a phone
	it('is a side panel by default and a bottom sheet on a phone', () => {
		const source = readFileSync(resolve(__dirname, '../../../src/components/PhotoComments.vue'), 'utf8')
		const phone = source.slice(source.indexOf('@include layout.below(layout.$phone)'))

		expect(source).toMatch(/&__panel \{[^}]*position: fixed;[^}]*inset-inline-end: 0;/s)
		expect(phone).toMatch(/\.photo-comments__panel \{[^}]*inset-block: auto 0;[^}]*max-height: 80vh;/s)
		expect(phone).toMatch(/\.photo-comments__scrim \{[^}]*display: block;/s)
	})
})
