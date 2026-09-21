/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'

import PersonCard from '../../../src/components/PersonCard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

/**
 * @param {object} account an Account entity, as a directory or the API sends one
 * @param {object} props the rest of what the row is told
 * @return {object} the mounted row
 */
function mountCard(account = {}, props = {}) {
	return mount(PersonCard, {
		props: {
			account: { acct: 'jens@chaos.social', username: 'jens', ...account },
			...props,
		},
		global: {
			stubs: { RouterLink: RouterLinkStub, ActorAvatar: true, NcButton: true, NcLoadingIcon: true },
		},
	})
}

describe('PersonCard', () => {
	it('shows the name over the handle', () => {
		const wrapper = mountCard({ display_name: 'Jens' })

		expect(wrapper.find('.person__name').text()).toContain('Jens')
		expect(wrapper.find('.person__handle').text()).toBe('@jens@chaos.social')
	})

	it('falls back to the username when there is no display name', () => {
		expect(mountCard({ display_name: '' }).find('.person__name').text()).toContain('jens')
	})

	it('marks an automated account quietly', () => {
		expect(mountCard({ bot: true }).find('.person__bot').text()).toBe('bot')
		expect(mountCard({ bot: false }).find('.person__bot').exists()).toBe(false)
	})

	describe('where the row points', () => {
		/** Following a profile link here for somebody nobody knows is a 404. */
		it('sends a stranger to their own server, in a new tab', () => {
			const wrapper = mountCard({ url: 'https://chaos.social/@jens' })

			const link = wrapper.find('.person__link')
			expect(link.attributes('href')).toBe('https://chaos.social/@jens')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toBe('noopener noreferrer')
		})

		it('sends somebody this server knows to the profile it holds', () => {
			const wrapper = mountCard({}, { link: true })

			expect(wrapper.findComponent(RouterLinkStub).props('to'))
				.toEqual({ name: 'profile', params: { account: 'jens@chaos.social' } })
		})
	})

	describe('the picture', () => {
		it('uses the absolute one a directory handed over', () => {
			const wrapper = mountCard({ avatar: 'https://chaos.social/avatars/jens.png' })

			expect(wrapper.find('img.person__avatar').attributes('src'))
				.toBe('https://chaos.social/avatars/jens.png')
		})

		/**
		 * An account *on* this Nextcloud with no picture of its own: Nextcloud
		 * generates one, and the avatar component knows how to ask for it.
		 */
		it('lets the avatar component draw an account on this server', () => {
			const wrapper = mountCard({ acct: 'alice', avatar: '' })

			expect(wrapper.find('img.person__avatar').exists()).toBe(false)
			expect(wrapper.findComponent({ name: 'ActorAvatar' }).exists()).toBe(true)
		})

		/**
		 * A local account's `avatar` points at this server's own cache, and
		 * `ActorAvatar` builds a better URL for it than the row was handed.
		 */
		it('does not use the handed-over URL for an account it can link to', () => {
			const wrapper = mountCard({ avatar: 'https://cloud.example/avatar' }, { link: true })

			expect(wrapper.find('img.person__avatar').exists()).toBe(false)
		})

		/**
		 * A remote account with no picture has only an address that answers
		 * 404, which the avatar component draws as a question mark — and a
		 * list full of those says nothing.
		 */
		it('gives a remote account with no picture a letter instead', () => {
			const wrapper = mountCard({ avatar: '', display_name: 'Jens' })

			expect(wrapper.find('.person__avatar--blank').text()).toBe('J')
		})

		it('falls back to a letter when the picture will not load', async () => {
			const wrapper = mountCard({ avatar: 'https://chaos.social/gone.png', display_name: 'Jens' })

			await wrapper.find('img.person__avatar').trigger('error')

			expect(wrapper.find('img.person__avatar').exists()).toBe(false)
			expect(wrapper.find('.person__avatar--blank').text()).toBe('J')
		})

		it('takes the first letter of a name that starts with an emoji', () => {
			expect(mountCard({ avatar: '', display_name: '🐙 Jens' }).find('.person__avatar--blank').text())
				.toBe('🐙')
		})
	})

	describe('the bio', () => {
		/**
		 * `note` is HTML written on somebody else's server. Rendering it would
		 * put a stranger's markup in a list; printing it raw put `<p>` in front
		 * of every bio on the page.
		 */
		it('is shown as text, with the markup taken out', () => {
			const wrapper = mountCard({ note: '<p>Writes about <b>birds</b></p>' })

			expect(wrapper.text()).toContain('Writes about birds')
			expect(wrapper.html()).not.toContain('<b>')
		})

		it('decodes the entities a bio actually carries', () => {
			expect(mountCard({ note: '<p>Tea &amp; cake</p>' }).text()).toContain('Tea & cake')
		})

		it('says nothing when there is no bio', () => {
			expect(mountCard({ note: '' }).find('.person__summary').exists()).toBe(false)
		})
	})

	/** A row on a discovery page has to say why the person is on it. */
	it('says why the person is being shown, when it is told', () => {
		expect(mountCard({}, { reason: 'Followed by three people you follow' }).text())
			.toContain('Followed by three people you follow')
	})

	it('asks to follow when the button is pressed', async () => {
		const wrapper = mountCard()

		wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('follow')).toBeTruthy()
	})
})
