/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'

const IconStub = { name: 'IconStub', props: ['size'], template: '<span class="icon-stub" />' }

/**
 * Three of something, which is what every use of this is: the timelines a
 * reader moves between, and what of an account a profile shows.
 */
const OPTIONS = [
	{ value: 'first', label: 'First', icon: IconStub, to: { name: 'first' } },
	{ value: 'second', label: 'Second', icon: IconStub, to: { name: 'second' } },
	{ value: 'third', label: 'Third', icon: IconStub, to: { name: 'third', query: { q: '1' } } },
]

function mountSwitcher(value, options = OPTIONS) {
	const push = vi.fn()

	const wrapper = mount(TimelineSwitcher, {
		props: { options, value, label: 'Which posts to show' },
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

	it('offers what it was given, in order', () => {
		const { wrapper } = mountSwitcher('first')

		expect(options(wrapper).map((option) => option.text()))
			.toEqual(['First', 'Second', 'Third'])
	})

	it('gives each one an icon of its own', () => {
		const { wrapper } = mountSwitcher('first')

		expect(wrapper.findAll('.switcher__icon')).toHaveLength(3)
	})

	it.each([
		['first', 0],
		['second', 1],
		['third', 2],
	])('marks %s as the one being shown', (value, index) => {
		const { wrapper } = mountSwitcher(value)

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
		['first', 'translateX(0%)'],
		['second', 'translateX(100%)'],
		['third', 'translateX(200%)'],
	])('slides the pill to %s', (value, transform) => {
		const { wrapper } = mountSwitcher(value)

		expect(wrapper.find('.switcher__glider').attributes('style'))
			.toContain(transform)
	})

	/**
	 * The width is the stylesheet's, which takes the track's padding off
	 * first; all the pill needs from here is how many options to divide by.
	 */
	it('leaves the pill to be sized from the number of options', () => {
		const { wrapper } = mountSwitcher('first')

		expect(wrapper.find('.switcher__glider').attributes('style'))
			.toContain('--switcher-count: 3')
	})

	it('divides the track by however many it was given', () => {
		const { wrapper } = mountSwitcher('a', [
			{ value: 'a', label: 'A', icon: IconStub, to: { name: 'a' } },
			{ value: 'b', label: 'B', icon: IconStub, to: { name: 'b' } },
		])

		expect(wrapper.find('.switcher__glider').attributes('style'))
			.toContain('--switcher-count: 2')
	})

	/** It is decoration: the state it shows is on the options themselves. */
	it('hides the pill from a screen reader', () => {
		const { wrapper } = mountSwitcher('first')

		expect(wrapper.find('.switcher__glider').attributes('aria-hidden')).toBe('true')
	})

	// routing

	it.each([
		['Second', 1, { name: 'second' }],
		['Third', 2, { name: 'third', query: { q: '1' } }],
	])('pushes the route %s carries', async (label, index, to) => {
		const { wrapper, push } = mountSwitcher('first')

		await options(wrapper)[index].trigger('click')

		expect(push).toHaveBeenCalledWith(to)
	})

	/** Choosing the page already on screen is not a navigation. */
	it('does not route to where it already is', async () => {
		const { wrapper, push } = mountSwitcher('second')

		await options(wrapper)[1].trigger('click')

		expect(push).not.toHaveBeenCalled()
	})

	// the keyboard

	/** A roving tabindex puts one stop on the control, not one per option. */
	it('is one tab stop', () => {
		const { wrapper } = mountSwitcher('second')

		expect(options(wrapper).map((option) => option.attributes('tabindex')))
			.toEqual(['-1', '0', '-1'])
	})

	it.each([
		['ArrowRight', 'first', { name: 'second' }],
		['ArrowDown', 'first', { name: 'second' }],
		['ArrowLeft', 'second', { name: 'first' }],
		['ArrowUp', 'second', { name: 'first' }],
	])('%s moves from %s', async (key, from, expected) => {
		const { wrapper, push } = mountSwitcher(from)

		await wrapper.find('.switcher').trigger('keydown', { key })

		expect(push).toHaveBeenCalledWith(expected)
	})

	/** The end of the group is never a dead stop. */
	it.each([
		['ArrowRight', 'third', { name: 'first' }],
		['ArrowLeft', 'first', { name: 'third', query: { q: '1' } }],
	])('%s wraps round from %s', async (key, from, expected) => {
		const { wrapper, push } = mountSwitcher(from)

		await wrapper.find('.switcher').trigger('keydown', { key })

		expect(push).toHaveBeenCalledWith(expected)
	})

	it('leaves every other key alone', async () => {
		const { wrapper, push } = mountSwitcher('first')

		await wrapper.find('.switcher').trigger('keydown', { key: 'a' })
		await wrapper.find('.switcher').trigger('keydown', { key: 'Tab' })

		expect(push).not.toHaveBeenCalled()
	})

	/** Arrowing to an option takes the focus with it, as a radio group does. */
	it('moves the focus with the selection', async () => {
		const { wrapper } = mountSwitcher('first')

		await wrapper.find('.switcher').trigger('keydown', { key: 'ArrowRight' })

		expect(document.activeElement).toBe(options(wrapper)[1].element)
	})

	// what a screen reader is told

	it('is a radio group that says what it is choosing', () => {
		const { wrapper } = mountSwitcher('first')
		const group = wrapper.find('.switcher')

		expect(group.attributes('role')).toBe('radiogroup')
		expect(group.attributes('aria-label')).toBe('Which posts to show')
	})

	it('makes each option a radio', () => {
		const { wrapper } = mountSwitcher('first')

		expect(options(wrapper).map((option) => option.attributes('role')))
			.toEqual(['radio', 'radio', 'radio'])
	})

	/** Inside a form this must not submit it. */
	it('never submits anything', () => {
		const { wrapper } = mountSwitcher('first')

		expect(options(wrapper).map((option) => option.attributes('type')))
			.toEqual(['button', 'button', 'button'])
	})

	/**
	 * A value that names none of the options -- a query somebody typed -- must
	 * still leave the control in a state that can be read and used.
	 */
	it('shows the first option when the value names none of them', () => {
		const { wrapper } = mountSwitcher('nonsense')

		expect(wrapper.find('.switcher__glider').attributes('style')).toContain('translateX(0%)')
		expect(options(wrapper).filter((option) => option.attributes('aria-checked') === 'true')).toHaveLength(0)
	})
})
