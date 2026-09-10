/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import FollowButton from '../../../src/components/FollowButton.vue'
import account from '../../../src/store/account.js'
import settings from '../../../src/store/settings.js'
import logger from '../../../src/services/logger.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

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

/**
 * NcDialog teleports into <body> and renders its buttons itself, which vitest
 * cannot drive; the stub renders them where the component is so the
 * confirmation can be clicked.
 */
const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: `<div v-if="open" class="dialog-stub">
		<span class="dialog-name">{{ name }}</span>
		<button v-for="(button, index) in buttons"
			:key="index"
			:class="'dialog-button dialog-button--' + index"
			@click="button.callback()">{{ button.label }}</button>
	</div>`,
}

const mountButton = (uid = bob.acct, errorHandler) => mount(FollowButton, {
	props: { uid },
	global: {
		plugins: [store],
		config: { errorHandler },
		stubs: { NcDialog: NcDialogStub },
	},
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

	it('offers unfollowing through one real button, reachable without a pointer', async () => {
		setRelationship(bob, { following: true })
		const wrapper = mountButton()

		// one control, not a hover swap of a label and a display:none button
		expect(buttonTexts(wrapper)).toEqual(['Following'])
		const button = wrapper.find('button')
		expect(button.attributes('aria-hidden')).toBeUndefined()
		expect(button.attributes('tabindex')).toBeUndefined()
		expect(button.attributes('aria-label')).toBe('Unfollow bob@remote.example')

		// the keyboard reaches it and it says what pressing it will do
		await button.trigger('focus')
		expect(button.text()).toBe('Unfollow')
		await button.trigger('blur')
		expect(button.text()).toBe('Following')
	})

	it('does not hide the unfollow control from the tab order in CSS', () => {
		// `display: none` until :hover was what locked out keyboards and
		// every touch device; the styles must not put it back
		const source = readFileSync(resolve(process.cwd(), 'src/components/FollowButton.vue'), 'utf8')
		const styles = source.slice(source.indexOf('<style'))
		expect(styles).not.toMatch(/display:\s*none/)
		expect(styles).not.toMatch(/:hover/)
	})

	it('asks before unfollowing', async () => {
		setRelationship(bob, { following: true })
		const dispatch = vi.spyOn(store, 'dispatch').mockResolvedValue(undefined)
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		expect(dispatch).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(true)
		expect(wrapper.find('.dialog-name').text()).toBe('Unfollow bob@remote.example?')

		// cancelling leaves the follow alone
		await wrapper.find('.dialog-button--0').trigger('click')
		expect(dispatch).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
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

	it('dispatches unfollowAccount once the confirmation is accepted', async () => {
		setRelationship(bob, { following: true })
		const dispatch = vi.spyOn(store, 'dispatch').mockResolvedValue(undefined)
		const wrapper = mountButton()

		await wrapper.find('button').trigger('click')
		await wrapper.find('.dialog-button--1').trigger('click')
		await flushPromises()
		expect(dispatch).toHaveBeenCalledWith('unfollowAccount', {
			currentAccount: 'alice@cloud.example.org',
			accountToUnfollow: 'bob@remote.example',
		})
		expect(dispatch).toHaveBeenCalledTimes(1)
	})

	it('re-enables the button on a follow that could not be carried out, and keeps the rejection in', async () => {
		setRelationship(bob)
		vi.spyOn(store, 'dispatch').mockRejectedValue(new Error('status -1'))
		const errorHandler = vi.fn()
		const wrapper = mountButton(bob.acct, errorHandler)

		await wrapper.find('button').trigger('click')
		await flushPromises()

		// the await had a `finally` and no `catch`, so a refusal left the
		// component as an unhandled rejection on its way out
		expect(errorHandler).not.toHaveBeenCalled()
		expect(logger.error).toHaveBeenCalledWith('Failed to follow an account', { error: expect.any(Error) })
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
		expect(buttonTexts(wrapper)).toEqual(['Following'])

		store.commit('unfollowAccount', bob.acct)
		await nextTick()
		expect(buttonTexts(wrapper)).toEqual(['Follow'])
	})
})
