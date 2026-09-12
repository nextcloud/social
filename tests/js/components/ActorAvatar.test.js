/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AccountHoverCard from '../../../src/components/AccountHoverCard.vue'
import ActorAvatar from '../../../src/components/ActorAvatar.vue'
import { createPinia } from 'pinia'

// NcAvatar loads images asynchronously and never renders an <img> in jsdom,
// so assert on what the component hands to it instead.
const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'displayName', 'size', 'disableTooltip', 'hideStatus'],
	template: '<span class="nc-avatar-stub" />',
}

function mountAvatar(props) {
	return mount(ActorAvatar, {
		props,
		global: { plugins: [createPinia()], stubs: { NcAvatar: NcAvatarStub } },
	})
}

const local = { username: 'bob', acct: 'bob', avatar: 'https://cloud.example.org/avatar/bob/128' }
const remote = {
	username: 'bob',
	acct: 'bob@remote.example',
	url: 'https://remote.example/users/bob',
	avatar: 'https://cloud.example.org/index.php/apps/social/document/get/abcdef',
}
const AVATAR_ENDPOINT = '/index.php/apps/social/api/v1/global/actor/avatar?id='

describe('ActorAvatar', () => {
	it('resolves a local actor through the server avatar endpoint by username', () => {
		const avatar = mountAvatar({ actor: local }).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('bob')
		expect(avatar.props('displayName')).toBe('bob')
		// the actor's own avatar URL is ignored for local users, the server one wins
		expect(avatar.props('url')).toBeUndefined()
	})

	it('never shows a tooltip or user status', () => {
		const avatar = mountAvatar({ actor: local }).findComponent(NcAvatarStub)
		expect(avatar.props('disableTooltip')).toBe(true)
		expect(avatar.props('hideStatus')).toBe(true)
	})

	it('defaults to 32px and forwards a custom size', () => {
		expect(mountAvatar({ actor: local }).findComponent(NcAvatarStub).props('size')).toBe(32)
		expect(mountAvatar({ actor: local, size: 64 }).findComponent(NcAvatarStub).props('size')).toBe(64)
	})

	it('works for a local actor without any avatar field', () => {
		const avatar = mountAvatar({ actor: { username: 'bob', acct: 'bob' } }).findComponent(NcAvatarStub)
		expect(avatar.props('user')).toBe('bob')
		expect(avatar.props('url')).toBeUndefined()
	})

	it('shows a remote actor with the avatar URL that was delivered with it', () => {
		const avatar = mountAvatar({ actor: remote }).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe(remote.avatar)
		expect(avatar.props('user')).toBeUndefined()
	})

	it('resolves a remote actor without an avatar through the proxy endpoint, by its escaped ActivityPub id', () => {
		const avatar = mountAvatar({ actor: { ...remote, avatar: undefined } }).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe(`${AVATAR_ENDPOINT}https%3A%2F%2Fremote.example%2Fusers%2Fbob`)
	})

	it('previews the actor when the avatar is hovered', () => {
		const card = mountAvatar({ actor: remote }).findComponent(AccountHoverCard)
		expect(card.props('handle')).toBe('bob@remote.example')
		expect(card.props('fallback')).toStrictEqual(remote)
		expect(card.vm.shown).toBe(false)
		expect(card.findComponent(NcAvatarStub).props('url')).toBe(remote.avatar)
	})

	it('can be asked for a plain avatar with no preview', () => {
		const wrapper = mountAvatar({ actor: remote, hoverCard: false })
		expect(wrapper.findComponent(AccountHoverCard).exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).props('url')).toBe(remote.avatar)
	})

	it('shows no preview for an actor with no handle to look up', () => {
		const wrapper = mountAvatar({ actor: { username: '', acct: '' } })
		expect(wrapper.findComponent(AccountHoverCard).exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).exists()).toBe(true)
	})

	it('still shows a remote actor that has neither an avatar nor an ActivityPub id', () => {
		const avatar = mountAvatar({ actor: { username: 'bob', acct: 'bob@remote.example' } }).findComponent(NcAvatarStub)
		expect(avatar.exists()).toBe(true)
		expect(avatar.props('url')).toBe(AVATAR_ENDPOINT)
	})
})
