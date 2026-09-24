/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import FediverseSearch from '../../../src/components/FediverseSearch.vue'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

// NcActions only renders its entries inside a popover once opened; these
// stand-ins render them inline so the menu content can be asserted
const NcActionsStub = { name: 'NcActions', props: ['menuName'], template: '<div class="finder__menu">{{ menuName }}<slot /></div>' }
const NcActionButtonStub = {
	name: 'NcActionButton',
	emits: ['click'],
	template: '<button class="finder__menu-item" @click="$emit(\'click\')"><slot /></button>',
}

const SOURCES = [
	{ host: 'cloud.example', kind: 'local', label: 'cloud.example' },
	{ host: 'mastodon.social', kind: 'mastodon', label: 'mastodon.social' },
]

/**
 * @param {object} fields what to override
 * @return {object} one result as the server hands it over
 */
function person(fields = {}) {
	return {
		acct: 'jens@chaos.social',
		username: 'jens',
		host: 'chaos.social',
		display_name: 'Jens',
		note: 'Photographer',
		avatar: 'https://chaos.social/jens.png',
		url: 'https://chaos.social/@jens',
		followers_count: 40,
		statuses_count: 12,
		bot: false,
		known: false,
		source: 'mastodon.social',
		source_kind: 'mastodon',
		...fields,
	}
}

/**
 * @param {object[]} accounts what the search answers with
 * @param {object[]} reports what each source said about itself
 */
function serve(accounts, reports = [{ host: 'mastodon.social', label: 'mastodon.social', status: 'ok', count: 1 }]) {
	axios.get.mockImplementation(async (url) => {
		if (url.endsWith('/directories')) {
			return { data: SOURCES }
		}

		return { data: { accounts, sources: reports } }
	})
}

function mountSearch() {
	const pinia = createPinia()
	setActivePinia(pinia)

	return mount(FediverseSearch, {
		global: {
			plugins: [pinia],
			stubs: {
				RouterLink: RouterLinkStub,
				NcActions: NcActionsStub,
				NcActionButton: NcActionButtonStub,
			},
		},
	})
}

/**
 * Types into the box and lets the debounce run out.
 *
 * @param {object} wrapper the mounted component
 * @param {string} text what the reader typed
 */
async function type(wrapper, text) {
	await wrapper.find('input').setValue(text)
	await vi.runAllTimersAsync()
	await flushPromises()
}

const handles = (wrapper) => wrapper.findAll('.person__handle').map((el) => el.text())

describe('FediverseSearch', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		axios.get.mockReset()
		axios.put.mockReset()
		serve([])
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.clearAllMocks()
	})

	/**
	 * One control rather than a button per directory: eight of them wrap onto
	 * two lines above an empty result list, and the question they answer has
	 * one answer at a time.
	 */
	it('says where it is looking, and how many places that is', async () => {
		const wrapper = mountSearch()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/directories`)
		expect(wrapper.find('.finder__where').text()).toContain('Everywhere')
		expect(wrapper.find('.finder__where').text()).toContain('2 directories')
	})

	it('offers each directory on its own in the menu', async () => {
		const wrapper = mountSearch()
		await flushPromises()

		const options = wrapper.findAllComponents({ name: 'NcActionButton' })
			.map((button) => button.text())
		expect(options).toContain('mastodon.social')
		expect(options).toContain('Everywhere')
	})

	it('asks the directories for what was typed', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()

		await type(wrapper, 'jens')

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/directories/search`, {
			params: { q: 'jens', source: '' },
		})
		expect(handles(wrapper)).toEqual(['@jens@chaos.social'])
	})

	/** One letter matches everybody and tells the reader nothing. */
	it('does not send a single letter to four servers', async () => {
		const wrapper = mountSearch()
		await flushPromises()
		axios.get.mockClear()

		await type(wrapper, 'j')

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('waits for the typing to stop rather than asking per keystroke', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		axios.get.mockClear()

		await wrapper.find('input').setValue('je')
		await wrapper.find('input').setValue('jen')
		await wrapper.find('input').setValue('jens')
		await vi.runAllTimersAsync()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('asks one directory on its own when the reader picks one', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		await wrapper.findAllComponents({ name: 'NcActionButton' })
			.find((button) => button.text() === 'mastodon.social')
			.vm.$emit('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/directories/search`, {
			params: { q: 'jens', source: 'mastodon.social' },
		})
	})

	/**
	 * A server that did not answer is not a server with nobody on it, and a
	 * reader about to try a different spelling is entitled to know which.
	 */
	it('says which directories did not answer', async () => {
		serve([person()], [
			{ host: 'mastodon.social', label: 'mastodon.social', status: 'ok', count: 1 },
			{ host: 'misskey.io', label: 'misskey.io', status: 'failed', count: 0 },
		])
		const wrapper = mountSearch()
		await flushPromises()

		await type(wrapper, 'jens')

		expect(wrapper.find('.finder__quiet').text()).toContain('misskey.io')
		expect(wrapper.find('.finder__quiet').text()).not.toContain('mastodon.social')
	})

	it('says nothing about quiet servers when they all answered', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()

		await type(wrapper, 'jens')

		expect(wrapper.find('.finder__quiet').exists()).toBe(false)
	})

	/**
	 * Somebody this instance already holds has a profile here; somebody it has
	 * never met has one on their own server.
	 */
	it('opens a known account here and a stranger on their own server', async () => {
		serve([person({ known: true }), person({ acct: 'far@other.example', known: false, url: 'https://other.example/@far' })])
		const wrapper = mountSearch()
		await flushPromises()

		await type(wrapper, 'jens')

		const rows = wrapper.findAll('.person__link')
		expect(wrapper.findComponent(RouterLinkStub).props('to'))
			.toEqual({ name: 'profile', params: { account: 'jens@chaos.social' } })
		expect(rows[1].attributes('href')).toBe('https://other.example/@far')
		expect(rows[1].attributes('rel')).toBe('noopener noreferrer')
	})

	/** The handle is the only durable reference to somebody on another server. */
	it('follows by handle', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		const store = useAccountStore()
		const followed = vi.spyOn(store, 'followAccount').mockResolvedValue({ data: {} })

		await wrapper.find('.person__follow').trigger('click')
		await flushPromises()

		expect(followed).toHaveBeenCalledWith({ accountToFollow: 'jens@chaos.social' })
		expect(wrapper.find('.person__follow').text()).toBe('Following')
	})

	/** A refused follow must not leave the row claiming it worked. */
	it('leaves the button alone when the follow was refused', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		const store = useAccountStore()
		vi.spyOn(store, 'followAccount').mockResolvedValue(undefined)

		await wrapper.find('.person__follow').trigger('click')
		await flushPromises()

		expect(wrapper.find('.person__follow').text()).toBe('Follow')
	})

	it('tells an empty answer apart from a question nobody has asked yet', async () => {
		const wrapper = mountSearch()
		await flushPromises()

		expect(wrapper.text()).not.toContain('Nobody by that name')

		await type(wrapper, 'nobody')

		expect(wrapper.text()).toContain('Nobody by that name')
	})

	/**
	 * A directory result is somebody this server has never met: the picture it
	 * carries is on that account's own server, and the component that resolves
	 * a cached avatar has nothing to resolve. Asking it anyway drew a question
	 * mark for every stranger.
	 */
	it('shows the picture the directory gave for a stranger', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		expect(wrapper.find('img.person__avatar').attributes('src'))
			.toBe('https://chaos.social/jens.png')
	})

	/** And a picture that will not load leaves a letter, not a broken frame. */
	it('falls back to an initial when that picture will not load', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		await wrapper.find('img.person__avatar').trigger('error')

		expect(wrapper.find('img.person__avatar').exists()).toBe(false)
		expect(wrapper.find('.person__avatar--blank').text()).toBe('J')
	})

	/**
	 * The question is the words *and* the directory they are asked of, and the
	 * failure path was not guarded at all — so an abandoned search could empty
	 * the results of the one on screen.
	 */
	describe('answers that arrive after the reader has moved on', () => {
		it('does not let an older directory answer for the one chosen now', async () => {
			const wrapper = mountSearch()

			let answerEverywhere
			axios.get.mockImplementationOnce(() => new Promise((resolve) => {
				answerEverywhere = () => resolve({
					data: { accounts: [{ id: '1', acct: 'from@everywhere', known: false, url: 'https://e/1' }], sources: [] },
				})
			}))
			wrapper.vm.query = 'alice'
			wrapper.vm.search(true)
			await flushPromises()

			// same words, another directory
			axios.get.mockResolvedValueOnce({
				data: { accounts: [{ id: '2', acct: 'from@chosen', known: false, url: 'https://c/2' }], sources: [] },
			})
			wrapper.vm.chosen = 'chosen.example'
			await wrapper.vm.search(true)
			await flushPromises()

			expect(wrapper.vm.accounts.map((one) => one.acct)).toEqual(['from@chosen'])

			answerEverywhere()
			await flushPromises()

			expect(wrapper.vm.accounts.map((one) => one.acct)).toEqual(['from@chosen'])
		})

		it('does not let an old failure empty the results on screen', async () => {
			const wrapper = mountSearch()

			let failFirst
			axios.get.mockImplementationOnce(() => new Promise((resolve, reject) => {
				failFirst = () => reject(new Error('busy'))
			}))
			wrapper.vm.query = 'alice'
			wrapper.vm.search(true)
			await flushPromises()

			axios.get.mockResolvedValueOnce({
				data: { accounts: [{ id: '3', acct: 'still@here', known: false, url: 'https://s/3' }], sources: [] },
			})
			wrapper.vm.query = 'alicia'
			await wrapper.vm.search(true)
			await flushPromises()

			failFirst()
			await flushPromises()

			expect(wrapper.vm.accounts.map((one) => one.acct)).toEqual(['still@here'])
			expect(wrapper.vm.loading).toBe(false)
		})
	})
})
