/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import SwitchWizard from '../../../src/views/SwitchWizard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))

const ANNOUNCEMENT = {
	handle: '@alice@cloud.example',
	url: 'https://cloud.example/apps/social/@alice',
	text: 'I have moved to the fediverse. You can follow me at @alice@cloud.example',
}

async function mountWizard() {
	axios.get.mockResolvedValue({ data: ANNOUNCEMENT })
	const wrapper = mount(SwitchWizard, {
		global: {
			stubs: { NcLoadingIcon: true, RouterLink: true },
		},
	})
	await flushPromises()

	return wrapper
}

async function pick(wrapper, name) {
	const button = wrapper.findAll('.networks__item').find((b) => b.text().includes(name))
	await button.trigger('click')
	await flushPromises()
}

describe('SwitchWizard', () => {
	beforeEach(() => {
		// jsdom has no canvas; the card is drawn on every choice and must not
		// take the page down with it when it cannot be
		HTMLCanvasElement.prototype.getContext = vi.fn(() => null)
	})

	afterEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('offers the four networks people arrive from', async () => {
		const wrapper = await mountWizard()

		const names = wrapper.findAll('.networks__name').map((one) => one.text())
		expect(names).toEqual(['X', 'Instagram', 'TikTok', 'YouTube'])
	})

	it('says nothing about the steps until a network is picked', async () => {
		const wrapper = await mountWizard()

		expect(wrapper.text()).not.toContain('Bring your posts')
	})

	/**
	 * The follow list is the step that decides whether the account is opened a
	 * second time, and it can only be done for the one network whose names are
	 * also fediverse names.
	 */
	it('offers to find people only for Instagram', async () => {
		const wrapper = await mountWizard()

		await pick(wrapper, 'Instagram')
		expect(wrapper.text()).toContain('Find the people you followed')

		await pick(wrapper, 'X')
		expect(wrapper.text()).not.toContain('Find the people you followed')
	})

	/** Three of the four federate nothing, and the page says so rather than implying otherwise. */
	it('says plainly when an archive cannot be read for posts', async () => {
		const wrapper = await mountWizard()

		await pick(wrapper, 'TikTok')

		expect(wrapper.text()).toContain('cannot be imported from the archive yet')
		expect(wrapper.find('input[type="file"]').exists()).toBe(false)
	})

	it('sends the archive to the post importer and says what came over', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'X')
		axios.post.mockResolvedValue({ data: { imported: 412, capped: false } })

		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['zip'], 'twitter.zip')],
			configurable: true,
		})
		await input.trigger('change')
		await flushPromises()

		expect(axios.post.mock.calls[0][0]).toContain('/migration/posts')
		expect(wrapper.text()).toContain('Brought over 412 posts.')
	})

	/**
	 * The cursor moves by what the server says it checked. Trusting a batch
	 * size agreed on this side is how a loop like this ends up asking about
	 * the same names forever.
	 */
	it('walks the follow list in the batches the server decides', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'Instagram')

		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['zip'], 'instagram.zip')],
			configurable: true,
		})
		axios.post.mockResolvedValue({ data: { imported: 0 } })
		await input.trigger('change')
		await flushPromises()

		axios.post.mockImplementation((url) => {
			if (url.includes('/migration/people/find')) {
				return Promise.resolve({
					data: { checked: 2, found: [{ handle: 'someone@threads.net', name: 'Someone' }] },
				})
			}

			return Promise.resolve({
				data: { handles: ['a@threads.net', 'b@threads.net', 'c@threads.net'] },
			})
		})

		await wrapper.findAll('button').find((b) => b.text().includes('Read the list')).trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Looked up 2 names so far')
		expect(wrapper.text()).toContain('1 left')
		expect(wrapper.find('.people__handle').text()).toBe('@someone@threads.net')
	})

	/**
	 * A server that answers `checked: 0` — a batch of names none of which was
	 * a handle — must still end the walk rather than leave the button offering
	 * the same names for ever.
	 */
	it('stops walking when a batch checks nothing', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'Instagram')
		wrapper.vm.candidates = ['not-a-handle']
		axios.post.mockResolvedValue({ data: { checked: 0, found: [] } })

		await wrapper.vm.probeMore()

		expect(wrapper.vm.probed).toBe(1)
	})

	it('follows the ticked accounts through the follows importer', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'Instagram')
		wrapper.vm.candidates = ['someone@threads.net']
		wrapper.vm.found = [{ handle: 'someone@threads.net', name: 'Someone' }]
		wrapper.vm.selected = ['someone@threads.net']
		axios.post.mockResolvedValue({ data: { followed: 1 } })

		await wrapper.vm.followSelected()

		const [url, body] = axios.post.mock.calls.at(-1)
		expect(url).toContain('/migration/follows')
		expect(body.get('file')).toBeInstanceOf(Blob)
	})

	it('shows the words to post on the network being left', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'X')

		expect(wrapper.find('.switch__announce').text()).toBe(ANNOUNCEMENT.text)
	})

	describe('the card', () => {
		let drawn

		beforeEach(() => {
			drawn = []
			const ctx = {
				canvas: { width: 1200, height: 675 },
				font: '400 10px sans-serif',
				createLinearGradient: () => ({ addColorStop: () => {} }),
				fillRect: () => {},
				measureText: () => ({ width: 10 }),
				fillText: (text) => drawn.push(text),
			}
			HTMLCanvasElement.prototype.getContext = vi.fn(() => ctx)
		})

		it('is redrawn with the account once the announcement arrives after the network was picked', async () => {
			let answer
			axios.get.mockReturnValue(new Promise((resolve) => {
				answer = resolve
			}))
			const wrapper = mount(SwitchWizard, { global: { stubs: { NcLoadingIcon: true, RouterLink: true } } })
			await pick(wrapper, 'X')
			expect(drawn).toContain('@you')

			drawn = []
			answer({ data: ANNOUNCEMENT })
			await flushPromises()

			expect(drawn).toContain(ANNOUNCEMENT.handle)
			expect(drawn).toContain(ANNOUNCEMENT.url)
		})

		it('is announced as a picture', async () => {
			const wrapper = await mountWizard()
			await pick(wrapper, 'X')

			expect(wrapper.find('canvas').attributes('role')).toBe('img')
		})
	})

	it('says so when the announcement cannot be read, and offers nothing empty to save or copy', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const wrapper = mount(SwitchWizard, { global: { stubs: { NcLoadingIcon: true, RouterLink: true } } })
		await flushPromises()
		await pick(wrapper, 'X')

		expect(wrapper.find('[role="alert"]').text()).toContain('could not be read')
		const buttons = wrapper.findAll('.switch__buttons button').filter((b) => /Save the card|Copy the words/.test(b.text()))
		expect(buttons).toHaveLength(2)
		for (const button of buttons) {
			expect(button.attributes('disabled')).toBeDefined()
		}

		axios.get.mockResolvedValue({ data: ANNOUNCEMENT })
		await wrapper.findAll('button').find((b) => b.text() === 'Try again').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.switch__announce').text()).toBe(ANNOUNCEMENT.text)
	})

	it('goes back to "Copy the words" a moment after copying them', async () => {
		vi.useFakeTimers()
		try {
			Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true })
			const wrapper = await mountWizard()
			await pick(wrapper, 'X')
			const copy = () => wrapper.findAll('.switch__buttons button').find((b) => /Copy the words|Copied/.test(b.text()))

			await copy().trigger('click')
			await flushPromises()
			expect(copy().text()).toBe('Copied')

			vi.advanceTimersByTime(3000)
			await flushPromises()
			expect(copy().text()).toBe('Copy the words')
		} finally {
			vi.useRealTimers()
		}
	})

	it('forgets what was read for one network when another is picked', async () => {
		const wrapper = await mountWizard()
		await pick(wrapper, 'Instagram')
		wrapper.vm.candidates = ['bob@threads.net']
		wrapper.vm.found = [{ handle: 'bob@threads.net' }]
		wrapper.vm.selected = ['bob@threads.net']
		wrapper.vm.probed = 1

		await pick(wrapper, 'X')

		expect(wrapper.vm.candidates).toEqual([])
		expect(wrapper.vm.found).toEqual([])
		expect(wrapper.vm.selected).toEqual([])
		expect(wrapper.vm.probed).toBe(0)
	})
})
