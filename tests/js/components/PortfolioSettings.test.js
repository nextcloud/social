/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import PortfolioSettings from '../../../src/components/PortfolioSettings.vue'
import { showError, showSuccess } from '../../../src/services/toast.js'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const PORTFOLIO = '/index.php/apps/social/api/v1.1/portfolio'
const COLLECTIONS = '/index.php/apps/social/api/v1.1/collections/self'

/**
 * @param {object} answers what the two reads answer with
 * @param {object} [answers.portfolio] the page as it stands
 * @param {object[]} [answers.collections] the reader's collections
 * @param {boolean} [answers.signedIn] whether the store knows who the reader is
 * @return {Promise<object>} the mounted editor, once both reads have settled
 */
async function mountEditor({ portfolio = {}, collections = [], signedIn = true } = {}) {
	axios.get.mockImplementation((url) => (url === COLLECTIONS
		? Promise.resolve({ data: collections })
		: Promise.resolve({ data: portfolio })))

	const pinia = createPinia()
	setActivePinia(pinia)
	if (signedIn) {
		const store = useAccountStore()
		store.accounts = {
			'https://cloud.example/users/alice': {
				id: '1',
				acct: 'alice',
				display_name: 'Alice Appleby',
			},
		}
		store.accountIdMap = { alice: 'https://cloud.example/users/alice' }
		store.currentAccountHandle = 'alice'
	}

	const wrapper = mount(PortfolioSettings, { global: { plugins: [pinia] } })
	await flushPromises()

	return wrapper
}

const saveButton = (wrapper) => wrapper.findComponent({ name: 'NcButton' })
const saved = () => axios.post.mock.calls[0][1]
function switchFor(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((box) => box.text() === label)
}

/**
 * The editor for somebody's page of work. Everything on it is one form saved in
 * one request, because the page it describes is one page.
 */
describe('the portfolio editor', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: {} })
	})

	it('waits rather than showing an empty form it is about to fill', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(PortfolioSettings, { global: { plugins: [pinia] } })

		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(saveButton(wrapper).exists()).toBe(false)
	})

	/** A page nobody asked to publish is a draft, and starts as one. */
	it('starts unpublished, with the defaults the page is drawn at', async () => {
		const wrapper = await mountEditor()

		expect(switchFor(wrapper, 'Publish my portfolio').props('modelValue')).toBe(false)
		expect(wrapper.vm.form).toMatchObject({
			layout: 'grid',
			source: 'recent',
			showCaptions: true,
			showPlaces: true,
			showDates: false,
			showAvatar: true,
		})
	})

	it('shows the page as the server has it', async () => {
		const wrapper = await mountEditor({
			portfolio: {
				active: true,
				title: 'Concrete',
				intro: 'Ten years of stairwells',
				layout: 'rows',
				source: 'collection',
				collection_id: '7',
				show_captions: false,
				show_places: false,
				show_dates: true,
				show_avatar: false,
			},
			collections: [{ id: '7', title: 'Stairwells' }],
		})

		expect(wrapper.vm.form).toMatchObject({
			active: true,
			title: 'Concrete',
			intro: 'Ten years of stairwells',
			layout: 'rows',
			source: 'collection',
			showCaptions: false,
			showPlaces: false,
			showDates: true,
			showAvatar: false,
		})
		expect(wrapper.vm.chosenCollection).toEqual({ id: 7, title: 'Stairwells' })
	})

	/** The switch is the whole difference between a draft and a public page. */
	it('says plainly what publishing means', async () => {
		const hint = (await mountEditor()).find('.portfolio-settings__hint').text()

		expect(hint).toContain('a draft only you can see')
		expect(hint).toContain('anybody with the link can read it without signing in')
	})

	describe('the address to hand somebody', () => {
		it('is shown once the page is published', async () => {
			const wrapper = await mountEditor({ portfolio: { active: true } })

			expect(wrapper.find('.portfolio-settings__url a').text())
				.toContain('/apps/social/@alice/portfolio')
		})

		/** There is no address to hand anybody while it is still a draft. */
		it('is not shown while it is a draft', async () => {
			const wrapper = await mountEditor({ portfolio: { active: false } })

			expect(wrapper.find('.portfolio-settings__url').exists()).toBe(false)
		})

		it('is not shown before the page knows whose it is', async () => {
			const wrapper = await mountEditor({ portfolio: { active: true }, signedIn: false })

			expect(wrapper.find('.portfolio-settings__url').exists()).toBe(false)
		})
	})

	/** An untitled page falls back to the name, so the field says so too. */
	it('offers the reader their own name as the title', async () => {
		const wrapper = await mountEditor()

		expect(wrapper.findComponent({ name: 'NcTextField' }).props('placeholder'))
			.toBe('Alice Appleby')
	})

	it('offers a collection to choose only once collections are what is shown', async () => {
		const wrapper = await mountEditor({ collections: [{ id: '7', title: 'Stairwells' }] })

		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)

		wrapper.vm.form.source = 'collection'
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options'))
			.toEqual([{ id: 7, title: 'Stairwells' }])
	})

	/**
	 * A card that saved the layout and refused the title would leave somebody
	 * guessing which of their changes took.
	 */
	it('saves the whole page in one request', async () => {
		const wrapper = await mountEditor()
		Object.assign(wrapper.vm.form, {
			active: true,
			title: 'Concrete',
			intro: 'Ten years of stairwells',
			layout: 'rows',
			source: 'recent',
			showDates: true,
		})

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toBe(PORTFOLIO)
		expect(saved()).toEqual({
			active: true,
			title: 'Concrete',
			intro: 'Ten years of stairwells',
			layout: 'rows',
			source: 'recent',
			collection_id: 0,
			show_captions: true,
			show_places: true,
			show_dates: true,
			show_avatar: true,
		})
		expect(showSuccess).toHaveBeenCalledWith('Your portfolio was saved')
	})

	it('saves the collection that was chosen', async () => {
		const wrapper = await mountEditor({
			portfolio: { source: 'collection', collection_id: '7' },
			collections: [{ id: '7', title: 'Stairwells' }],
		})

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(saved().collection_id).toBe(7)
	})

	it('confirms in place as well as in a toast', async () => {
		const wrapper = await mountEditor()

		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.portfolio-settings__message').text()).toBe('Saved')
		expect(wrapper.find('.portfolio-settings__message').attributes('role')).toBe('status')
	})

	it('cannot be saved twice at once', async () => {
		const wrapper = await mountEditor()
		axios.post.mockReturnValue(new Promise(() => {}))

		saveButton(wrapper).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(saveButton(wrapper).props('disabled')).toBe(true)
	})

	describe('when it does not go through', () => {
		it('says what the server said, in place and in a toast', async () => {
			const wrapper = await mountEditor()
			axios.post.mockRejectedValue({ response: { data: { error: 'That collection is not yours' } } })

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.portfolio-settings__message').text())
				.toBe('That collection is not yours')
			expect(showError).toHaveBeenCalledWith('That collection is not yours')
			expect(saveButton(wrapper).props('disabled')).toBe(false)
		})

		it('says something when the server said nothing', async () => {
			const wrapper = await mountEditor()
			axios.post.mockRejectedValue(new Error('offline'))

			saveButton(wrapper).vm.$emit('click')
			await flushPromises()

			expect(wrapper.find('.portfolio-settings__message').text()).toBe('Could not save it')
		})
	})

	/**
	 * Collections are the second half of one question. A reader who has none,
	 * or whose list did not come, can still publish their recent photos.
	 */
	it('still opens when the collections could not be read', async () => {
		axios.get.mockImplementation((url) => (url === COLLECTIONS
			? Promise.reject(new Error('offline'))
			: Promise.resolve({ data: { title: 'Concrete' } })))
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(PortfolioSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(wrapper.vm.form.title).toBe('Concrete')
		expect(wrapper.vm.collections).toEqual([])
		expect(showError).not.toHaveBeenCalled()
	})

	it('says so when the page itself could not be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(PortfolioSettings, { global: { plugins: [pinia] } })
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load your portfolio')
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})
})
