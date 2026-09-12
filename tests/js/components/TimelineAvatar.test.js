/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AccountHoverCard from '../../../src/components/AccountHoverCard.vue'
import TimelineAvatar from '../../../src/components/TimelineAvatar.vue'
import { createPinia } from 'pinia'

const NcAvatarStub = {
	name: 'NcAvatar',
	// the v9 prop names: showUserStatus and menuPosition are gone, and passing
	// them was silently ignored (so local avatars showed a status dot)
	props: ['url', 'user', 'displayName', 'size', 'disableTooltip', 'hideStatus', 'menuContainer'],
	template: '<span class="nc-avatar-stub" />',
}

function mountAvatar(item) {
	return mount(TimelineAvatar, {
		props: { item },
		global: { plugins: [createPinia()], stubs: { NcAvatar: NcAvatarStub } },
	})
}

const localStatus = {
	id: '1',
	account: { username: 'bob', acct: 'bob', display_name: 'Bob', avatar: 'https://cloud.example.org/avatar/bob/128' },
}
const remoteStatus = {
	id: '2',
	account: { username: 'carol', acct: 'carol@remote.example', display_name: 'Carol', avatar: 'https://remote.example/media/carol.png' },
}

describe('TimelineAvatar', () => {
	it('shows a local author through the server avatar of their uid', () => {
		const wrapper = mountAvatar(localStatus)
		const avatar = wrapper.findComponent(NcAvatarStub)
		expect(wrapper.find('.post-avatar').exists()).toBe(true)
		expect(avatar.props('user')).toBe('bob')
		expect(avatar.props('displayName')).toBe('Bob')
		expect(avatar.props('url')).toBeUndefined()
		expect(avatar.props('hideStatus')).toBe(true)
		expect(avatar.props('disableTooltip')).toBe(true)
	})

	it('shows a remote author through the avatar URL delivered with the status', () => {
		const avatar = mountAvatar(remoteStatus).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBe('https://remote.example/media/carol.png')
		expect(avatar.props('user')).toBeUndefined()
		expect(avatar.props('disableTooltip')).toBe(true)
	})

	it('passes no URL for a remote author without an avatar so NcAvatar falls back', () => {
		const avatar = mountAvatar({ id: '3', account: { username: 'dave', acct: 'dave@remote.example' } }).findComponent(NcAvatarStub)
		expect(avatar.props('url')).toBeUndefined()
		expect(avatar.props('user')).toBeUndefined()
	})

	it('previews the author when the avatar is hovered, seeded with what the status carries', () => {
		const card = mountAvatar(remoteStatus).findComponent(AccountHoverCard)
		expect(card.props('handle')).toBe('carol@remote.example')
		expect(card.props('fallback')).toStrictEqual(remoteStatus.account)
		expect(card.props('variant')).toBe('block')
		// closed, and nothing asked for, until somebody hovers
		expect(card.vm.shown).toBe(false)
	})

	it('renders nothing when the status has no account', () => {
		const wrapper = mountAvatar({ id: '4' })
		expect(wrapper.find('.post-avatar').exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).exists()).toBe(false)
	})
})
