/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ComposerPreview from '../../../src/components/Composer/ComposerPreview.vue'

/**
 * @param {object} props the text as typed, and any content warning
 * @return {object} the mounted panel
 */
function mountPreview(props = {}) {
	return mount(ComposerPreview, {
		props: { text: '', ...props },
		global: { stubs: { NcButton: { template: '<button><slot /></button>' } } },
	})
}

describe('the composer preview', () => {
	it('says there is nothing to show until something is typed', () => {
		const wrapper = mountPreview()

		expect(wrapper.find('.composer-preview__empty').text()).toBe('Nothing written yet.')
		expect(wrapper.find('.composer-preview__body').exists()).toBe(false)
	})

	it('marks up the mentions, hashtags and links it finds', () => {
		const wrapper = mountPreview({ text: 'Hi @jens@chaos.social see #birds at https://example.org/b' })

		const kinds = wrapper.findAll('.composer-preview__entity')
			.map((entity) => entity.classes().find((name) => name.includes('--')))
		expect(kinds).toEqual([
			'composer-preview__entity--mention',
			'composer-preview__entity--hashtag',
			'composer-preview__entity--url',
		])
	})

	/**
	 * The point of the panel: it is the text as it will be published, so a
	 * post that is all angle brackets shows the angle brackets rather than
	 * swallowing them as markup.
	 */
	it('shows the text as text, never as markup', () => {
		const wrapper = mountPreview({ text: 'a <b>bold</b> claim' })

		expect(wrapper.find('.composer-preview__body').text()).toBe('a <b>bold</b> claim')
		expect(wrapper.find('.composer-preview__body').html()).not.toContain('<b>bold</b>')
	})

	/** `pre-wrap` means a newline in the markup would be a space in the post. */
	it('keeps the text exactly as typed, spacing included', () => {
		const text = 'one  two\nthree #four '

		// textContent, not text(): the helper trims, and trailing space is the point
		expect(mountPreview({ text }).find('.composer-preview__body').element.textContent).toBe(text)
	})

	describe('what it says was found', () => {
		/** "No hashtags" is the answer to why a post never reached a tag timeline. */
		it('says so when nothing was found, rather than saying nothing', () => {
			expect(mountPreview({ text: 'just words' }).find('.composer-preview__note').text())
				.toBe('No mentions, hashtags or links yet.')
		})

		it('counts each kind it found', () => {
			const wrapper = mountPreview({
				text: '@a@b.social @c@d.social #one #two #three https://example.org',
			})

			expect(wrapper.find('.composer-preview__note').text())
				.toBe('Will be published with 2 mentions, 3 hashtags, 1 link.')
		})

		it('leaves out the kinds it did not find', () => {
			expect(mountPreview({ text: '#birds' }).find('.composer-preview__note').text())
				.toBe('Will be published with 1 hashtag.')
		})
	})

	it('shows the content warning above the post it hides', () => {
		const wrapper = mountPreview({ text: 'the spoiler', warning: 'Season finale' })

		expect(wrapper.find('.composer-preview__warning').text()).toBe('Season finale')
	})

	it('has no warning line when the post carries none', () => {
		expect(mountPreview({ text: 'x' }).find('.composer-preview__warning').exists()).toBe(false)
	})

	/** The way out of a panel belongs on the panel. */
	it('can be dismissed from inside itself', async () => {
		const wrapper = mountPreview({ text: 'x' })

		await wrapper.find('.composer-preview__close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/**
	 * It rewrites itself as the writer types, under the field they are typing
	 * in — polite, so it is read at a pause rather than over every keystroke.
	 */
	it('is announced politely rather than interrupting', () => {
		expect(mountPreview().find('.composer-preview').attributes('aria-live')).toBe('polite')
	})

	it('follows the text as it is typed', async () => {
		const wrapper = mountPreview({ text: 'nothing here' })

		await wrapper.setProps({ text: 'now #something' })

		expect(wrapper.findAll('.composer-preview__entity--hashtag')).toHaveLength(1)
		expect(wrapper.find('.composer-preview__note').text()).toContain('1 hashtag')
	})
})
