/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TimelineAvatar from '../../../src/components/TimelineAvatar.vue'

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'displayName', 'size', 'disableTooltip', 'showUserStatus', 'menuPosition'],
	template: '<span class="nc-avatar-stub" />',
}

const mountAvatar = (item) => mount(TimelineAvatar, {
	props: { item },
	global: { stubs: { NcAvatar: NcAvatarStub } },
})

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
		expect(avatar.props('menuPosition')).toBe('left')
		expect(avatar.props('showUserStatus')).toBe(false)
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

	it('renders nothing when the status has no account', () => {
		const wrapper = mountAvatar({ id: '4' })
		expect(wrapper.find('.post-avatar').exists()).toBe(false)
		expect(wrapper.findComponent(NcAvatarStub).exists()).toBe(false)
	})
})
