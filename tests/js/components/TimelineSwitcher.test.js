/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'

/**
 * The switch is a radio group, so a stub that records what it was asked to
 * show is enough: what this component decides is which route each choice is,
 * and that `home` is the bare one.
 */
const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['modelValue', 'value', 'name', 'type', 'buttonVariant', 'buttonVariantGrouped'],
	emits: ['update:modelValue'],
	template: `<button
		class="feed"
		:data-value="value"
		:aria-current="modelValue === value ? 'page' : undefined"
		@click="$emit('update:modelValue', value)"><slot /></button>`,
}

function mountSwitcher(type) {
	const push = vi.fn()

	const wrapper = mount(TimelineSwitcher, {
		props: { type },
		global: {
			stubs: { NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub },
			mocks: { $router: { push } },
		},
	})

	return { wrapper, push }
}

function feeds(wrapper) {
	return wrapper.findAll('.feed')
}

describe('TimelineSwitcher', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers the three timelines a reader moves between', () => {
		const { wrapper } = mountSwitcher('home')

		expect(feeds(wrapper).map((feed) => feed.text()))
			.toEqual(['My Feed', 'Local', 'Global'])
	})

	/**
	 * Next to Local and Global, what distinguishes the home timeline is whose
	 * posts it holds rather than where it sits in the sidebar.
	 */
	it('calls the home timeline My Feed', () => {
		const { wrapper } = mountSwitcher('home')

		expect(feeds(wrapper)[0].text()).toBe('My Feed')
		expect(feeds(wrapper)[0].attributes('data-value')).toBe('home')
	})

	it.each([
		['home', 0],
		['timeline', 1],
		['federated', 2],
	])('marks %s as the one being shown', (type, index) => {
		const { wrapper } = mountSwitcher(type)

		expect(feeds(wrapper)[index].attributes('aria-current')).toBe('page')
		expect(feeds(wrapper).filter((feed) => feed.attributes('aria-current') === 'page')).toHaveLength(1)
	})

	/**
	 * `home` is the route with no `type` at all: passing `type: 'home'` would
	 * ask for a timeline of that name, which nothing serves.
	 */
	it('routes to the bare timeline for My Feed', async () => {
		const { wrapper, push } = mountSwitcher('federated')

		await feeds(wrapper)[0].trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'timeline' })
	})

	it.each([
		['Local', 1, 'timeline'],
		['Global', 2, 'federated'],
	])('routes to %s', async (label, index, type) => {
		const { wrapper, push } = mountSwitcher('home')

		await feeds(wrapper)[index].trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'timeline', params: { type } })
	})

	/** Choosing the timeline already on screen is not a navigation. */
	it('does not route to where it already is', async () => {
		const { wrapper, push } = mountSwitcher('timeline')

		await feeds(wrapper)[1].trigger('click')

		expect(push).not.toHaveBeenCalled()
	})

	it('names itself for a screen reader', () => {
		const { wrapper } = mountSwitcher('home')

		expect(wrapper.find('nav').attributes('aria-label')).toBe('Which posts to show')
	})

	/** One group, so a keyboard moves through it as one control. */
	it('is a single radio group', () => {
		const { wrapper } = mountSwitcher('home')
		const names = wrapper.findAllComponents(NcCheckboxRadioSwitchStub)
			.map((feed) => feed.props('name'))

		expect(new Set(names).size).toBe(1)
		expect(wrapper.findAllComponents(NcCheckboxRadioSwitchStub)[0].props('type')).toBe('radio')
	})
})
