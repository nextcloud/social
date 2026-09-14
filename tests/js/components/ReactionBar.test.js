/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ReactionBar from '../../../src/components/ReactionBar.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const stubs = {
	// the real one is an async chunk pulled in on first use; it stands in here
	NcEmojiPicker: { name: 'NcEmojiPicker', template: '<div class="emoji-picker-stub"><slot /></div>' },
	NcLoadingIcon: true,
}

/**
 * @param {object} props what the card passes down
 * @return {object} the mounted bar
 */
function mountBar(props = {}) {
	return mount(ReactionBar, {
		props: { nid: 7, modelValue: [], canReact: true, ...props },
		global: { stubs },
	})
}

const bar = [
	{ name: '👍', count: 2, me: false },
	{ name: '🎉', count: 1, me: true },
]

describe('ReactionBar', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('draws one chip per emoji, with its count', () => {
		const wrapper = mountBar({ modelValue: bar })
		const chips = wrapper.findAll('.reaction:not(.reaction--add)')

		expect(chips).toHaveLength(2)
		expect(chips[0].find('.reaction__emoji').text()).toBe('👍')
		expect(chips[0].find('.reaction__count').text()).toBe('2')
	})

	it('marks the ones the reader chose', () => {
		const wrapper = mountBar({ modelValue: bar })
		const chips = wrapper.findAll('.reaction:not(.reaction--add)')

		expect(chips[0].classes()).not.toContain('reaction--mine')
		expect(chips[1].classes()).toContain('reaction--mine')
		expect(chips[1].attributes('aria-pressed')).toBe('true')
	})

	/**
	 * The emoji is hidden from the accessibility tree: read aloud it is a name
	 * that may mean nothing here, and the useful part is the count and whether
	 * pressing takes the reader's own back.
	 */
	it('says what a press will do, in words', () => {
		const wrapper = mountBar({ modelValue: bar })
		const chips = wrapper.findAll('.reaction:not(.reaction--add)')

		expect(chips[0].attributes('aria-label')).toBe('React with 👍 (2 reactions)')
		expect(chips[1].attributes('aria-label')).toBe('Remove your 🎉 reaction (1 reaction)')
		expect(chips[0].find('.reaction__emoji').attributes('aria-hidden')).toBe('true')
	})

	it('adds a reaction the reader has not used', async () => {
		axios.post.mockResolvedValue({ data: { reactions: [{ name: '👍', count: 3, me: true }] } })
		const wrapper = mountBar({ modelValue: bar })

		await wrapper.findAll('.reaction:not(.reaction--add)')[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/statuses/7/react'),
			{ emoji: '👍' },
		)
	})

	it('takes back one the reader already used', async () => {
		axios.post.mockResolvedValue({ data: { reactions: [] } })
		const wrapper = mountBar({ modelValue: bar })

		await wrapper.findAll('.reaction:not(.reaction--add)')[1].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/statuses/7/unreact'),
			{ emoji: '🎉' },
		)
	})

	/**
	 * The bar the server rebuilt is what goes up, not a local guess: a
	 * reaction is a federated write that can be refused, and a count that
	 * jumped and then jumped back is worse than one that waits a moment.
	 */
	it('hands the card the bar the server answered with', async () => {
		const answered = [{ name: '👍', count: 3, me: true }]
		axios.post.mockResolvedValue({ data: { reactions: answered } })
		const wrapper = mountBar({ modelValue: bar })

		await wrapper.findAll('.reaction:not(.reaction--add)')[0].trigger('click')
		await flushPromises()

		expect(wrapper.emitted('update:modelValue')).toEqual([[answered]])
	})

	it('changes nothing when the server refuses', async () => {
		axios.post.mockRejectedValue(new Error('nope'))
		const wrapper = mountBar({ modelValue: bar })

		await wrapper.findAll('.reaction:not(.reaction--add)')[0].trigger('click')
		await flushPromises()

		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('sends one press at a time', async () => {
		let settle
		axios.post.mockReturnValue(new Promise((resolve) => {
			settle = () => resolve({ data: { reactions: [] } })
		}))
		const wrapper = mountBar({ modelValue: bar })

		await wrapper.findAll('.reaction:not(.reaction--add)')[0].trigger('click')
		await wrapper.findAll('.reaction:not(.reaction--add)')[1].trigger('click')

		expect(axios.post).toHaveBeenCalledTimes(1)
		settle()
		await flushPromises()
	})

	describe('a reader who may not react', () => {
		it('still sees the reactions', () => {
			const wrapper = mountBar({ modelValue: bar, canReact: false })

			expect(wrapper.findAll('.reaction:not(.reaction--add)')).toHaveLength(2)
		})

		it('is offered nothing to press', async () => {
			const wrapper = mountBar({ modelValue: bar, canReact: false })

			expect(wrapper.find('.reaction--add').exists()).toBe(false)
			await wrapper.findAll('.reaction:not(.reaction--add)')[0].trigger('click')
			expect(axios.post).not.toHaveBeenCalled()
		})
	})

	it('draws nothing at all for a post with no reactions that cannot take one', () => {
		const wrapper = mountBar({ modelValue: [], canReact: false })

		expect(wrapper.find('.reaction-bar').exists()).toBe(false)
	})

	it('offers a way in on a post with no reactions yet', () => {
		const wrapper = mountBar({ modelValue: [] })

		expect(wrapper.find('.reaction--add').exists()).toBe(true)
	})

	it('copes with a server that sent no reactions key at all', () => {
		const wrapper = mountBar({ modelValue: undefined })

		expect(wrapper.findAll('.reaction:not(.reaction--add)')).toHaveLength(0)
	})
})
