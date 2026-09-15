/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import FeaturedTagsSettings from '../../../src/components/FeaturedTagsSettings.vue'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

/**
 * @param {string} id the row's id, as the API sends it
 * @param {string} name the hashtag, as it is stored
 * @param {number} count how many posts carry it
 * @return {object} a featured tag entity
 */
function featuredTag(id, name, count = 0) {
	return {
		id,
		name,
		url: `https://cloud.example/tags/${name}`,
		statuses_count: count,
		last_status_at: count > 0 ? '2026-09-01' : null,
	}
}

/**
 * @param {object} answers what the two reads answer with
 * @param {Array} [answers.featured] `GET /featured_tags`
 * @param {Array} [answers.suggestions] `GET /featured_tags/suggestions`
 * @return {Promise<object>} the mounted component, once both have settled
 */
async function mountEditor({ featured = [], suggestions = [] } = {}) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/suggestions') ? suggestions : featured,
	}))

	const wrapper = mount(FeaturedTagsSettings, {
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()

	return wrapper
}

const rows = (wrapper) => wrapper.findAll('.featured-tags-settings__item')
const chips = (wrapper) => wrapper.findAll('.featured-tags-settings__chip')
const typeTag = (wrapper, value) => wrapper.find('.featured-tags-settings__field input').setValue(value)
const submit = (wrapper) => wrapper.find('.featured-tags-settings__add').trigger('submit')

describe('FeaturedTagsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('asks for the reader\'s own tags and for the suggestions', async () => {
		await mountEditor()

		expect(axios.get).toHaveBeenCalledWith(`${API}/featured_tags`)
		expect(axios.get).toHaveBeenCalledWith(`${API}/featured_tags/suggestions`)
	})

	it('says what featuring a hashtag is for when none are', async () => {
		const wrapper = await mountEditor()

		expect(wrapper.text()).toContain('You feature no hashtags yet.')
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('lists what is featured, with how much has been posted under it', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('1', 'design', 12)] })

		expect(rows(wrapper)).toHaveLength(1)
		expect(rows(wrapper)[0].text()).toContain('#design')
		expect(rows(wrapper)[0].text()).toContain('12 public posts')
	})

	it('says so rather than showing a nought for a tag nothing was posted with', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('1', 'moss')] })

		expect(rows(wrapper)[0].text()).toContain('Nothing posted with it yet')
	})

	it('links a featured tag to the timeline for it', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('1', 'design', 3)] })

		expect(wrapper.findAllComponents(RouterLinkStub)[0].props('to'))
			.toEqual({ name: 'tags', params: { tag: 'design' } })
	})

	// the whole point of the suggestions route: the first question here is
	// "which of mine?", not "what is a hashtag"
	it('offers the tags the reader already posts with', async () => {
		const wrapper = await mountEditor({ suggestions: [{ name: 'moss' }, { name: 'design' }] })

		expect(chips(wrapper).map((chip) => chip.text())).toEqual(['#moss', '#design'])
	})

	it('ignores a suggestion with no name rather than offering a bare #', async () => {
		const wrapper = await mountEditor({ suggestions: [{ name: '' }, { name: 'moss' }] })

		expect(chips(wrapper)).toHaveLength(1)
	})

	it('features a suggestion by its name when it is clicked', async () => {
		const wrapper = await mountEditor({ suggestions: [{ name: 'moss' }] })
		axios.post.mockResolvedValue({ data: featuredTag('7', 'moss') })

		await chips(wrapper)[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/featured_tags`, { name: 'moss' })
		expect(rows(wrapper)[0].text()).toContain('#moss')
		expect(chips(wrapper)).toHaveLength(0)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('leaves half-typed text alone when a suggestion is clicked instead', async () => {
		const wrapper = await mountEditor({ suggestions: [{ name: 'moss' }] })
		axios.post.mockResolvedValue({ data: featuredTag('7', 'moss') })

		await typeTag(wrapper, 'trav')
		await chips(wrapper)[0].trigger('click')
		await flushPromises()

		expect(wrapper.find('.featured-tags-settings__field input').element.value).toBe('trav')
	})

	it('features what was typed, leaving the server to normalise it', async () => {
		const wrapper = await mountEditor()
		axios.post.mockResolvedValue({ data: featuredTag('7', 'travel') })

		await typeTag(wrapper, '#Travel')
		await submit(wrapper)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/featured_tags`, { name: '#Travel' })
		expect(wrapper.find('.featured-tags-settings__field input').element.value).toBe('')
	})

	it('will not send an empty box', async () => {
		const wrapper = await mountEditor()

		await submit(wrapper)
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('says what a hashtag may contain instead of sending something that is not one', async () => {
		const wrapper = await mountEditor()

		await typeTag(wrapper, 'two words')
		await submit(wrapper)
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('A hashtag is letters, numbers and underscores')
	})

	// the server answers the existing entity rather than refusing, and a second
	// row for the same tag would be a list that disagrees with the profile
	it('replaces the row when the server answers with one already featured', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('1', 'design', 12)] })
		axios.post.mockResolvedValue({ data: featuredTag('1', 'design', 12) })

		await typeTag(wrapper, 'design')
		await submit(wrapper)
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
	})

	// the ceiling is the server's to know: this asks and reports the refusal
	// rather than counting rows and hiding the control
	it('asks even when the ceiling is presumably reached, and keeps the reason on the page', async () => {
		const featured = Array.from({ length: 10 }, (_, i) => featuredTag(String(i + 1), `tag${i}`))
		const wrapper = await mountEditor({ featured })
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'Limit of 10 featured tags reached' } } })

		await typeTag(wrapper, 'eleven')
		await submit(wrapper)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${API}/featured_tags`, { name: 'eleven' })
		expect(showError).toHaveBeenCalledWith('Limit of 10 featured tags reached')
		expect(wrapper.text()).toContain('Limit of 10 featured tags reached')
	})

	it('drops the reason once a tag lands', async () => {
		const wrapper = await mountEditor()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Limit of 10 featured tags reached' } } })

		await typeTag(wrapper, 'eleven')
		await submit(wrapper)
		await flushPromises()

		axios.post.mockResolvedValue({ data: featuredTag('7', 'moss') })
		await typeTag(wrapper, 'moss')
		await submit(wrapper)
		await flushPromises()

		expect(wrapper.text()).not.toContain('Limit of 10 featured tags reached')
	})

	it('says something of its own when the refusal carried no reason', async () => {
		const wrapper = await mountEditor()
		axios.post.mockRejectedValue(new Error('network'))

		await typeTag(wrapper, 'moss')
		await submit(wrapper)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not feature that hashtag')
	})

	it('unfeatures a tag by its id', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('4', 'design', 12)] })
		axios.delete.mockResolvedValue({ data: {} })

		await rows(wrapper)[0].find('button').trigger('click')
		await flushPromises()

		expect(axios.delete).toHaveBeenCalledWith(`${API}/featured_tags/4`)
		expect(rows(wrapper)).toHaveLength(0)
	})

	// it may be worth suggesting again now, and only the server knows
	it('asks for the suggestions again after unfeaturing one', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('4', 'design', 12)] })
		axios.delete.mockResolvedValue({ data: {} })
		axios.get.mockClear()

		await rows(wrapper)[0].find('button').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/featured_tags/suggestions`)
		expect(axios.get).not.toHaveBeenCalledWith(`${API}/featured_tags`)
	})

	it('keeps the row when the server would not drop it', async () => {
		const wrapper = await mountEditor({ featured: [featuredTag('4', 'design', 12)] })
		axios.delete.mockRejectedValue({ response: { data: { error: 'Record not found' } } })

		await rows(wrapper)[0].find('button').trigger('click')
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(showError).toHaveBeenCalledWith('Record not found')
	})

	it('says so when the list itself cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('nope'))

		const wrapper = mount(FeaturedTagsSettings, {
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load the hashtags you feature')
		expect(wrapper.text()).toContain('You feature no hashtags yet.')
	})

	// a shortcut that did not load is not worth a message; the box still works
	it('stays quiet when only the suggestions cannot be read', async () => {
		axios.get.mockImplementation((url) => (url.endsWith('/suggestions')
			? Promise.reject(new Error('nope'))
			: Promise.resolve({ data: [] })))

		const wrapper = mount(FeaturedTagsSettings, {
			global: { stubs: { RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(showError).not.toHaveBeenCalled()
		expect(chips(wrapper)).toHaveLength(0)
		expect(wrapper.find('.featured-tags-settings__add').exists()).toBe(true)
	})
})
