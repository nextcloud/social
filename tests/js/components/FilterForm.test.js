/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import FilterForm from '../../../src/components/FilterForm.vue'

/** An hour from now and an hour ago, as the API writes a date. */
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString().replace(/\.\d+Z$/, '.000Z')

/** @param {object} overrides what to change about it */
function filter(overrides = {}) {
	return {
		id: '7',
		title: 'Politics',
		context: ['home', 'public'],
		filter_action: 'hide',
		expires_at: null,
		keywords: [{ id: '11', keyword: 'election', whole_word: true }],
		statuses: [],
		...overrides,
	}
}

/** @param {object} props what to open the form with */
function mountForm(props = {}) {
	return mount(FilterForm, { props })
}

const choices = (wrapper) => wrapper.findAll('.filter-form__choice')
const choiceFor = (wrapper, label) => choices(wrapper).find((choice) => choice.text().includes(label))
const words = (wrapper) => wrapper.findAll('.filter-form__word input')
const submitted = (wrapper) => wrapper.emitted('submit')?.at(-1)?.[0]

/** @param {object} wrapper the mounted form */
async function submit(wrapper) {
	await wrapper.find('form').trigger('submit')
	await flushPromises()
}

describe('FilterForm', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('offers the five places a filter can apply, under the names this app uses', () => {
		const wrapper = mountForm()

		const labels = choices(wrapper).map((choice) => choice.text())

		expect(labels.filter((label) => label.startsWith('My Feed'))).toHaveLength(1)
		expect(labels.filter((label) => label.startsWith('Activities'))).toHaveLength(1)
		expect(labels.filter((label) => label.startsWith('Local, Global'))).toHaveLength(1)
		expect(labels.filter((label) => label.startsWith('Conversations'))).toHaveLength(1)
		expect(labels.filter((label) => label.startsWith('Profiles'))).toHaveLength(1)
	})

	it('sends what was typed, as the v2 API takes it', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('  Politics  ')
		await words(wrapper)[0].setValue('election')
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		await choiceFor(wrapper, 'Take it out of the timeline').find('input').setValue(true)
		await submit(wrapper)

		expect(submitted(wrapper)).toEqual({
			title: 'Politics',
			context: ['home'],
			filter_action: 'hide',
			keywords_attributes: [{ keyword: 'election', whole_word: false }],
			expires_in: '',
		})
	})

	/**
	 * The order is the server's own, which is the order it stores and answers
	 * with: a form that sent them in tick order would show a filter reordering
	 * itself the next time it was read.
	 */
	it('names the contexts in the order the server keeps them', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('Politics')
		await words(wrapper)[0].setValue('election')
		await choiceFor(wrapper, 'Profiles').find('input').setValue(true)
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		await choiceFor(wrapper, 'Conversations').find('input').setValue(true)
		await submit(wrapper)

		expect(submitted(wrapper).context).toEqual(['home', 'thread', 'account'])
	})

	it('leaves a post where it is unless told otherwise, as the API does', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('Politics')
		await words(wrapper)[0].setValue('election')
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		await submit(wrapper)

		expect(submitted(wrapper).filter_action).toBe('warn')
	})

	it('carries whole-word for each word on its own', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('Politics')
		await words(wrapper)[0].setValue('cat')
		await wrapper.find('.filter-form__add-word').trigger('click')
		await words(wrapper)[1].setValue('dog')
		await wrapper.findAll('.filter-form__whole-word input')[1].setValue(true)
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		await submit(wrapper)

		expect(submitted(wrapper).keywords_attributes).toEqual([
			{ keyword: 'cat', whole_word: false },
			{ keyword: 'dog', whole_word: true },
		])
	})

	it('says an empty expiry rather than leaving it out, which is how a new filter never expires', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('Politics')
		await words(wrapper)[0].setValue('election')
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		await submit(wrapper)

		expect(submitted(wrapper).expires_in).toBe('')
	})

	it('sends a chosen duration as the seconds the API counts from now', async () => {
		const wrapper = mountForm()

		await wrapper.find('.filter-form__title input').setValue('Politics')
		await words(wrapper)[0].setValue('election')
		await choiceFor(wrapper, 'My Feed').find('input').setValue(true)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		await select.vm.$emit('update:modelValue', select.props('options').find((option) => option.id === '86400'))
		await submit(wrapper)

		expect(submitted(wrapper).expires_in).toBe(86400)
	})

	describe('what it refuses to send', () => {
		/** Everything filled in but the one thing each test then takes away. */
		async function nearlyComplete() {
			const wrapper = mountForm()
			await wrapper.find('.filter-form__title input').setValue('Politics')
			await words(wrapper)[0].setValue('election')
			await choiceFor(wrapper, 'My Feed').find('input').setValue(true)

			return wrapper
		}

		it('has nothing to complain about once it is filled in', async () => {
			const wrapper = await nearlyComplete()

			expect(wrapper.find('.notecard').exists()).toBe(false)
			expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
		})

		/**
		 * The server answers 422 with "title is required" — its own English,
		 * which no translator ever saw and which names a field the reader
		 * never typed. Each of these is said here instead, before the request.
		 */
		it('says a filter needs a name, and does not send one without', async () => {
			const wrapper = await nearlyComplete()

			await wrapper.find('.filter-form__title input').setValue('   ')
			await submit(wrapper)

			expect(wrapper.text()).toContain('Give the filter a name')
			expect(wrapper.emitted('submit')).toBeUndefined()
		})

		it('says an empty word would match every post, and does not send it', async () => {
			const wrapper = await nearlyComplete()

			await words(wrapper)[0].setValue('  ')
			await submit(wrapper)

			expect(wrapper.text()).toContain('would match every post there is')
			expect(wrapper.emitted('submit')).toBeUndefined()
		})

		it('says a filter with no place does nothing anywhere, and does not send it', async () => {
			const wrapper = await nearlyComplete()

			await choiceFor(wrapper, 'My Feed').find('input').setValue(false)
			await submit(wrapper)

			expect(wrapper.text()).toContain('Tick at least one place')
			expect(wrapper.emitted('submit')).toBeUndefined()
		})

		/** Both columns are 255 characters wide, and the server cuts to fit. */
		it('holds the name and every word to the width of the column behind them', async () => {
			const wrapper = await nearlyComplete()

			expect(wrapper.find('.filter-form__title input').attributes('maxlength')).toBe('255')
			expect(words(wrapper)[0].attributes('maxlength')).toBe('255')
		})

		it('keeps one word row, since a filter with no words matches nothing', async () => {
			const wrapper = await nearlyComplete()

			expect(words(wrapper)).toHaveLength(1)
			expect(wrapper.find('.filter-form__keyword button').attributes('disabled')).toBeDefined()
		})
	})

	describe('changing a filter that exists', () => {
		it('opens with what the filter says', () => {
			const wrapper = mountForm({ filter: filter() })

			expect(wrapper.find('.filter-form__title input').element.value).toBe('Politics')
			expect(words(wrapper)[0].element.value).toBe('election')
			expect(wrapper.find('.filter-form__whole-word input').element.checked).toBe(true)
			expect(choiceFor(wrapper, 'My Feed').find('input').element.checked).toBe(true)
			expect(choiceFor(wrapper, 'Activities').find('input').element.checked).toBe(false)
			expect(choiceFor(wrapper, 'Take it out of the timeline').find('input').element.checked).toBe(true)
		})

		/**
		 * A word keeps its id or the update adds it again: `keywords_attributes`
		 * edits in place, and an entry without an id is a new keyword.
		 */
		it('keeps the id of a word it did not add', async () => {
			const wrapper = mountForm({ filter: filter() })

			await words(wrapper)[0].setValue('ballot')
			await submit(wrapper)

			expect(submitted(wrapper).keywords_attributes)
				.toEqual([{ id: '11', keyword: 'ballot', whole_word: true }])
		})

		it('names a word it took out, since dropping it from the form leaves it filtering', async () => {
			const wrapper = mountForm({
				filter: filter({
					keywords: [
						{ id: '11', keyword: 'election', whole_word: true },
						{ id: '12', keyword: 'ballot', whole_word: false },
					],
				}),
			})

			await wrapper.findAll('.filter-form__keyword')[1].find('button').trigger('click')
			await submit(wrapper)

			expect(submitted(wrapper).keywords_attributes).toEqual([
				{ id: '11', keyword: 'election', whole_word: true },
				{ id: '12', _destroy: true },
			])
		})

		/**
		 * An update that does not name `expires_in` leaves the expiry alone.
		 * Without that option, opening a filter to fix a typo would restart
		 * its countdown from the moment it was saved.
		 */
		it('leaves a running expiry alone unless the reader changes it', async () => {
			const wrapper = mountForm({ filter: filter({ expires_at: iso(3600 * 1000) }) })

			expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue').id).toBe('keep')

			await submit(wrapper)

			expect(submitted(wrapper)).not.toHaveProperty('expires_in')
		})

		it('offers to stop a running expiry, which is an empty expires_in', async () => {
			const wrapper = mountForm({ filter: filter({ expires_at: iso(3600 * 1000) }) })

			const select = wrapper.findComponent({ name: 'NcSelect' })
			await select.vm.$emit('update:modelValue', select.props('options').find((option) => option.id === 'never'))
			await submit(wrapper)

			expect(submitted(wrapper).expires_in).toBe('')
		})

		/**
		 * An expired filter is not doing anything, and saving it writes
		 * whatever the expiry box says — so there is no "leave it as it is" to
		 * offer, and the reader is told what saving will do.
		 */
		it('warns that saving an expired filter starts it again', () => {
			const wrapper = mountForm({ filter: filter({ expires_at: iso(-3600 * 1000) }) })

			expect(wrapper.text()).toContain('Saving it starts it again')
			expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue').id).toBe('never')
		})
	})
})
