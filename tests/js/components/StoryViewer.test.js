/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import StoryViewer from '../../../src/components/StoryViewer.vue'
import { useAccountStore } from '../../../src/store/account.js'

const { post, del } = vi.hoisted(() => ({ post: vi.fn(), del: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post, delete: del } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const alice = { id: '7', url: 'https://cloud.example.org/@alice', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
const bob = { id: '9', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: '' }

function story(id, account, overrides = {}) {
	return { id, account, seen: false, duration: 5, caption: '', view_count: 0, created_at: new Date().toISOString(), media: { type: 'image', url: `https://cloud.example.org/${id}.jpg` }, ...overrides }
}

const NcModalStub = {
	name: 'NcModal',
	props: ['name', 'hasPrevious', 'hasNext', 'size'],
	emits: ['close', 'previous', 'next'],
	template: '<div class="modal-stub"><slot /></div>',
}

function mountViewer(groups, start = 0) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.addAccount({ actorId: alice.url, data: alice })
	store.setCurrentAccount('alice@cloud.example.org')

	return mount(StoryViewer, {
		props: { groups, start },
		global: { plugins: [pinia], stubs: { NcModal: NcModalStub, ActorAvatar: true, RouterLink: RouterLinkStub } },
	})
}

describe('StoryViewer', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		post.mockReset().mockResolvedValue({ data: {} })
		del.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the first story of the group it was opened on and marks it seen at once', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob), story('3', bob)] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/2.jpg')
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories/2/seen'))
		expect(wrapper.emitted('seen')[0]).toEqual(['2'])
		expect(wrapper.findAll('.story-viewer__segment')).toHaveLength(2)
	})

	it('moves on when the story\'s seconds are up, and closes after the last one', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob, { duration: 3 }), story('3', bob, { duration: 3 })] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		vi.advanceTimersByTime(3200)
		await flushPromises()
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/3.jpg')

		vi.advanceTimersByTime(3200)
		await flushPromises()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('goes forward and back on the two halves of the stage', async () => {
		const groups = [{ account: bob, own: false, seen: false, stories: [story('2', bob), story('3', bob)] }]
		const wrapper = mountViewer(groups)
		await flushPromises()

		await wrapper.find('.story-viewer__tap--forward').trigger('click')
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/3.jpg')
		await wrapper.find('.story-viewer__tap--back').trigger('click')
		expect(wrapper.find('.story-viewer__image').attributes('src')).toBe('https://cloud.example.org/2.jpg')
	})

	it('shows the poster their view count and a delete, and nobody else either', async () => {
		const own = [{ account: alice, own: true, seen: true, stories: [story('1', alice, { view_count: 12, seen: true })] }]
		const mine = mountViewer(own)
		await flushPromises()
		expect(mine.find('.story-viewer__views').text()).toContain('12')
		expect(mine.find('.story-viewer__delete').exists()).toBe(true)
		// own stories are not marked seen: the server would refuse, and the ring is the reader's anyway
		expect(post).not.toHaveBeenCalled()

		const theirs = mountViewer([{ account: bob, own: false, seen: false, stories: [story('2', bob)] }])
		await flushPromises()
		expect(theirs.find('.story-viewer__views').exists()).toBe(false)
		expect(theirs.find('.story-viewer__delete').exists()).toBe(false)
	})

	it('deletes an own story through the API and tells the bar', async () => {
		del.mockResolvedValue({ data: {} })
		const own = [{ account: alice, own: true, seen: true, stories: [story('1', alice, { seen: true })] }]
		const wrapper = mountViewer(own)
		await flushPromises()

		await wrapper.find('.story-viewer__delete').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories/1'))
		expect(wrapper.emitted('deleted')[0][0].id).toBe('1')
		expect(showSuccess).toHaveBeenCalled()
	})
})
