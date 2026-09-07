/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import MessageContent from '../../../src/components/MessageContent.js'

const Empty = { template: '<div />' }

/**
 * The named routes MessageContent links to, with the paths of src/router.js,
 * so the rendered <a href> is what the app would navigate to.
 */
const makeRouter = () => createRouter({
	history: createMemoryHistory('/apps/social/'),
	routes: [
		{ path: '/', component: Empty },
		{
			path: '/timeline/:type?',
			name: 'timeline',
			component: Empty,
			children: [{ path: 'tags/:tag', name: 'tags', component: Empty }],
		},
		{ path: '/@:account', name: 'profile', component: Empty },
	],
})

const mountContent = (content, extra = {}) => mount(MessageContent, {
	props: { item: { content, mentions: [], ...extra } },
	global: { plugins: [makeRouter()] },
})

describe('MessageContent', () => {
	describe('formatting', () => {
		it('keeps the structural markup Mastodon sends', () => {
			const wrapper = mountContent('<p>Hello <strong>world</strong><br>and <em>more</em></p>'
				+ '<blockquote><p>quoted</p></blockquote><ul><li>one</li></ul><pre><code>run()</code></pre>')

			expect(wrapper.find('p strong').text()).toBe('world')
			expect(wrapper.find('p br').exists()).toBe(true)
			expect(wrapper.find('p em').text()).toBe('more')
			expect(wrapper.find('blockquote p').text()).toBe('quoted')
			expect(wrapper.find('ul li').text()).toBe('one')
			expect(wrapper.find('pre code').text()).toBe('run()')
		})

		it('drops every attribute from structural elements', () => {
			const wrapper = mountContent('<p class="x" style="position:fixed" onclick="alert(1)">text</p>')
			expect(wrapper.find('p').attributes()).toEqual({})
			expect(wrapper.find('p').text()).toBe('text')
		})

		it('reduces unknown elements to their text', () => {
			const wrapper = mountContent('<p><font color="red">red</font> <mark data-x="1">marked</mark></p>')
			expect(wrapper.find('font').exists()).toBe(false)
			expect(wrapper.find('mark').exists()).toBe(false)
			expect(wrapper.html()).not.toContain('data-x')
			expect(wrapper.find('p').text()).toBe('red marked')
		})
	})

	describe('links', () => {
		it('opens ordinary links in a new tab without referrer or opener', () => {
			const wrapper = mountContent('<p>see <a href="https://example.org/x" onclick="alert(1)">https://example.org/x</a></p>')

			const link = wrapper.find('a')
			expect(link.attributes()).toEqual({
				href: 'https://example.org/x',
				rel: 'nofollow noopener noreferrer',
				target: '_blank',
			})
			expect(link.text()).toBe('https://example.org/x')
		})

		it('renders a dead link for script-capable URL schemes', () => {
			const wrapper = mountContent('<p><a href="javascript:alert(1)">click</a> <a href="data:text/html,x">data</a></p>')

			const links = wrapper.findAll('a')
			expect(links).toHaveLength(2)
			for (const link of links) {
				expect(link.attributes('href')).toBeUndefined()
				expect(link.attributes('target')).toBe('_blank')
			}
			expect(wrapper.html()).not.toContain('javascript:')
		})

		it('does not leave plain URLs in text clickable', () => {
			const wrapper = mountContent('<p>go to https://example.org now</p>')
			expect(wrapper.find('a').exists()).toBe(false)
			expect(wrapper.text()).toBe('go to https://example.org now')
		})
	})

	describe('hashtags', () => {
		it('turns a Mastodon hashtag anchor into a link to the tag timeline', () => {
			const wrapper = mountContent('<p>about <a href="https://mastodon.example/tags/nextcloud" class="mention hashtag" rel="tag">#<span>nextcloud</span></a></p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/timeline/tags/nextcloud')
			expect(link.attributes('rel')).toBeUndefined()
			expect(link.text()).toBe('#nextcloud')
		})

		it('links a hashtag written in plain text', () => {
			const wrapper = mountContent('<p>Loving the #Fediverse today</p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/timeline/tags/Fediverse')
			expect(link.text()).toBe('#Fediverse')
			expect(wrapper.text()).toBe('Loving the #Fediverse today')
		})
	})

	describe('mentions', () => {
		it('links a plain-text handle to the profile, showing only the short name', () => {
			const wrapper = mountContent('<p>cc @carol@other.example thanks</p>')

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('/apps/social/@carol@other.example')
			expect(link.text()).toBe('@carol')
			expect(wrapper.text()).toBe('cc @carol thanks')
		})

		it('keeps the remote profile link of a mention listed in the status mentions', () => {
			const wrapper = mountContent(
				'<p><a href="https://remote.example/@bob" class="u-url mention">bob@remote.example</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)

			const link = wrapper.find('a')
			expect(link.attributes('href')).toBe('https://remote.example/@bob')
			expect(link.attributes('rel')).toBe('nofollow noopener noreferrer')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.text()).toBe('bob@remote.example')
		})

		it('refuses an unsafe URL on a listed mention', () => {
			const wrapper = mountContent(
				'<p><a href="javascript:alert(1)" class="mention">bob@remote.example</a> hi</p>',
				{ mentions: [{ id: '9', username: 'bob', acct: 'bob@remote.example', url: 'https://remote.example/@bob' }] },
			)
			expect(wrapper.find('a').attributes('href')).toBeUndefined()
		})

		it('reduces a mention anchor that is not among the status mentions to text', () => {
			const wrapper = mountContent('<p><a href="https://evil.example/@bob" class="mention">@bob</a> hi</p>')
			expect(wrapper.find('a').exists()).toBe(false)
			expect(wrapper.text()).toBe('@bob hi')
		})
	})

	describe('emoji', () => {
		it('replaces an emoji sequence by its twemoji image', () => {
			const wrapper = mountContent('<p>Hi 🧑‍🤝‍🧑 there</p>')

			const emoji = wrapper.find('img.emoji')
			expect(emoji.attributes('alt')).toBe('🧑‍🤝‍🧑')
			expect(emoji.attributes('src')).toBe('/apps/social/img/twemoji/1f9d1-200d-1f91d-200d-1f9d1.svg')
			expect(emoji.attributes('draggable')).toBe('false')
			expect(wrapper.text()).toBe('Hi  there')
		})
	})

	describe('hostile markup', () => {
		it('never renders script or image elements from the content', () => {
			const wrapper = mountContent('<p>a<script>alert(1)</script><img src="x" onerror="alert(1)">b</p>')

			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('img').exists()).toBe(false)
			expect(wrapper.html()).not.toContain('onerror')
		})

		it('renders an empty wrapper for an empty status', () => {
			const wrapper = mountContent('')
			expect(wrapper.element.tagName).toBe('DIV')
			expect(wrapper.text()).toBe('')
		})
	})
})
