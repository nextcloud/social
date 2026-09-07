/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ActorAvatar from '../../../src/components/ActorAvatar.vue'

// NcAvatar loads images asynchronously and never renders an <img> in jsdom,
// so assert on what the component hands to it instead.
const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'displayName', 'size', 'disableTooltip', 'showUserStatus'],
	template: '<span class="nc-avatar-stub" />',
}

const mountAvatar = (props) => mount(ActorAvatar, {
	props,
	global: { stubs: { NcAvatar: NcAvatarStub } },
})

const local = { username: 'bob', acct: 'bob', avatar: 'https://cloud.example.org/avatar/bob/128' }

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
		expect(avatar.props('showUserStatus')).toBe(false)
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
})
