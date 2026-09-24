/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InterestCloud from '../../../src/components/InterestCloud.vue'
import {
	addInterest,
	moveInterest,
	pinInterest,
	removeInterest,
	unpinInterest,
} from '../../../src/services/interests.js'
import { showError, showSuccess, showUndo } from '../../../src/services/toast.js'
import { STEP_SIZES, moveTo, sizeStep } from '../../../src/utils/interestCloud.js'

vi.mock('../../../src/services/interests.js', () => ({
	addInterest: vi.fn(),
	moveInterest: vi.fn(),
	pinInterest: vi.fn(),
	removeInterest: vi.fn(),
	unpinInterest: vi.fn(),
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showUndo: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * Twenty-five interests the way a reader who has used this for a month would
 * have them: a few pinned at the top, one added by hand, two followed, the
 * rest learned, with a trend on some.
 */
const TAGS = [
	['photography', 'learned', true, 18.4, 'up'],
	['analog', 'manual', true, 3],
	['nextcloud', 'followed', false, 14.2],
	['film', 'learned', false, 12.3, 'down'],
	['streetphotography', 'learned', false, 10.9],
	['darkroom', 'learned', false, 9.7, 'up'],
	['opensource', 'followed', false, 9.1],
	['bicycles', 'learned', false, 8.8],
	['hiking', 'learned', false, 8.1],
	['berlin', 'learned', false, 7.4],
	['coffee', 'learned', false, 7],
	['typography', 'learned', false, 6.6],
	['linux', 'learned', false, 6.1],
	['birds', 'learned', false, 5.8, 'up'],
	['cooking', 'learned', false, 5.5],
	['architecture', 'learned', false, 5.1],
	['mastodon', 'learned', false, 4.9],
	['privacy', 'learned', false, 4.6],
	['books', 'learned', false, 4.4],
	['gardening', 'learned', false, 4.1],
	['jazz', 'learned', false, 3.9],
	['climbing', 'learned', false, 3.6],
	['fediverse', 'learned', false, 3.4],
	['maps', 'learned', false, 3.2],
	['ceramics', 'learned', false, 3.1],
]

/** @return {object[]} a fresh copy of the fixture, as the API sends it */
function interests() {
	return TAGS.map(([tag, source, pinned, score, trend = null], rank) => ({ tag, rank, source, pinned, score, trend }))
}

/**
 * @param {object[]} list the interests
 * @return {object} the state the server answers with around them
 */
function stateOf(list) {
	return {
		settings: { enabled: true, learning: true, paused: false, languages: [], noticeAcknowledged: true },
		interests: list.map((entry, rank) => ({ ...entry, rank })),
		candidates: [],
		thin: false,
		cap: 30,
	}
}

/**
 * The server's answer to a move: the tag at that rank, pinned.
 *
 * @param {string} tag the tag moved
 * @param {number} position where to
 * @return {object} the state
 */
function movedState(tag, position) {
	const byTag = new Map(interests().map((entry) => [entry.tag, entry]))
	const order = moveTo([...byTag.keys()], tag, position)

	return stateOf(order.map((name) => (name === tag ? { ...byTag.get(name), pinned: true } : byTag.get(name))))
}

const NcPopoverStub = {
	name: 'NcPopover',
	props: { shown: Boolean },
	emits: ['update:shown'],
	template: '<div class="popover-stub"><slot name="trigger" :attrs="{ \'aria-expanded\': String(shown) }" /><div v-if="shown" class="popover-stub__content"><slot /></div></div>',
}

/**
 * @param {object} [props] the cloud's props
 * @return {object} the mounted cloud
 */
function mountCloud(props = {}) {
	return mount(InterestCloud, {
		props: { interests: interests(), ...props },
		slots: { end: '<button class="add-pill-stub">Add</button>' },
		global: { stubs: { NcPopover: NcPopoverStub, RouterLink: RouterLinkStub } },
		attachTo: document.body,
	})
}

const tags = (wrapper) => wrapper.findAll('.interest-cloud__tag')
const items = (wrapper) => wrapper.findAll('.interest-cloud__item:not(.interest-cloud__item--end)')
const tagNamed = (wrapper, name) => tags(wrapper).find((tag) => tag.attributes('data-tag') === name)
const itemNamed = (wrapper, name) => items(wrapper).find((item) => item.find('.interest-cloud__tag').attributes('data-tag') === name)
const action = (wrapper, label) => wrapper.findAll('.interest-cloud__action').find((button) => button.text() === label)

/**
 * @param {object} wrapper the mounted cloud
 * @param {string} name the tag whose popover to open
 */
async function openPopover(wrapper, name) {
	await tagNamed(wrapper, name).trigger('click')
}

describe('InterestCloud', () => {
	let wrapper

	beforeEach(() => {
		vi.clearAllMocks()
		moveInterest.mockImplementation((tag, position) => Promise.resolve(movedState(tag, position)))
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = undefined
	})

	describe('drawing', () => {
		it('draws every listed tag in rank order, top interest first', () => {
			wrapper = mountCloud()

			expect(tags(wrapper).map((tag) => tag.attributes('data-tag'))).toEqual(TAGS.map(([tag]) => tag))
			// the Add pill the parent hands in closes the flow
			expect(wrapper.find('.interest-cloud__item--end .add-pill-stub').exists()).toBe(true)
		})

		/**
		 * A twenty-five tag cloud as it would really be drawn: sizes never go
		 * up along the reading order, the top two are the largest step and
		 * the tail the smallest.
		 */
		it('steps the size down with the rank, never up', () => {
			wrapper = mountCloud()
			const sizes = items(wrapper).map((item) => parseFloat(item.attributes('style').match(/--interest-size:\s*([\d.]+)rem/)[1]))

			expect(sizes).toHaveLength(25)
			expect(sizes[0]).toBe(2)
			expect(sizes[1]).toBe(2)
			expect(sizes[24]).toBe(0.85)
			for (let i = 1; i < sizes.length; i++) {
				expect(sizes[i]).toBeLessThanOrEqual(sizes[i - 1])
			}
			expect(items(wrapper).map((item) => item.classes()).every((classes, rank) => classes.includes(`interest-cloud__item--step-${sizeStep(rank)}`))).toBe(true)
			expect(new Set(sizes)).toEqual(new Set(STEP_SIZES))
		})

		it('says the rank, the total and the source in each tag\'s name', () => {
			wrapper = mountCloud()

			expect(tagNamed(wrapper, 'photography').attributes('aria-label')).toBe('#photography, priority 1 of 25, learned, pinned')
			expect(tagNamed(wrapper, 'analog').attributes('aria-label')).toBe('#analog, priority 2 of 25, added by you, pinned')
			expect(tagNamed(wrapper, 'nextcloud').attributes('aria-label')).toBe('#nextcloud, priority 3 of 25, followed')
			expect(tagNamed(wrapper, 'film').attributes('aria-label')).toBe('#film, priority 4 of 25, learned')
		})

		it('explains a tag on hover', () => {
			wrapper = mountCloud()

			expect(tagNamed(wrapper, 'film').attributes('title')).toBe('Rank 4 · learned · score 12.3')
		})

		it('marks pinned, added and followed tags with a glyph, and learned ones with none', () => {
			wrapper = mountCloud()
			const glyph = (name) => tagNamed(wrapper, name).find('.interest-cloud__glyph')

			expect(glyph('photography').classes()).toContain('pin-icon')
			// one glyph each, and the pin says the more useful thing; the name
			// still says it was added by hand
			expect(glyph('analog').classes()).toContain('pin-icon')
			expect(glyph('nextcloud').classes()).toContain('bell-outline-icon')
			expect(glyph('film').exists()).toBe(false)

			wrapper.unmount()
			wrapper = mountCloud({ interests: [{ tag: 'analog', rank: 0, source: 'manual', pinned: false, score: 3, trend: null }] })
			expect(glyph('analog').classes()).toContain('pencil-outline-icon')
		})

		it('shows a trend arrow on learned tags only', () => {
			wrapper = mountCloud()

			expect(tagNamed(wrapper, 'photography').find('.interest-cloud__trend--up').exists()).toBe(true)
			expect(tagNamed(wrapper, 'film').find('.interest-cloud__trend--down').exists()).toBe(true)
			expect(tagNamed(wrapper, 'hiking').find('.interest-cloud__trend').exists()).toBe(false)

			wrapper.unmount()
			wrapper = mountCloud({ interests: [{ tag: 'analog', rank: 0, source: 'manual', pinned: false, score: 3, trend: 'up' }] })
			expect(tagNamed(wrapper, 'analog').find('.interest-cloud__trend').exists()).toBe(false)
		})

		it('draws an empty cloud as a faint placeholder with the Add pill', () => {
			wrapper = mountCloud({ interests: [] })

			expect(wrapper.find('.interest-cloud__placeholder').exists()).toBe(true)
			expect(wrapper.text()).toContain('Your interests appear here as you read')
			expect(wrapper.find('.add-pill-stub').exists()).toBe(true)
		})

		it('dims a frozen cloud', () => {
			wrapper = mountCloud({ frozen: true })

			expect(wrapper.classes()).toContain('interest-cloud--frozen')
		})
	})

	describe('followed tags', () => {
		it('offer no × and no Remove, and say how to remove them instead', async () => {
			wrapper = mountCloud()

			expect(itemNamed(wrapper, 'nextcloud').find('.interest-cloud__remove').exists()).toBe(false)
			expect(itemNamed(wrapper, 'film').find('.interest-cloud__remove').exists()).toBe(true)

			await openPopover(wrapper, 'nextcloud')
			expect(action(wrapper, 'Remove')).toBeUndefined()
			expect(wrapper.find('.interest-cloud__menu-note').text()).toBe('Unfollow #nextcloud to remove it from interests')
		})

		it('ignore Delete on the keyboard', async () => {
			wrapper = mountCloud()
			await tagNamed(wrapper, 'nextcloud').trigger('keydown', { key: 'Delete' })

			expect(removeInterest).not.toHaveBeenCalled()
		})
	})

	describe('dragging', () => {
		it('moves the dropped tag to the rank it was dropped at', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'photography').trigger('dragstart', { dataTransfer: { setData: vi.fn() } })
			// jsdom lays nothing out, so the pointer is past the middle of
			// every tag: this is "behind #bicycles"
			await itemNamed(wrapper, 'bicycles').trigger('dragover', { clientX: 10 })
			await itemNamed(wrapper, 'bicycles').trigger('drop')
			await flushPromises()

			expect(moveInterest).toHaveBeenCalledWith('photography', 7)
			expect(wrapper.emitted('update').at(-1)[0]).toEqual(movedState('photography', 7))
		})

		it('makes room while the tag is carried, before anything is sent', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'coffee').trigger('dragstart', { dataTransfer: { setData: vi.fn() } })
			await itemNamed(wrapper, 'nextcloud').trigger('dragover', { clientX: 10 })

			expect(tags(wrapper).slice(0, 5).map((tag) => tag.attributes('data-tag')))
				.toEqual(['photography', 'analog', 'nextcloud', 'coffee', 'film'])
			// and the carried tag already has the size of where it would land
			expect(itemNamed(wrapper, 'coffee').classes()).toContain(`interest-cloud__item--step-${sizeStep(3)}`)
			expect(moveInterest).not.toHaveBeenCalled()
		})

		it('puts everything back when the tag is let go somewhere else', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'coffee').trigger('dragstart', { dataTransfer: { setData: vi.fn() } })
			await itemNamed(wrapper, 'nextcloud').trigger('dragover', { clientX: 10 })
			await tagNamed(wrapper, 'coffee').trigger('dragend')

			expect(tags(wrapper).map((tag) => tag.attributes('data-tag'))).toEqual(TAGS.map(([tag]) => tag))
			expect(moveInterest).not.toHaveBeenCalled()
		})

		it('keeps the preview until the server has answered', async () => {
			let answer
			moveInterest.mockImplementation(() => new Promise((resolve) => {
				answer = resolve
			}))
			wrapper = mountCloud()

			await tagNamed(wrapper, 'coffee').trigger('dragstart', { dataTransfer: { setData: vi.fn() } })
			await itemNamed(wrapper, 'photography').trigger('dragover', { clientX: 10 })
			await itemNamed(wrapper, 'photography').trigger('drop')

			expect(tags(wrapper)[1].attributes('data-tag')).toBe('coffee')
			answer(movedState('coffee', 1))
			await flushPromises()
			expect(moveInterest).toHaveBeenCalledWith('coffee', 1)
		})

		it('says so when the move is refused, and draws the server\'s order again', async () => {
			moveInterest.mockRejectedValue({ response: { data: { error: 'Nope' } } })
			wrapper = mountCloud()

			await tagNamed(wrapper, 'coffee').trigger('dragstart', { dataTransfer: { setData: vi.fn() } })
			await itemNamed(wrapper, 'photography').trigger('dragover', { clientX: 10 })
			await itemNamed(wrapper, 'photography').trigger('drop')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Nope')
			expect(tags(wrapper).map((tag) => tag.attributes('data-tag'))).toEqual(TAGS.map(([tag]) => tag))
		})
	})

	describe('the popover', () => {
		it('opens on a click and closes on a second one', async () => {
			wrapper = mountCloud()

			await openPopover(wrapper, 'film')
			expect(wrapper.find('.popover-stub__content').exists()).toBe(true)
			expect(wrapper.find('.interest-cloud__menu-meta').text()).toBe('Rank 4 · learned · score 12.3')

			await openPopover(wrapper, 'film')
			expect(wrapper.find('.popover-stub__content').exists()).toBe(false)
		})

		it('moves a tag one rank higher and one lower', async () => {
			wrapper = mountCloud()

			await openPopover(wrapper, 'film')
			await action(wrapper, 'Higher priority').trigger('click')
			await flushPromises()
			expect(moveInterest).toHaveBeenLastCalledWith('film', 2)

			await openPopover(wrapper, 'hiking')
			await action(wrapper, 'Lower priority').trigger('click')
			await flushPromises()
			expect(moveInterest).toHaveBeenLastCalledWith('hiking', 9)
		})

		it('moves a tag to the top', async () => {
			wrapper = mountCloud()

			await openPopover(wrapper, 'ceramics')
			await action(wrapper, 'Move to top').trigger('click')
			await flushPromises()

			expect(moveInterest).toHaveBeenCalledWith('ceramics', 0)
		})

		it('cannot move the top tag higher or the last one lower', async () => {
			wrapper = mountCloud()

			await openPopover(wrapper, 'photography')
			expect(action(wrapper, 'Higher priority').attributes('disabled')).toBeDefined()
			expect(action(wrapper, 'Move to top').attributes('disabled')).toBeDefined()

			await openPopover(wrapper, 'ceramics')
			expect(action(wrapper, 'Lower priority').attributes('disabled')).toBeDefined()
		})

		it('pins an unpinned tag and unpins a pinned one', async () => {
			pinInterest.mockResolvedValue(stateOf(interests()))
			unpinInterest.mockResolvedValue(stateOf(interests()))
			wrapper = mountCloud()

			await openPopover(wrapper, 'film')
			await action(wrapper, 'Pin').trigger('click')
			await flushPromises()
			expect(pinInterest).toHaveBeenCalledWith('film')

			await openPopover(wrapper, 'photography')
			await action(wrapper, 'Unpin').trigger('click')
			await flushPromises()
			expect(unpinInterest).toHaveBeenCalledWith('photography')
			expect(wrapper.emitted('update')).toHaveLength(2)
		})

		it('links to the hashtag timeline', async () => {
			wrapper = mountCloud()

			await openPopover(wrapper, 'film')
			const link = wrapper.findComponent(RouterLinkStub)

			expect(link.props('to')).toEqual({ name: 'tags', params: { tag: 'film' } })
			expect(link.text()).toBe('Open #film')
		})
	})

	describe('removing', () => {
		it('removes a hand-added tag with an Undo that adds it back where it was', async () => {
			const without = stateOf(interests().filter((entry) => entry.tag !== 'analog'))
			removeInterest.mockResolvedValue(without)
			addInterest.mockResolvedValue(stateOf(interests()))
			wrapper = mountCloud()

			await openPopover(wrapper, 'analog')
			await action(wrapper, 'Remove').trigger('click')
			await flushPromises()

			expect(removeInterest).toHaveBeenCalledWith('analog')
			expect(wrapper.emitted('update').at(-1)[0]).toEqual(without)
			expect(showUndo).toHaveBeenCalledWith('#analog removed from your interests', expect.any(Function))

			// it was pinned at rank 1, so the Undo pins it there again
			await showUndo.mock.calls[0][1]()
			await flushPromises()
			expect(addInterest).toHaveBeenCalledWith('analog')
			expect(moveInterest).toHaveBeenCalledWith('analog', 1)
		})

		/**
		 * Removing a learned tag resets its score, and nothing in the API sets
		 * a score, so there is no honest Undo: adding it back would make it a
		 * hand-added tag it never was.
		 */
		it('says a learned tag is gone, without an Undo it could not keep', async () => {
			removeInterest.mockResolvedValue(stateOf(interests().filter((entry) => entry.tag !== 'film')))
			wrapper = mountCloud()

			await itemNamed(wrapper, 'film').find('.interest-cloud__remove').trigger('click')
			await flushPromises()

			expect(removeInterest).toHaveBeenCalledWith('film')
			expect(showUndo).not.toHaveBeenCalled()
			expect(showSuccess).toHaveBeenCalledWith('#film removed. Reading more of it can bring it back.')
		})

		it('takes the tag out of the cloud at once', async () => {
			removeInterest.mockReturnValue(new Promise(() => {}))
			wrapper = mountCloud()

			await itemNamed(wrapper, 'film').find('.interest-cloud__remove').trigger('click')

			expect(tagNamed(wrapper, 'film')).toBeUndefined()
		})

		it('puts it back when the server refuses', async () => {
			removeInterest.mockRejectedValue({ response: { data: { error: 'Unfollow #film to remove it' } } })
			wrapper = mountCloud()

			await itemNamed(wrapper, 'film').find('.interest-cloud__remove').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Unfollow #film to remove it')
			expect(tagNamed(wrapper, 'film')).toBeDefined()
		})

		it('removes the focused tag with Delete', async () => {
			removeInterest.mockResolvedValue(stateOf(interests()))
			wrapper = mountCloud()

			await tagNamed(wrapper, 'film').trigger('keydown', { key: 'Delete' })
			await flushPromises()

			expect(removeInterest).toHaveBeenCalledWith('film')
		})
	})

	describe('the keyboard', () => {
		it('is one tab stop that the arrow keys move', async () => {
			wrapper = mountCloud()
			const stops = () => tags(wrapper).filter((tag) => tag.attributes('tabindex') === '0')

			expect(stops()).toHaveLength(1)
			expect(stops()[0].attributes('data-tag')).toBe('photography')

			await tagNamed(wrapper, 'photography').trigger('keydown', { key: 'ArrowRight' })
			await flushPromises()
			expect(stops()[0].attributes('data-tag')).toBe('analog')
			expect(document.activeElement).toBe(tagNamed(wrapper, 'analog').element)

			await tagNamed(wrapper, 'analog').trigger('keydown', { key: 'End' })
			await flushPromises()
			expect(stops()[0].attributes('data-tag')).toBe('ceramics')

			await tagNamed(wrapper, 'ceramics').trigger('keydown', { key: 'Home' })
			await flushPromises()
			expect(stops()[0].attributes('data-tag')).toBe('photography')
		})

		it('moves the focused tag a rank with Alt and an arrow, and says where it went', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'film').trigger('keydown', { key: 'ArrowRight', altKey: true })
			await flushPromises()
			expect(moveInterest).toHaveBeenLastCalledWith('film', 4)
			expect(wrapper.find('[aria-live="polite"]').text()).toBe('#film, now priority 5 of 25')

			await tagNamed(wrapper, 'film').trigger('keydown', { key: 'ArrowLeft', altKey: true })
			await flushPromises()
			expect(moveInterest).toHaveBeenLastCalledWith('film', 2)
			expect(wrapper.find('[aria-live="polite"]').text()).toBe('#film, now priority 3 of 25')
		})

		it('keeps focus on the tag it moved', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'film').trigger('keydown', { key: 'ArrowLeft', altKey: true })
			await flushPromises()

			expect(document.activeElement?.getAttribute('data-tag')).toBe('film')
		})

		it('does not move the top tag any higher', async () => {
			wrapper = mountCloud()

			await tagNamed(wrapper, 'photography').trigger('keydown', { key: 'ArrowLeft', altKey: true })
			await flushPromises()

			expect(moveInterest).not.toHaveBeenCalled()
		})
	})

	describe('motion', () => {
		const SOURCE = readFileSync(resolve(process.cwd(), 'src/components/InterestCloud.vue'), 'utf8')

		it('turns the reorder, the fade and the flash off for a reader who asked for less movement', () => {
			const reduced = SOURCE.slice(SOURCE.indexOf('@media (prefers-reduced-motion: reduce)'))

			expect(reduced).toContain('.interest-cloud-move')
			expect(reduced).toContain('.interest-cloud-leave-active')
			expect(reduced).toContain('transition: none')
			expect(reduced).toContain('animation: none')
		})

		it('scrolls a revealed tag into view without smoothing when motion is reduced', async () => {
			const matchMedia = vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true })
			wrapper = mountCloud()
			const scroll = vi.spyOn(tagNamed(wrapper, 'ceramics').element, 'scrollIntoView')

			wrapper.vm.reveal('ceramics')
			await flushPromises()

			expect(scroll).toHaveBeenCalledWith({ block: 'nearest', behavior: 'auto' })
			expect(itemNamed(wrapper, 'ceramics').classes()).toContain('interest-cloud__item--flash')
			matchMedia.mockRestore()
		})
	})
})
