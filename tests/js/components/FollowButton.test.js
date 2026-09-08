/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import FollowButton from '../../../src/components/FollowButton.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'

// @nextcloud/auth reads the current user from <head> once and caches it.
vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

// The store modules keep their state in a shared module-level object.
const pristine = structuredClone(account.state)

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol', display_name: 'Carol' }

let store

const makeStore = (serverData = {}) => {
	Object.assign(account.state, structuredClone(pristine))
	store = createStore({ modules: { account, settings } })
	store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org', ...serverData })
	store.commit('addAccount', { actorId: bob.url, data: bob })
	store.commit('addAccount', { actorId: carol.url, data: carol })
	return store
}

const setRelationship = (target, data = {}) => store.commit('addRelationship', {
	actorId: target.id,
	data: { id: target.id, following: false, requested: false, ...data },
})

const mountButton = (uid = bob.acct, errorHandler) => mount(FollowButton, {
	props: { uid },
	global: { plugins: [store], config: { errorHandler } },
})

const buttonTexts = (wrapper) => wrapper.findAll('button').map((button) => button.text())

describe('FollowButton', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders nothing until the relationship with the account is known', () => {
		expect(mountButton().find('button').exists()).toBe(false)
	})

	it('renders nothing for unauthenticated visitors of the public page', () => {
		makeStore({ public: true })
		setRelationship(bob)
		expect(mountButton().find('button').exists()).toBe(false)
	})

	it('offers to follow an account the user does not follow yet', () => {
		setRelationship(bob)
		const wrapper = mountButton()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('shows the following state together with the hover unfollow action', () => {
		setRelationship(bob, { following: true })
		const wrapper = mountButton()
		expect(wrapper.find('.follow-button-container .follow-button--following').text()).toBe('Following')
		expect(wrapper.find('.follow-button-container .follow-button--unfollow').text()).toBe('Unfollow')
		expect(buttonTexts(wrapper)).toEqual(['Following', 'Unfollow'])
	})

	it('shows a disabled "Requested" state while a follow request is pending', () => {
		setRelationship(bob, { requested: true })
		const button = mountButton().find('button')
		expect(button.text()).toBe('Requested')
		expect(button.attributes('disabled')).toBeDefined()
	})

	it('dispatches followAccount with both handles and blocks the button until it settles', async () => {
		setRelationship(bob)
		let settle
		const dispatch = vi.spyOn(store, 'dispatch').mockImplementation(() => new Promise((resolve) => {
			settle = resolve
		}))
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		expect(dispatch).toHaveBeenCalledWith('followAccount', {
			currentAccount: 'alice@cloud.example.org',
			accountToFollow: 'bob@remote.example',
		})
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()

		settle()
		await flushPromises()
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('dispatches unfollowAccount from the unfollow button', async () => {
		setRelationship(bob, { following: true })
		const dispatch = vi.spyOn(store, 'dispatch').mockResolvedValue(undefined)
		const wrapper = mountButton()

		await wrapper.find('.follow-button--unfollow').trigger('click')
		expect(dispatch).toHaveBeenCalledWith('unfollowAccount', {
			currentAccount: 'alice@cloud.example.org',
			accountToUnfollow: 'bob@remote.example',
		})
		expect(dispatch).toHaveBeenCalledTimes(1)
	})

	it('re-enables the button when the follow request is rejected', async () => {
		setRelationship(bob)
		vi.spyOn(store, 'dispatch').mockRejectedValue(new Error('status -1'))
		const errorHandler = vi.fn()
		const wrapper = mountButton(bob.acct, errorHandler)

		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(errorHandler).toHaveBeenCalledTimes(1)
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('completes a bare local uid with the instance hostname', async () => {
		setRelationship(carol)
		const dispatch = vi.spyOn(store, 'dispatch').mockResolvedValue(undefined)
		await mountButton('carol').find('button').trigger('click')
		expect(dispatch).toHaveBeenCalledWith('followAccount', {
			currentAccount: 'alice@cloud.example.org',
			accountToFollow: 'carol@cloud.example.org',
		})
	})

	it('flips to the following state once the store records the follow', async () => {
		setRelationship(bob)
		const wrapper = mountButton()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])

		store.commit('followAccount', bob.acct)
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Following', 'Unfollow'])

		store.commit('unfollowAccount', bob.acct)
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])
	})
})
