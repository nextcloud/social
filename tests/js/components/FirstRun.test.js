/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import FirstRun from '../../../src/components/FirstRun.vue'
import eventBus from '../../../src/services/eventBus.js'
import { showError } from '../../../src/services/toast.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const bob = { id: '2', acct: 'bob@cloud.example.org', username: 'bob', display_name: 'Bob', avatar: '' }
const carol = { id: '3', acct: 'carol@cloud.example.org', username: 'carol', display_name: 'Carol', avatar: '' }
const stranger = { id: '9', acct: 'zed@remote.example', username: 'zed', display_name: 'Zed', avatar: '' }
const pack = { id: 'fediverse', name: 'The fediverse', description: 'The projects this app talks to', size: 4, accounts: [] }

/** what the server answers the two lookups with */
function serverHas({ suggestions = [], packs = [] } = {}) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/api/v2/suggestions')) {
			return Promise.resolve({ data: suggestions })
		}
		if (url.endsWith('/api/v1/starter_packs')) {
			return Promise.resolve({ data: packs })
		}
		return Promise.reject(new Error(`unexpected ${url}`))
	})
}

let accountStore

async function mountFirstRun() {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', firstrun: true })
	accountStore = useAccountStore()
	vi.spyOn(accountStore, 'followAccount').mockResolvedValue(undefined)
	const wrapper = mount(FirstRun, {
		global: { plugins: [pinia], stubs: { ActorAvatar: true, NcLoadingIcon: true } },
	})
	await flushPromises()
	return wrapper
}

const buttons = (wrapper) => wrapper.findAll('button')
const button = (wrapper, text) => buttons(wrapper).find((b) => b.text() === text)
async function next(wrapper) {
	await button(wrapper, 'Next').trigger('click')
	await flushPromises()
}

describe('FirstRun', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('opens on the address, which is the one thing a new account has', async () => {
		serverHas()
		const wrapper = await mountFirstRun()

		expect(wrapper.find('h2').text()).toBe('You are on the fediverse')
		expect(wrapper.find('.first-run__handle').text()).toBe('@alice@cloud.example.org')
		// four steps, the first lit
		expect(wrapper.findAll('.first-run__dot')).toHaveLength(4)
		expect(wrapper.find('.first-run__dot--current').attributes('aria-current')).toBe('step')
	})

	it('copies the address', async () => {
		serverHas()
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await mountFirstRun()

		await button(wrapper, 'Copy').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith('@alice@cloud.example.org')
		expect(button(wrapper, 'Copied')).toBeDefined()
	})

	it('shows the colleagues among the suggestions, and only them, with the starter packs', async () => {
		serverHas({
			suggestions: [
				{ sources: ['featured'], account: bob },
				{ sources: ['global'], account: stranger },
				{ sources: ['featured', 'global'], account: carol },
			],
			packs: [pack],
		})
		const wrapper = await mountFirstRun()
		await next(wrapper)

		expect(wrapper.find('h2').text()).toBe('A feed is only as good as who is in it')
		// the people on this Nextcloud: what no other server could know
		expect(wrapper.findAll('.first-run__person-name').map((el) => el.text())).toEqual(['Bob', 'Carol'])
		expect(wrapper.text()).not.toContain('Zed')
		expect(wrapper.find('.first-run__pack-name').text()).toBe('The fediverse')
		expect(button(wrapper, 'Follow all 4')).toBeDefined()
	})

	it('follows a colleague from the card and says so', async () => {
		serverHas({ suggestions: [{ sources: ['featured'], account: bob }] })
		const wrapper = await mountFirstRun()
		await next(wrapper)

		await button(wrapper, 'Follow').trigger('click')
		await flushPromises()

		expect(accountStore.followAccount).toHaveBeenCalledWith({ accountToFollow: 'bob@cloud.example.org' })
		expect(button(wrapper, 'Following')).toBeDefined()
		expect(button(wrapper, 'Following').attributes('disabled')).toBeDefined()
	})

	it('follows a whole starter pack through the pack route', async () => {
		serverHas({ packs: [pack] })
		axios.post.mockResolvedValue({ data: { followed: ['a', 'b', 'c', 'd'], failed: [] } })
		const wrapper = await mountFirstRun()
		await next(wrapper)

		await button(wrapper, 'Follow all 4').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/starter_packs/fediverse/follow')
		expect(button(wrapper, 'Following')).toBeDefined()
	})

	it('says so when there is nobody yet, rather than showing an empty list', async () => {
		serverHas()
		const wrapper = await mountFirstRun()
		await next(wrapper)

		expect(wrapper.text()).toContain('You are the first')
		expect(wrapper.text()).toContain('No starter packs on this server')
	})

	it('survives the suggestions failing and still offers the packs', async () => {
		axios.get.mockImplementation((url) => url.endsWith('/starter_packs')
			? Promise.resolve({ data: [pack] })
			: Promise.reject(new Error('500')))
		const wrapper = await mountFirstRun()
		await next(wrapper)

		expect(wrapper.find('.first-run__pack-name').text()).toBe('The fediverse')
		expect(showError).not.toHaveBeenCalled()
	})

	it('uploads a following_accounts.csv to the same import the Settings page uses', async () => {
		serverHas()
		axios.post.mockResolvedValue({ data: { followed: 12, skipped: 1, failed: { 'x@y': 'gone' } } })
		const wrapper = await mountFirstRun()
		await next(wrapper)
		await next(wrapper)

		expect(wrapper.find('h2').text()).toBe('Already somewhere else?')
		const input = wrapper.find('input[type="file"]')
		const file = new File(['Account address,Show boosts\nbob@remote.example,true\n'], 'following_accounts.csv', { type: 'text/csv' })
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/social/api/v1/migration/follows')
		expect(body).toBeInstanceOf(FormData)
		expect(body.get('file')).toBe(file)
		expect(wrapper.find('.first-run__result').text()).toBe('12 followed, 1 skipped, 1 could not be reached')
	})

	it('ends by putting the caret in the composer', async () => {
		serverHas()
		const emit = vi.spyOn(eventBus, 'emit')
		const wrapper = await mountFirstRun()
		await next(wrapper)
		await next(wrapper)
		await next(wrapper)

		expect(wrapper.find('h2').text()).toBe('That is all there is to it')
		await button(wrapper, 'Write your first post').trigger('click')

		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(emit).toHaveBeenCalledWith('shortcut:compose')
	})

	it('can be skipped at any point, without touching the composer', async () => {
		serverHas()
		const emit = vi.spyOn(eventBus, 'emit')
		const wrapper = await mountFirstRun()

		await wrapper.find('.first-run__skip').trigger('click')

		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(emit).not.toHaveBeenCalledWith('shortcut:compose')
	})

	it('goes back as well as forward', async () => {
		serverHas()
		const wrapper = await mountFirstRun()
		await next(wrapper)
		expect(button(wrapper, 'Back')).toBeDefined()

		await button(wrapper, 'Back').trigger('click')
		await flushPromises()

		expect(wrapper.find('h2').text()).toBe('You are on the fediverse')
		expect(button(wrapper, 'Back')).toBeUndefined()
	})
})
