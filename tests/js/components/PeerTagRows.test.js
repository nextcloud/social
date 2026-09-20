/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import PeerTagRows from '../../../src/components/PeerTagRows.vue'
import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * @param {string} name the tag, without its '#'
 * @param {string[]} servers who reported it
 * @param {object} extra `local` where a test cares
 * @return {object} a PeerTag entity
 */
function peerTag(name, servers, extra = {}) {
	return { name, servers, servers_count: servers.length, uses: 0, local: false, ...extra }
}

/**
 * @param {object[]} tags what to draw
 * @return {object} the mounted rows
 */
function mountRows(tags) {
	// the follow button reads the server data, so the rows need a store the
	// way every other page that draws one does
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example' })

	return mount(PeerTagRows, {
		props: { tags, followed: [] },
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
	})
}

describe('hashtags as other servers report them', () => {
	const where = (wrapper) => wrapper.findAll('.peertags__where').map((el) => el.text())

	it('points a tag at this instance\'s own timeline for it', () => {
		const wrapper = mountRows([peerTag('berlin', ['mastodon.social'])])

		expect(wrapper.findComponent(RouterLinkStub).props('to'))
			.toEqual({ name: 'tags', params: { tag: 'berlin' } })
	})

	it('names one server plainly', () => {
		expect(where(mountRows([peerTag('berlin', ['mastodon.social'])])))
			.toEqual(['Busy on mastodon.social'])
	})

	/**
	 * The component is also usable for a tag only this instance has seen, and
	 * "busy on 0 servers" is not a sentence.
	 */
	it('says a tag only this server knows is used here', () => {
		expect(where(mountRows([peerTag('nextcloud', ['cloud.example'], { local: true })])))
			.toEqual(['Used here'])
	})

	it('survives a row with no servers at all', () => {
		expect(where(mountRows([peerTag('berlin', [])]))).toEqual(['Used here'])
	})

	it('passes a follow up to whoever is showing the rows', async () => {
		const wrapper = mountRows([peerTag('berlin', ['mastodon.social'])])

		wrapper.findComponent(HashtagFollowButton).vm.$emit('changed', { tag: 'berlin', following: true })

		expect(wrapper.emitted('changed')).toEqual([[{ tag: 'berlin', following: true }]])
	})
})
