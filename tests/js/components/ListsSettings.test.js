/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import ListsSettings from '../../../src/components/ListsSettings.vue'
import eventBus, { LISTS_CHANGED } from '../../../src/services/eventBus.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

// NcActions only renders its entries in a popover once it is opened; these
// stand-ins put them inline, where a row's own buttons already are
const NcActionsStub = { name: 'NcActions', template: '<div class="list-menu"><slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="list-menu__item" @click="$emit(\'click\')"><slot /></button>',
}

const list = (id, title, extra = {}) => ({ id, title, replies_policy: 'list', ...extra })
const bob = {
	id: 'https://remote.example/users/bob',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
}

/** @param {object[]} lists what `GET /lists` answers with */
async function mountLists(lists = []) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/lists')) {
			return Promise.resolve({ data: lists })
		}

		return Promise.resolve({ data: [] })
	})
	const pinia = createPinia()
	setActivePinia(pinia)
	const wrapper = mount(ListsSettings, {
		global: {
			plugins: [pinia],
			// the avatar opens an account card of its own, which is a page's
			// worth of component for a row in a settings list
			stubs: {
				RouterLink: RouterLinkStub,
				NcDialog: true,
				ActorAvatar: true,
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
			},
		},
	})
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.lists-settings__item')
const rowFor = (wrapper, title) => rows(wrapper).find((row) => row.text().includes(title))
const actionLabels = (row) => row.findAll('button').map((button) => button.text()).filter((text) => text !== '')

describe('ListsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for the lists as it opens', async () => {
		await mountLists()

		expect(axios.get).toHaveBeenCalledWith(`${API}/lists`)
	})

	it('says what a list is when there are none', async () => {
		const wrapper = await mountLists()

		expect(wrapper.text()).toContain('You have no lists yet.')
	})

	it('lists the group ones first, since nobody made those', async () => {
		const wrapper = await mountLists([
			list('1', 'Book club'),
			list('2', 'Design', { nextcloud_group: 'design' }),
		])

		expect(rows(wrapper).map((row) => row.find('.lists-settings__title').text()))
			.toEqual(['Design', 'Book club'])
	})

	it('creates a list under the name it was given', async () => {
		const wrapper = await mountLists()
		axios.post.mockResolvedValue({ data: list('3', 'Book club') })

		await wrapper.find('.lists-settings__create-title input').setValue('Book club')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/lists`, { title: 'Book club' })
		expect(rowFor(wrapper, 'Book club')).toBeDefined()
	})

	it('will not create a list with no name', async () => {
		const wrapper = await mountLists()

		await wrapper.find('.lists-settings__create-title input').setValue('   ')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('renames a list, and carries its reply policy so nothing is reset', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.put.mockResolvedValue({ data: list('1', 'Reading group') })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Rename').trigger('click')
		await wrapper.find('.lists-settings__rename input').setValue('Reading group')
		await wrapper.find('.lists-settings__rename').trigger('submit')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(`${API}/lists/1`, {
			title: 'Reading group',
			replies_policy: 'list',
		})
	})

	it('deletes a list once the confirmation has agreed', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.delete.mockResolvedValue({ data: {} })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Delete').trigger('click')
		// the dialog is stubbed; its button is a prop rather than markup
		const confirm = wrapper.findComponent({ name: 'NcDialog' }).props('buttons')
			.find((button) => button.label === 'Delete')
		await confirm.callback()
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/lists/1`)
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('asks for the members of a list when it is opened, and all of them', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [bob] })

		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/lists/1/accounts`, { params: { limit: 0 } })
		expect(wrapper.find('.lists-settings__member-acct').text()).toBe('@bob@remote.example')
	})

	it('adds somebody the search found, by their actor id', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [] })
		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		// the search answers as the global account search does: actors, whose
		// `id` is the URL the list route wants
		axios.get.mockResolvedValue({ data: { result: { accounts: [{ id: bob.id, account: bob.acct, name: 'Bob' }] } } })
		await wrapper.findAll('input').at(-1).setValue('bob')
		await vi.waitUntil(() => wrapper.find('.lists-settings__result').exists(), { timeout: 5000 })

		axios.post.mockResolvedValue({ data: {} })
		axios.get.mockResolvedValue({ data: [bob] })
		await wrapper.find('.lists-settings__result').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/lists/1/accounts`, { account_ids: [bob.id] })
	})

	it('takes somebody out of a list with the ids on the address', async () => {
		const wrapper = await mountLists([list('1', 'Book club')])
		axios.get.mockResolvedValue({ data: [bob] })
		await rowFor(wrapper, 'Book club').findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		axios.delete.mockResolvedValue({ data: {} })
		await wrapper.find('.lists-settings__member button').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/lists/1/accounts`, {
			params: { account_ids: [bob.id] },
		})
		expect(wrapper.find('.lists-settings__member').exists()).toBe(false)
	})

	/** The group decides all three, and the server answers 422 to anybody else. */
	it('offers no rename, no delete and no adding for a list a Nextcloud group makes', async () => {
		const wrapper = await mountLists([list('2', 'Design', { nextcloud_group: 'design' })])
		const row = rowFor(wrapper, 'Design')

		expect(row.text()).toContain('Nextcloud group')
		expect(actionLabels(row)).toEqual(['Members'])

		axios.get.mockResolvedValue({ data: [bob] })
		await row.findAll('button').find((b) => b.text() === 'Members').trigger('click')
		await flushPromises()

		expect(wrapper.find('.lists-settings__add').exists()).toBe(false)
		expect(wrapper.find('.lists-settings__member button').exists()).toBe(false)
	})

	it('tells the sidebar whenever the lists have changed', async () => {
		const heard = vi.fn()
		eventBus.on(LISTS_CHANGED, heard)
		const wrapper = await mountLists()
		axios.post.mockResolvedValue({ data: list('3', 'Book club') })

		await wrapper.find('.lists-settings__create-title input').setValue('Book club')
		await wrapper.find('.lists-settings__create').trigger('submit')
		await flushPromises()

		expect(heard).toHaveBeenCalled()
		eventBus.off(LISTS_CHANGED, heard)
	})
})
