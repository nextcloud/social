/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'

function mountSwitcher(type) {
	const push = vi.fn()

	const wrapper = mount(TimelineSwitcher, {
		props: { type },
		global: { mocks: { $router: { push } } },
		attachTo: document.body,
	})

	return { wrapper, push }
}

function options(wrapper) {
	return wrapper.findAll('.switcher__option')
}

describe('TimelineSwitcher', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
	})

	it('offers the three timelines a reader moves between', () => {
		const { wrapper } = mountSwitcher('home')

		expect(options(wrapper).map((option) => option.text()))
			.toEqual(['My Feed', 'Local', 'Global'])
	})

	/**
	 * Next to Local and Global, what distinguishes the home timeline is whose
	 * posts it holds rather than where it sits in the sidebar.
	 */
	it('calls the home timeline My Feed', () => {
		const { wrapper } = mountSwitcher('home')

		expect(options(wrapper)[0].text()).toBe('My Feed')
	})

	it('gives each one an icon of its own', () => {
		const { wrapper } = mountSwitcher('home')

		expect(wrapper.findAll('.switcher__icon')).toHaveLength(3)
	})

	it.each([
		['home', 0],
		['timeline', 1],
		['federated', 2],
	])('marks %s as the one being shown', (type, index) => {
		const { wrapper } = mountSwitcher(type)

		expect(options(wrapper)[index].attributes('aria-checked')).toBe('true')
		expect(options(wrapper).filter((option) => option.attributes('aria-checked') === 'true')).toHaveLength(1)
	})

	// the pill

	/**
	 * One indicator that travels rather than three that light up: three
	 * buttons lighting up tell you where you landed, a pill that slides tells
	 * you where you came from.
	 */
	it.each([
		['home', 'translateX(0%)'],
		['timeline', 'translateX(100%)'],
		['federated', 'translateX(200%)'],
	])('slides the pill to %s', (type, transform) => {
		const { wrapper } = mountSwitcher(type)

		expect(wrapper.find('.switcher__glider').attributes('style'))
			.toContain(transform)
	})

	/**
	 * The width is the stylesheet's, which takes the track's padding off
	 * first; all the pill needs from here is how many options to divide by.
	 */
	it('leaves the pill to be sized from the number of options', () => {
		const { wrapper } = mountSwitcher('home')

		expect(wrapper.find('.switcher__glider').attributes('style'))
			.toContain('--switcher-count: 3')
	})

	/** It is decoration: the state it shows is on the options themselves. */
	it('hides the pill from a screen reader', () => {
		const { wrapper } = mountSwitcher('home')

		expect(wrapper.find('.switcher__glider').attributes('aria-hidden')).toBe('true')
	})

	// routing

	/**
	 * `home` is the route with no `type` at all: passing `type: 'home'` would
	 * ask for a timeline of that name, which nothing serves.
	 */
	it('routes to the bare timeline for My Feed', async () => {
		const { wrapper, push } = mountSwitcher('federated')

		await options(wrapper)[0].trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'timeline' })
	})

	it.each([
		['Local', 1, 'timeline'],
		['Global', 2, 'federated'],
	])('routes to %s', async (label, index, type) => {
		const { wrapper, push } = mountSwitcher('home')

		await options(wrapper)[index].trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'timeline', params: { type } })
	})

	/** Choosing the timeline already on screen is not a navigation. */
	it('does not route to where it already is', async () => {
		const { wrapper, push } = mountSwitcher('timeline')

		await options(wrapper)[1].trigger('click')

		expect(push).not.toHaveBeenCalled()
	})

	// the keyboard

	/** A roving tabindex puts one stop on the control, not three. */
	it('is one tab stop', () => {
		const { wrapper } = mountSwitcher('timeline')

		expect(options(wrapper).map((option) => option.attributes('tabindex')))
			.toEqual(['-1', '0', '-1'])
	})

	it.each([
		['ArrowRight', 'home', 'timeline'],
		['ArrowDown', 'home', 'timeline'],
		['ArrowLeft', 'timeline', 'home'],
		['ArrowUp', 'timeline', 'home'],
	])('%s moves from %s to %s', async (key, from, to) => {
		const { wrapper, push } = mountSwitcher(from)

		await wrapper.find('.switcher').trigger('keydown', { key })

		expect(push).toHaveBeenCalledWith(to === 'home' ? { name: 'timeline' } : { name: 'timeline', params: { type: to } })
	})

	/** The end of the group is never a dead stop. */
	it.each([
		['ArrowRight', 'federated', { name: 'timeline' }],
		['ArrowLeft', 'home', { name: 'timeline', params: { type: 'federated' } }],
	])('%s wraps round from %s', async (key, from, expected) => {
		const { wrapper, push } = mountSwitcher(from)

		await wrapper.find('.switcher').trigger('keydown', { key })

		expect(push).toHaveBeenCalledWith(expected)
	})

	it('leaves every other key alone', async () => {
		const { wrapper, push } = mountSwitcher('home')

		await wrapper.find('.switcher').trigger('keydown', { key: 'a' })
		await wrapper.find('.switcher').trigger('keydown', { key: 'Tab' })

		expect(push).not.toHaveBeenCalled()
	})

	/** Arrowing to a timeline takes the focus with it, as a radio group does. */
	it('moves the focus with the selection', async () => {
		const { wrapper } = mountSwitcher('home')

		await wrapper.find('.switcher').trigger('keydown', { key: 'ArrowRight' })

		expect(document.activeElement).toBe(options(wrapper)[1].element)
	})

	// what a screen reader is told

	it('is a radio group that names itself', () => {
		const { wrapper } = mountSwitcher('home')
		const group = wrapper.find('.switcher')

		expect(group.attributes('role')).toBe('radiogroup')
		expect(group.attributes('aria-label')).toBe('Which posts to show')
	})

	it('makes each option a radio', () => {
		const { wrapper } = mountSwitcher('home')

		expect(options(wrapper).map((option) => option.attributes('role')))
			.toEqual(['radio', 'radio', 'radio'])
	})

	/** Inside a form this must not submit it. */
	it('never submits anything', () => {
		const { wrapper } = mountSwitcher('home')

		expect(options(wrapper).map((option) => option.attributes('type')))
			.toEqual(['button', 'button', 'button'])
	})
})
