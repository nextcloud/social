/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import QuotedPost from '../../../src/components/QuotedPost.vue'

const bob = {
	id: '2',
	acct: 'bob@remote.example',
	username: 'bob',
	display_name: 'Bob',
	url: 'https://remote.example/@bob',
	avatar: 'https://remote.example/avatar.png',
	emojis: [],
}

function quoted(overrides = {}) {
	return {
		id: '77',
		url: 'https://remote.example/@bob/77',
		content: '<p>The post being quoted</p>',
		visibility: 'public',
		mentions: [],
		tags: [],
		emojis: [],
		account: bob,
		quote: null,
		...overrides,
	}
}

function mountQuote(quote) {
	return mount(QuotedPost, {
		props: { quote },
		global: {
			stubs: {
				ActorAvatar: true,
				RouterLink: RouterLinkStub,
			},
		},
	})
}

const card = (wrapper) => wrapper.find('.quoted-post')
const notice = (wrapper) => wrapper.find('.quoted-post__notice')
function openLink(wrapper) {
	return wrapper.findAllComponents(RouterLinkStub)
		.find((link) => link.classes().includes('quoted-post__open'))
}

describe('QuotedPost', () => {
	describe('an accepted quote', () => {
		it('shows the quoted post as a card of its own', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted() })

			expect(card(wrapper).exists()).toBe(true)
			expect(wrapper.find('.quoted-post__author').text()).toBe('Bob')
			expect(wrapper.find('.quoted-post__handle').text()).toBe('@bob@remote.example')
			expect(wrapper.find('.quoted-post__message').text()).toContain('The post being quoted')
			expect(notice(wrapper).exists()).toBe(false)
		})

		it('shows the quoted author with their own avatar', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted() })

			expect(wrapper.findComponent({ name: 'ActorAvatar' }).props('actor')).toEqual(bob)
		})

		it('is a quotation in the markup, not merely in the styling', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted() })

			expect(card(wrapper).element.tagName).toBe('BLOCKQUOTE')
			expect(card(wrapper).attributes('cite')).toBe('https://remote.example/@bob/77')
		})

		it('can be followed to the quoted post and to its author', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted() })

			// links, not click handlers on a div: the card has to be reachable
			// by keyboard like anything else in the timeline
			expect(openLink(wrapper).props('to')).toEqual({
				name: 'single-post',
				params: { account: 'bob@remote.example', id: '77', type: 'single-post' },
			})
			expect(wrapper.find('.quoted-post__author-link').exists()).toBe(true)
			expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
				name: 'profile',
				params: { account: 'bob@remote.example' },
			})
		})

		it('renders the quoted content rather than injecting it', () => {
			const wrapper = mountQuote({
				state: 'accepted',
				quoted_status: quoted({ content: '<p>look <img src="x" onerror="alert(1)"> out</p>' }),
			})

			expect(wrapper.find('.quoted-post__message').element.querySelector('img')).toBeNull()
			expect(wrapper.find('.quoted-post__message').text()).toContain('look')
		})
	})

	describe('a quote with nothing to show', () => {
		it('says that a pending quote is waiting for the author', () => {
			const wrapper = mountQuote({ state: 'pending', quoted_status: null })

			expect(notice(wrapper).text()).toBe('This quote is waiting for the quoted author to approve it.')
			expect(wrapper.find('.quoted-post__message').exists()).toBe(false)
		})

		it('says that the author refused', () => {
			const wrapper = mountQuote({ state: 'rejected', quoted_status: null })

			expect(notice(wrapper).text()).toBe('The author of the quoted post did not allow this quote.')
		})

		it('says that the author took the permission back', () => {
			const wrapper = mountQuote({ state: 'revoked', quoted_status: null })

			expect(notice(wrapper).text()).toBe('The author of the quoted post withdrew their permission for this quote.')
		})

		it('says nothing more than "not available" when the reader may not read it', () => {
			// accepted, but the post did not come with it: the reader is not
			// allowed to see it, which is not an error and not a refusal either
			const wrapper = mountQuote({ state: 'accepted', quoted_status: null })

			expect(notice(wrapper).text()).toBe('The quoted post is not available.')
		})

		it('treats a state it does not know as unavailable', () => {
			const wrapper = mountQuote({ state: 'something-newer', quoted_status: null })

			expect(notice(wrapper).text()).toBe('The quoted post is not available.')
		})

		it('does not show a post that came with a quote still waiting for approval', () => {
			const wrapper = mountQuote({ state: 'pending', quoted_status: quoted() })

			expect(notice(wrapper).text()).toBe('This quote is waiting for the quoted author to approve it.')
			expect(wrapper.find('.quoted-post__message').exists()).toBe(false)
		})

		it('does not render half a card for a quoted post without an author', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted({ account: undefined }) })

			expect(notice(wrapper).text()).toBe('The quoted post is not available.')
			expect(wrapper.find('.quoted-post__message').exists()).toBe(false)
		})

		it('renders nothing at all for a post that quotes nothing', () => {
			expect(mountQuote(null).find('.quoted-post').exists()).toBe(false)
		})
	})

	describe('a chain of quotes', () => {
		it('goes one level deep and says that there is more', () => {
			const wrapper = mountQuote({
				state: 'accepted',
				quoted_status: quoted({
					quote: {
						state: 'accepted',
						quoted_status: quoted({ id: '78', content: '<p>The post at the bottom</p>' }),
					},
				}),
			})

			expect(wrapper.findAll('.quoted-post')).toHaveLength(1)
			expect(wrapper.text()).not.toContain('The post at the bottom')
			expect(wrapper.find('.quoted-post__deeper').text()).toBe('The quoted post quotes another post.')
		})

		it('does not follow a quote that points back at its own quoter', () => {
			const loop = { state: 'accepted', quoted_status: quoted() }
			loop.quoted_status.quote = loop

			expect(() => mountQuote(loop)).not.toThrow()
			expect(mountQuote(loop).findAll('.quoted-post')).toHaveLength(1)
		})

		it('says nothing about a deeper quote when the post quotes nothing', () => {
			const wrapper = mountQuote({ state: 'accepted', quoted_status: quoted() })

			expect(wrapper.find('.quoted-post__deeper').exists()).toBe(false)
		})
	})
})
