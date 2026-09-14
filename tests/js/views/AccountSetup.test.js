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

const handleInput = (wrapper) => wrapper.find('#account-setup-handle')
const createButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().startsWith('Create @'))
const linkButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Put it on my profile')

describe('AccountSetup', () => {
	afterEach(() => {
		axios.post.mockReset()
	})

	it('suggests a handle and shows the address it would make', () => {
		const wrapper = mountSetup()

		expect(handleInput(wrapper).element.value).toBe('alice')
		expect(wrapper.find('.account-setup__host').text()).toBe('@cloud.example.org')
		expect(createButton(wrapper).text()).toBe('Create @alice@cloud.example.org')
		// nothing has been created: the page says so before anything else
		expect(wrapper.text()).toContain('Nothing has been created for you yet')
	})

	it('creates the account with the handle that was typed and says so', async () => {
		axios.post.mockResolvedValue({ data: { status: 1, result: { account: { id: '1', acct: 'ali_ce' } } } })
		const wrapper = mountSetup()

		await handleInput(wrapper).setValue('ali_ce')
		expect(createButton(wrapper).text()).toBe('Create @ali_ce@cloud.example.org')
		await wrapper.find('form.account-setup__choice').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/account/create', { username: 'ali_ce' })
		expect(wrapper.emitted('created')).toHaveLength(1)
		expect(wrapper.emitted('created')[0][0]).toEqual({ id: '1', acct: 'ali_ce' })
	})

	it('shows the reason a handle cannot be had, and stays', async () => {
		axios.post.mockRejectedValue({ response: { data: { status: -1, error: 'that handle is taken' } } })
		const wrapper = mountSetup()

		await wrapper.find('form.account-setup__choice').trigger('submit')
		await flushPromises()

		expect(wrapper.find('.account-setup__error').text()).toBe('that handle is taken')
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(handleInput(wrapper).element.disabled).toBe(false)
	})

	it('puts an account elsewhere on the profile and creates nothing', async () => {
		axios.post.mockResolvedValue({ data: { status: 1, result: { handle: 'alice@mastodon.social' } } })
		const wrapper = mountSetup({ linkedHandle: 'alice@mastodon.social' })

		expect(wrapper.find('#account-setup-linked').element.value).toBe('alice@mastodon.social')
		expect(linkButton(wrapper).attributes('disabled')).toBeUndefined()
		await wrapper.find('form.account-setup__choice--secondary').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/account/link', { handle: 'alice@mastodon.social' })
		expect(wrapper.find('.account-setup__done').text()).toContain('@alice@mastodon.social')
		expect(wrapper.emitted('linked')[0]).toEqual(['alice@mastodon.social'])
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})
