/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import TagPeopleDialog from '../../../src/components/TagPeopleDialog.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ROUTE = '/index.php/apps/social/api/v1.1/compose/tag'

const NcDialog = {
	name: 'NcDialog',
	props: ['open', 'name', 'size'],
	emits: ['update:open'],
	template: '<div><slot /><slot name="actions" /></div>',
}

/**
 * @param {object[]} people who the photo names now
 * @return {object} the mounted dialog
 */
function mountDialog(people = []) {
	return mount(TagPeopleDialog, {
		props: { nid: '99', people },
		global: { stubs: { NcDialog } },
	})
}

const field = (wrapper) => wrapper.find('.tag-people__field input')
const saveButton = (wrapper) => wrapper.findAllComponents({ name: 'NcButton' })[1]

describe('naming the people in a photo', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: { tagged_people: [] } })
	})

	/**
	 * The field holds the whole list, so removing somebody is deleting their
	 * handle rather than hunting for a second control — and that is what the
	 * route expects: the list it is sent is the list the post ends up with.
	 */
	it('arrives pre-filled with whoever is named now', () => {
		const wrapper = mountDialog([{ acct: 'alice@cloud.example' }, { acct: 'bob@cloud.example' }])

		expect(field(wrapper).element.value).toBe('alice@cloud.example, bob@cloud.example')
	})

	it('starts empty for a photo that names nobody', () => {
		expect(field(mountDialog()).element.value).toBe('')
	})

	/** Everybody named is told about it, and it shows on their profile. */
	it('says what naming somebody will do', () => {
		expect(mountDialog().find('.tag-people__lede').text())
			.toContain('Everybody named is told')
	})

	it('sends the list the post should end up with', async () => {
		const wrapper = mountDialog()
		await field(wrapper).setValue('alice@cloud.example, bob@cloud.example')

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ROUTE, {
			status_id: '99',
			accounts: ['alice@cloud.example', 'bob@cloud.example'],
		})
	})

	/** Handles are typed, so they arrive with the slack of typing on them. */
	it('takes the spacing and the leading @ off what was typed', async () => {
		const wrapper = mountDialog()
		await field(wrapper).setValue('  @alice@cloud.example ,, @bob@cloud.example ,  ')

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1].accounts)
			.toEqual(['alice@cloud.example', 'bob@cloud.example'])
	})

	/** Clearing the field is how everybody is taken off the photo. */
	it('sends an empty list when the field has been cleared', async () => {
		const wrapper = mountDialog([{ acct: 'alice@cloud.example' }])
		await field(wrapper).setValue('')

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1].accounts).toEqual([])
	})

	it('hands the post its new list and closes', async () => {
		const tagged = [{ acct: 'alice@cloud.example' }]
		axios.post.mockResolvedValue({ data: { tagged_people: tagged } })
		const wrapper = mountDialog()

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.emitted('tagged')).toEqual([[tagged]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	describe('when the save does not take', () => {
		it('stays open, and says what the server said', async () => {
			axios.post.mockRejectedValue({ response: { data: { error: 'alice@cloud.example is not a real account' } } })
			const wrapper = mountDialog()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.tag-people__error').text())
				.toBe('alice@cloud.example is not a real account')
			expect(wrapper.emitted('close')).toBeUndefined()
		})

		it('says something even when the server said nothing', async () => {
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountDialog()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.tag-people__error').text()).toBe('Could not save that')
		})

		/** A failure the reader has already been told about, and moved past. */
		it('clears the last failure when it is tried again', async () => {
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountDialog()
			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			axios.post.mockResolvedValue({ data: { tagged_people: [] } })
			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.tag-people__error').exists()).toBe(false)
		})

		/** The error is what the reader must act on before anything else. */
		it('interrupts to say so', async () => {
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountDialog()

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.tag-people__error').attributes('role')).toBe('alert')
		})
	})

	it('cannot be typed in or saved twice while it is saving', async () => {
		axios.post.mockReturnValue(new Promise(() => {}))
		const wrapper = mountDialog()

		saveButton(wrapper).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(saveButton(wrapper).props('disabled')).toBe(true)
		expect(wrapper.findAllComponents({ name: 'NcButton' })[0].props('disabled')).toBe(true)
		expect(wrapper.findComponent({ name: 'NcTextField' }).props('disabled')).toBe(true)
	})

	it('closes without saving when it is cancelled', async () => {
		const wrapper = mountDialog()

		wrapper.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
