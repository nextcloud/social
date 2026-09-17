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
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
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

const handles = (wrapper) => wrapper.findAll('.finder__handle').map((el) => el.text())

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

	it('names the directories it will ask, because an administrator chose them', async () => {
		const wrapper = mountSearch()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/directories`)
		expect(wrapper.find('.finder__sources').text()).toContain('mastodon.social')
		expect(wrapper.find('.finder__sources').text()).toContain('Everywhere')
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

		await wrapper.findAll('.finder__sources button')
			.find((button) => button.text() === 'mastodon.social')
			.trigger('click')
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

		const rows = wrapper.findAll('.finder__person')
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

		await wrapper.find('.finder__follow').trigger('click')
		await flushPromises()

		expect(followed).toHaveBeenCalledWith({ accountToFollow: 'jens@chaos.social' })
		expect(wrapper.find('.finder__follow').text()).toBe('Following')
	})

	/** A refused follow must not leave the row claiming it worked. */
	it('leaves the button alone when the follow was refused', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		const store = useAccountStore()
		vi.spyOn(store, 'followAccount').mockResolvedValue(undefined)

		await wrapper.find('.finder__follow').trigger('click')
		await flushPromises()

		expect(wrapper.find('.finder__follow').text()).toBe('Follow')
	})

	it('tells an empty answer apart from a question nobody has asked yet', async () => {
		const wrapper = mountSearch()
		await flushPromises()

		expect(wrapper.text()).not.toContain('Nobody by that name')

		await type(wrapper, 'nobody')

		expect(wrapper.text()).toContain('Nobody by that name')
	})

	/** A picture that will not load leaves a broken frame in every row. */
	it('falls back to an initial when an avatar will not load', async () => {
		serve([person()])
		const wrapper = mountSearch()
		await flushPromises()
		await type(wrapper, 'jens')

		await wrapper.find('img.finder__avatar').trigger('error')

		expect(wrapper.find('img.finder__avatar').exists()).toBe(false)
		expect(wrapper.find('.finder__avatar--blank').text()).toBe('J')
	})
})
