/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import AccountSetup from '../../../src/views/AccountSetup.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * Mounts the setup screen with account and instance state supplied by the server.
 *
 * @param {object} serverData overrides for the default instance state
 * @return {import('@vue/test-utils').VueWrapper} the mounted setup screen
 */
function mountSetup(serverData = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({
		public: false,
		cloudAddress: 'https://cloud.example.org',
		needsAccount: true,
		suggestedHandle: 'alice',
		linkedHandle: '',
		...serverData,
	})

	return mount(AccountSetup, { global: { plugins: [pinia], stubs: { NcLoadingIcon: true } } })
}

/**
 * Finds the username field in the creation form.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the setup screen
 * @return {import('@vue/test-utils').DOMWrapper} the matching form control
 */
const handleInput = (wrapper) => wrapper.find('#account-setup-handle')
/**
 * Finds the explicit account creation action.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the setup screen
 * @return {import('@vue/test-utils').DOMWrapper} the matching form control
 */
const createButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Create account')
/**
 * Finds the action that publishes an external handle.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the setup screen
 * @return {import('@vue/test-utils').DOMWrapper} the matching form control
 */
const linkButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Link account')
/**
 * Finds the choice that opens the account creation form.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the setup screen
 * @return {import('@vue/test-utils').DOMWrapper} the matching form control
 */
const createModeButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('Create a new account'))
/**
 * Finds the choice that opens the external account form.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the setup screen
 * @return {import('@vue/test-utils').DOMWrapper} the matching form control
 */
const linkModeButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('Link an existing account'))

describe('AccountSetup', () => {
	afterEach(() => {
		axios.post.mockReset()
	})

	it('lays the explainer beside the answers', () => {
		const wrapper = mountSetup()

		expect(wrapper.find('.account-setup__body').exists()).toBe(true)
		expect(wrapper.find('.account-setup__body > .account-setup__about').exists()).toBe(true)
		expect(wrapper.find('.account-setup__body > .account-setup__answers').exists()).toBe(true)
	})

	it('suggests a handle and shows the address it would make', () => {
		const wrapper = mountSetup()

		expect(handleInput(wrapper).element.value).toBe('alice')
		expect(wrapper.find('.account-setup__address').text()).toBe('@alice@cloud.example.org')
		expect(createButton(wrapper).text()).toBe('Create account')
		// nothing has been created: the page says so before anything else
		expect(wrapper.text()).toContain('Welcome to Aloha Social')
	})

	it('says what the software is before it asks anything', () => {
		const wrapper = mountSetup()

		expect(wrapper.text()).toContain('What Aloha Social gives you')
		expect(wrapper.text()).toContain('An address of your own')
		expect(wrapper.text()).toContain('One fediverse, many servers')
		expect(wrapper.text()).toContain('Yours to keep')
		// what this instance offers is not what every instance offers
		expect(wrapper.text()).not.toContain('Also on Bluesky')

		// the longer answer is folded, and opens
		const more = wrapper.find('.account-setup__more summary')
		expect(wrapper.find('.account-setup__more').element.open).toBe(false)
		expect(more.text()).toBe('What is the fediverse?')
	})

	// the administrator switches AT Protocol on per instance; where they
	// have, both the explainer and the address the username would make say so
	it('says the account is on Bluesky too, where this server offers that', () => {
		const wrapper = mountSetup({ bluesky: { enabled: true, host: 'cloud.example.org' } })

		expect(wrapper.text()).toContain('Also on Bluesky')
		expect(wrapper.text()).toContain('AT Protocol')
		expect(wrapper.find('.account-setup__address--bluesky').text()).toBe('On Bluesky too: @alice.cloud.example.org')
	})

	it('waits for a username before drawing the address it cannot show', async () => {
		const wrapper = mountSetup({ bluesky: { enabled: true, host: 'cloud.example.org' } })

		expect(wrapper.find('.account-setup__address--bluesky').exists()).toBe(true)
		await handleInput(wrapper).setValue('')
		expect(wrapper.find('.account-setup__address--bluesky').exists()).toBe(false)
	})

	it('creates the account with the handle that was typed and says so', async () => {
		axios.post.mockResolvedValue({ data: { status: 1, result: { account: { id: '1', acct: 'ali_ce' } } } })
		const wrapper = mountSetup()

		await handleInput(wrapper).setValue('ali_ce')
		expect(wrapper.find('.account-setup__address').text()).toBe('@ali_ce@cloud.example.org')
		await wrapper.find('form.account-setup__form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/account/create', { username: 'ali_ce' })
		expect(wrapper.emitted('created')).toHaveLength(1)
		expect(wrapper.emitted('created')[0][0]).toEqual({ id: '1', acct: 'ali_ce' })
	})

	it('shows the reason a handle cannot be had, and stays', async () => {
		axios.post.mockRejectedValue({ response: { data: { status: -1, error: 'that handle is taken' } } })
		const wrapper = mountSetup()

		await wrapper.find('form.account-setup__form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('.account-setup__error').text()).toBe('that handle is taken')
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(handleInput(wrapper).element.disabled).toBe(false)
		expect(handleInput(wrapper).attributes('aria-invalid')).toBe('true')
	})

	it('puts an account elsewhere on the profile and creates nothing', async () => {
		axios.post.mockResolvedValue({ data: { status: 1, result: { handle: 'alice@mastodon.social' } } })
		const wrapper = mountSetup({ linkedHandle: 'alice@mastodon.social' })

		await linkModeButton(wrapper).trigger('click')
		expect(wrapper.find('#account-setup-linked').element.value).toBe('alice@mastodon.social')
		expect(linkButton(wrapper).attributes('disabled')).toBeUndefined()
		await wrapper.find('form.account-setup__form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/account/link', { handle: 'alice@mastodon.social' })
		expect(wrapper.find('.account-setup__done').text()).toContain('@alice@mastodon.social')
		expect(wrapper.emitted('linked')[0]).toEqual(['alice@mastodon.social'])
		expect(wrapper.emitted('created')).toBeUndefined()
	})

	it('keeps the create path as default and only shows linking form once selected', async () => {
		const wrapper = mountSetup()

		expect(wrapper.find('#account-setup-handle').exists()).toBe(true)
		expect(wrapper.find('#account-setup-linked').exists()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.text()).not.toContain('This only shows your existing account on your Nextcloud profile')
		await linkModeButton(wrapper).trigger('click')
		expect(wrapper.find('#account-setup-handle').exists()).toBe(false)
		expect(wrapper.find('#account-setup-linked').exists()).toBe(true)
		expect(wrapper.text()).toContain('This only shows your existing account on your Nextcloud profile')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('leaves a refusal behind when the answer changes', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'that handle is taken' } } })
		const wrapper = mountSetup()

		await wrapper.find('form.account-setup__form').trigger('submit')
		await flushPromises()
		expect(wrapper.find('.account-setup__error').text()).toBe('that handle is taken')

		await linkModeButton(wrapper).trigger('click')
		await createModeButton(wrapper).trigger('click')
		expect(wrapper.find('.account-setup__error').exists()).toBe(false)
	})

	it('holds the choice still while a form is talking to the server', async () => {
		let release
		axios.post.mockReturnValue(new Promise((resolve) => {
			release = resolve
		}))
		const wrapper = mountSetup()

		await wrapper.find('form.account-setup__form').trigger('submit')
		expect(linkModeButton(wrapper).attributes('disabled')).toBeDefined()

		release({ data: { result: { account: { id: '1', acct: 'alice' } } } })
		await flushPromises()
		expect(linkModeButton(wrapper).attributes('disabled')).toBeUndefined()
	})
})
