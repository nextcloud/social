/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StarterPackDetail, { CONFIRM_ABOVE } from '../../../src/components/StarterPackDetail.vue'

const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	template: '<div v-if="open" class="dialog-stub"><span class="dialog-stub__name">{{ name }}</span><button v-for="button in buttons" :key="button.label" class="dialog-stub__button" @click="button.callback()">{{ button.label }}</button></div>',
}

const member = (key, extra = {}) => ({ key, acct: `${key}@remote.example`, name: '', actor: { acct: `${key}@remote.example` }, following: false, ...extra })

function pack(members, extra = {}) {
	return { name: 'News', description: 'Good sources', curator: '@curator@remote.example', topic: 'news', url: 'https://remote.example/collections/1', members, ...extra }
}

function mountDetail(props) {
	return mount(StarterPackDetail, {
		props: {
			followMembers: vi.fn(async (members) => ({ followed: members.map((m) => m.key), failed: [] })),
			followMember: vi.fn(async () => true),
			...props,
		},
		global: {
			stubs: {
				ActorAvatar: true,
				NcDialog: NcDialogStub,
				RouterLink: { props: ['to'], template: '<a><slot /></a>' },
			},
		},
	})
}

const followAllButton = (wrapper) => wrapper.find('.starter-pack-detail__actions button')

describe('StarterPackDetail', () => {
	beforeEach(() => {
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('says who curated it and links to where it lives, with no network named', () => {
		const wrapper = mountDetail({ pack: pack([member('a')]) })

		const origin = wrapper.find('.starter-pack-detail__origin')
		expect(origin.text()).toContain('Curated by @curator@remote.example')
		expect(origin.text()).toContain('#news')
		expect(origin.find('a').attributes('href')).toBe('https://remote.example/collections/1')
		expect(origin.find('a').attributes('rel')).toContain('noopener')
		expect(wrapper.text()).not.toMatch(/Bluesky|fediverse|Mastodon/)
	})

	it('puts its name at the level it is given, and its sections one below', () => {
		const wrapper = mountDetail({ pack: pack([member('a')]), headingLevel: 2 })

		expect(wrapper.find('h2').text()).toBe('News')
		expect(wrapper.find('h2').attributes('tabindex')).toBe('-1')
		expect(wrapper.find('h3').exists()).toBe(true)
		expect(wrapper.find('h1').exists()).toBe(false)
	})

	it('gives every member not followed yet a Follow, and marks the rest', () => {
		const wrapper = mountDetail({ pack: pack([member('a'), member('b', { following: true }), member('me', { self: true })]) })

		const rows = wrapper.findAll('.starter-pack-detail__row')
		expect(rows[0].find('button').text()).toBe('Follow')
		expect(rows[1].find('.starter-pack-detail__state').text()).toBe('Following')
		expect(rows[2].find('button').exists()).toBe(false)
		expect(rows[2].find('.starter-pack-detail__state').exists()).toBe(false)
		expect(followAllButton(wrapper).text()).toContain('Follow 1 person')
	})

	it('follows one member and shows them as followed', async () => {
		const followMember = vi.fn(async () => true)
		const wrapper = mountDetail({ pack: pack([member('a'), member('b')]), followMember })

		await wrapper.findAll('.starter-pack-detail__row')[0].find('button').trigger('click')
		await flushPromises()

		expect(followMember).toHaveBeenCalledWith(expect.objectContaining({ key: 'a' }))
		expect(wrapper.findAll('.starter-pack-detail__state')).toHaveLength(1)
		expect(followAllButton(wrapper).text()).toContain('Follow 1 person')
	})

	it('leaves a member it could not follow followable', async () => {
		const wrapper = mountDetail({ pack: pack([member('a')]), followMember: vi.fn(async () => false) })

		await wrapper.find('.starter-pack-detail__row button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.starter-pack-detail__state').exists()).toBe(false)
	})

	it('follows everybody not followed yet, and then says it is done', async () => {
		const followMembers = vi.fn(async (members) => ({ followed: members.map((m) => m.key), failed: [] }))
		const wrapper = mountDetail({ pack: pack([member('a'), member('b'), member('c', { following: true })]), followMembers })

		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(followMembers.mock.calls[0][0].map((m) => m.key)).toEqual(['a', 'b'])
		expect(showSuccess).toHaveBeenCalledWith('You now follow 2 people from this pack')
		expect(wrapper.findAll('.starter-pack-detail__state')).toHaveLength(3)
		expect(followAllButton(wrapper).text()).toBe('You follow everybody in it')
		expect(followAllButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('shows how far it has got while it follows', async () => {
		let finish
		const followMembers = vi.fn((members, feeds, progress) => new Promise((resolve) => {
			progress(1)
			finish = () => resolve({ followed: members.map((m) => m.key), failed: [] })
		}))
		const wrapper = mountDetail({ pack: pack([member('a'), member('b')]), followMembers })

		await followAllButton(wrapper).trigger('click')
		await wrapper.vm.$nextTick()
		expect(followAllButton(wrapper).text()).toContain('Following 1 of 2')

		finish()
		await flushPromises()
	})

	it('reports how many could not be followed', async () => {
		const followMembers = vi.fn(async () => ({ followed: ['a'], failed: ['b'] }))
		const wrapper = mountDetail({ pack: pack([member('a'), member('b')]), followMembers })

		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('1 account could not be followed')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.findAll('.starter-pack-detail__state')).toHaveLength(1)
	})

	it('treats nobody followed as a failure, not as "followed 0"', async () => {
		const followMembers = vi.fn(async () => ({ followed: [], failed: [] }))
		const wrapper = mountDetail({ pack: pack([member('a'), member('b')]), followMembers })

		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(expect.stringContaining('Nobody in this pack could be followed'))
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('says so when the follow fails outright', async () => {
		const followMembers = vi.fn(async () => {
			throw new Error('busy')
		})
		const wrapper = mountDetail({ pack: pack([member('a')]), followMembers })

		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not follow from the starter pack')
		expect(followAllButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it(`asks first before following more than ${CONFIRM_ABOVE} people`, async () => {
		const members = Array.from({ length: CONFIRM_ABOVE + 1 }, (_, i) => member(`m${i}`))
		const followMembers = vi.fn(async (asked) => ({ followed: asked.map((m) => m.key), failed: [] }))
		const wrapper = mountDetail({ pack: pack(members), followMembers })

		await followAllButton(wrapper).trigger('click')
		expect(followMembers).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub__name').text()).toBe(`Follow ${CONFIRM_ABOVE + 1} people?`)

		await wrapper.findAll('.dialog-stub__button')[0].trigger('click')
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		expect(followMembers).not.toHaveBeenCalled()

		await followAllButton(wrapper).trigger('click')
		await wrapper.findAll('.dialog-stub__button')[1].trigger('click')
		await flushPromises()

		expect(followMembers).toHaveBeenCalledTimes(1)
		expect(followMembers.mock.calls[0][0]).toHaveLength(CONFIRM_ABOVE + 1)
	})

	it(`does not ask for ${CONFIRM_ABOVE} or fewer`, async () => {
		const members = Array.from({ length: CONFIRM_ABOVE }, (_, i) => member(`m${i}`))
		const followMembers = vi.fn(async (asked) => ({ followed: asked.map((m) => m.key), failed: [] }))
		const wrapper = mountDetail({ pack: pack(members), followMembers })

		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		expect(followMembers).toHaveBeenCalledTimes(1)
	})

	it('keeps the feeds with everybody only when asked to', async () => {
		const followMembers = vi.fn(async (members) => ({ followed: members.map((m) => m.key), failed: [] }))
		const feeds = [{ uri: 'at://feed', name: 'Cats', description: '', route: null }]
		const wrapper = mountDetail({ pack: pack([member('a')], { feeds }), followMembers })

		expect(wrapper.find('.starter-pack-detail__section').text()).toContain('Cats')
		await followAllButton(wrapper).trigger('click')
		await flushPromises()

		expect(followMembers.mock.calls[0][1]).toBe(true)
	})

	it('offers no follow to a visitor', () => {
		const wrapper = mountDetail({ pack: pack([member('a')]), canFollow: false })

		expect(wrapper.find('.starter-pack-detail__actions').exists()).toBe(false)
		expect(wrapper.find('.starter-pack-detail__row button').exists()).toBe(false)
	})

	it('does not offer to follow members it is still reading', () => {
		const wrapper = mountDetail({ pack: pack([]), loading: true })

		expect(followAllButton(wrapper).attributes('disabled')).toBeDefined()
		expect(followAllButton(wrapper).text()).toBe('Follow everyone')
	})

	it('can put the focus on its name', () => {
		const wrapper = mount(StarterPackDetail, {
			props: { pack: pack([]), followMembers: vi.fn(), followMember: vi.fn() },
			attachTo: document.body,
			global: { stubs: { ActorAvatar: true, NcDialog: NcDialogStub } },
		})

		wrapper.vm.focusHeading()

		expect(document.activeElement).toBe(wrapper.find('h1').element)
		wrapper.unmount()
	})
})
