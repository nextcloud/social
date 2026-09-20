/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import TrendingHashtags from '../../../src/components/TrendingHashtags.vue'
import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

/**
 * @param {string} name the hashtag, without its '#'
 * @param {number} uses how often it was used in the window
 * @return {object} a Tag entity as the trends endpoint hands one over
 */
function tag(name, uses) {
	return {
		name,
		url: `https://cloud.example.org/tags/${name}`,
		history: [{ day: '1789000000', uses: String(uses), accounts: '0' }],
	}
}

/**
 * One hashtag as the peer-trends endpoint reports it.
 *
 * @param {string} name the tag, without its '#'
 * @param {string[]} servers who says it is busy
 * @param {object} extra `local` and `uses`, where a test cares
 * @return {object} a PeerTag entity
 */
function peerTag(name, servers, extra = {}) {
	return {
		name,
		servers,
		servers_count: servers.length,
		uses: 0,
		local: false,
		...extra,
	}
}

/** What the peer-trends endpoint answers when a test says nothing about it. */
const NO_PEERS = { tags: [], sources: [] }

/**
 * Answers the three requests the page makes.
 *
 * @param {object[]} tags what the trends endpoint returns
 * @param {object[]|Error} followed what the followed-tags endpoint returns
 * @param {object|Error|Function} peers what the peer-trends endpoint returns; a
 *                                      function is given the query, so a test
 *                                      can answer a search differently
 */
function serve(tags, followed = [], peers = NO_PEERS) {
	axios.get.mockImplementation(async (url, config = {}) => {
		if (url.endsWith('/followed_tags')) {
			if (followed instanceof Error) {
				throw followed
			}

			return { data: followed }
		}

		if (url.endsWith('/directories/hashtags')) {
			if (peers instanceof Error) {
				throw peers
			}

			return {
				data: typeof peers === 'function' ? peers(config?.params?.q ?? '') : peers,
			}
		}

		return { data: tags }
	})
}

function mountTrends({ isPublic = false } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: isPublic, cloudAddress: 'https://cloud.example.org' })

	return mount(TrendingHashtags, {
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
	})
}

const names = (wrapper) => wrapper.findAll('.trending__name').map((el) => el.text())
/**
 * @param {object} wrapper the mounted list
 * @param {string} label the window's name, as the reader reads it
 * @return {object} the button that chooses it
 */
function periodButton(wrapper, label) {
	return wrapper.findAll('.trending__periods button').find((button) => button.text() === label)
}

describe('TrendingHashtags', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		serve([])
	})

	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks for today over the whole ranking the server will give', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/trends/tags`, { params: { limit: 20, period: '1d' } })
		expect(names(wrapper)).toEqual(['#nextcloud'])
	})

	it('says how often each tag was used', async () => {
		serve([tag('nextcloud', 4), tag('fediverse', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findAll('.trending__count').map((el) => el.text())).toEqual(['4 posts', '1 post'])
	})

	it('draws each tag against the busiest one, which is the only comparison the numbers support', async () => {
		serve([tag('nextcloud', 10), tag('fediverse', 5), tag('vue', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		const widths = wrapper.findAll('.trending__bar-fill').map((el) => el.attributes('style'))
		expect(widths[0]).toContain('width: 100%')
		expect(widths[1]).toContain('width: 50%')
		// a floor, so the quietest tag is a bar rather than an empty track
		expect(widths[2]).toContain('width: 10%')
	})

	it('never draws an empty track for a tag that was used', async () => {
		serve([tag('nextcloud', 100), tag('quiet', 1)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findAll('.trending__bar-fill')[1].attributes('style')).toContain('width: 6%')
	})

	it('re-ranks over another window rather than relabelling this one', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()
		serve([tag('fediverse', 40)])

		await periodButton(wrapper, 'Last 10 days').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(`${API}/trends/tags`, { params: { limit: 20, period: '10d' } })
		expect(names(wrapper)).toEqual(['#fediverse'])
	})

	it('does not ask again for the window already on screen', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()
		axios.get.mockClear()

		await periodButton(wrapper, 'Today').trigger('click')
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('ignores the answer to a window the reader has moved on from', async () => {
		let resolveSlow
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/followed_tags')) {
				return { data: [] }
			}

			if (url.includes('period')) {
				return { data: [] }
			}

			return new Promise((resolve) => {
				resolveSlow = resolve
			})
		})
		const wrapper = mountTrends()
		// the first window's answer is still in flight
		serve([tag('fediverse', 40)])
		await periodButton(wrapper, 'Last hour').trigger('click')
		await flushPromises()
		resolveSlow?.({ data: [tag('stale', 999)] })
		await flushPromises()

		expect(names(wrapper)).toEqual(['#fediverse'])
	})

	it('reads which tags are followed once for the page, not once per button', async () => {
		serve([tag('nextcloud', 4), tag('fediverse', 2)], [tag('fediverse', 2)])
		const wrapper = mountTrends()
		await flushPromises()

		const lookups = axios.get.mock.calls.filter(([url]) => /\/tags\/[^/]+$/.test(url))
		expect(lookups).toHaveLength(0)
		expect(axios.get).toHaveBeenCalledWith(`${API}/followed_tags`, { params: { limit: 200 } })

		const buttons = wrapper.findAllComponents(HashtagFollowButton)
		expect(buttons[0].text()).toBe('Follow')
		expect(buttons[1].text()).toBe('Following')
	})

	it('leaves each button to ask for itself when that list could not be read', async () => {
		serve([tag('nextcloud', 4)], new Error('nope'))
		mountTrends()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/tags/nextcloud`)
	})

	it('asks nobody about following on a page with no account behind it', async () => {
		serve([tag('nextcloud', 4)])
		mountTrends({ isPublic: true })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalledWith(`${API}/followed_tags`, expect.anything())
	})

	it('keeps a follow made here when the window changes', async () => {
		serve([tag('nextcloud', 4)], [])
		const wrapper = mountTrends()
		await flushPromises()

		wrapper.findComponent(HashtagFollowButton).vm.$emit('changed', { tag: 'nextcloud', following: true })
		await flushPromises()
		await periodButton(wrapper, 'Last 3 days').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent(HashtagFollowButton).text()).toBe('Following')
	})

	it('says a quiet window is quiet rather than that nobody uses hashtags', async () => {
		serve([])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.find('.empty-content__description').text())
			.toBe('Nothing was tagged in this stretch of time. Try a longer one.')

		serve([])
		await periodButton(wrapper, 'Last 10 days').trigger('click')
		await flushPromises()

		// over ten days, an empty list really is an instance with no hashtags
		expect(wrapper.find('.empty-content__description').text())
			.toBe('Hashtags people are using will appear here.')
	})

	it('offers the reader another go when the ranking could not be read', async () => {
		axios.get.mockRejectedValue(new Error('busy'))
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.find('.trending__error').text()).toContain('Could not load this')

		serve([tag('nextcloud', 4)])
		await wrapper.find('.trending__error button').trigger('click')
		await flushPromises()

		expect(names(wrapper)).toEqual(['#nextcloud'])
	})

	it('links each tag at its timeline', async () => {
		serve([tag('nextcloud', 4)])
		const wrapper = mountTrends()
		await flushPromises()

		expect(wrapper.findComponent(RouterLinkStub).props('to'))
			.toEqual({ name: 'tags', params: { tag: 'nextcloud' } })
	})

	describe('what other servers are talking about', () => {
		const peerNames = (wrapper) => wrapper.findAll('.peertags__name').map((el) => el.text())
		const where = (wrapper) => wrapper.findAll('.peertags__where').map((el) => el.text())

		it('asks the other servers when the page opens, without anything typed', async () => {
			serve([tag('nextcloud', 4)], [], { tags: [peerTag('berlin', ['mastodon.social'])], sources: [] })
			const wrapper = mountTrends()
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(
				`${API}/directories/hashtags`,
				{ params: { q: '', limit: 20 } },
			)
			expect(peerNames(wrapper)).toEqual(['#berlin'])
			expect(wrapper.text()).toContain('Busy elsewhere in the fediverse')
		})

		it('names the servers rather than counting them', async () => {
			serve([], [], { tags: [peerTag('berlin', ['mastodon.social', 'misskey.io'])], sources: [] })
			const wrapper = mountTrends()
			await flushPromises()

			expect(where(wrapper)).toEqual(['Busy on mastodon.social, misskey.io'])
		})

		it('counts the servers it has no room to name', async () => {
			serve([], [], {
				tags: [peerTag('berlin', ['one.example', 'two.example', 'three.example', 'four.example'])],
				sources: [],
			})
			const wrapper = mountTrends()
			await flushPromises()

			expect(where(wrapper)[0]).toContain('and 2 other servers')
		})

		it('says when a tag is busy here as well as out there', async () => {
			serve([], [], {
				tags: [peerTag('berlin', ['cloud.example.org', 'mastodon.social'], { local: true })],
				sources: [],
			})
			const wrapper = mountTrends()
			await flushPromises()

			expect(where(wrapper)).toEqual(['Busy on mastodon.social, and used here'])
		})

		/**
		 * The same tag in both lists would read as a second opinion rather
		 * than as the answer to a different question.
		 */
		it('does not repeat a tag that is already in the list above', async () => {
			serve([tag('nextcloud', 4)], [], {
				tags: [
					peerTag('nextcloud', ['cloud.example.org', 'mastodon.social'], { local: true }),
					peerTag('berlin', ['mastodon.social']),
				],
				sources: [],
			})
			const wrapper = mountTrends()
			await flushPromises()

			expect(names(wrapper)).toEqual(['#nextcloud'])
			expect(peerNames(wrapper)).toEqual(['#berlin'])
		})

		it('says nothing about elsewhere when only this server named anything', async () => {
			serve([tag('nextcloud', 4)], [], {
				tags: [peerTag('nextcloud', ['cloud.example.org'], { local: true })],
				sources: [],
			})
			const wrapper = mountTrends()
			await flushPromises()

			expect(wrapper.text()).not.toContain('Busy elsewhere')
		})

		/**
		 * "No trending hashtags" above twenty of them reads as a broken page,
		 * and on the instances this exists for that is the normal case.
		 */
		it('points the empty state at what is below it rather than at a longer window', async () => {
			serve([], [], { tags: [peerTag('berlin', ['mastodon.social'])], sources: [] })
			const wrapper = mountTrends()
			await flushPromises()

			expect(wrapper.text()).toContain('Nothing is trending on this server')
			expect(wrapper.text()).toContain('What other servers are talking about is below.')
			expect(wrapper.text()).not.toContain('Try a longer one.')
		})

		it('still says try a longer window when there is nothing anywhere', async () => {
			serve([], [], NO_PEERS)
			const wrapper = mountTrends()
			await flushPromises()

			expect(wrapper.text()).toContain('Try a longer one.')
		})

		it('leaves the page alone when the other servers cannot be reached', async () => {
			serve([tag('nextcloud', 4)], [], new Error('network'))
			const wrapper = mountTrends()
			await flushPromises()

			expect(names(wrapper)).toEqual(['#nextcloud'])
			expect(wrapper.text()).not.toContain('Busy elsewhere')
		})
	})

	describe('finding a hashtag', () => {
		const peerNames = (wrapper) => wrapper.findAll('.peertags__name').map((el) => el.text())

		/**
		 * @param {object} wrapper the mounted page
		 * @param {string} text what the reader typed
		 */
		async function type(wrapper, text) {
			await wrapper.find('input[type="search"]').setValue(text)
			await vi.advanceTimersByTimeAsync(500)
			await flushPromises()
		}

		beforeEach(() => {
			vi.useFakeTimers()
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		it('asks every server about the word, and shows what they answer', async () => {
			serve([tag('nextcloud', 4)], [], (q) => (q === 'berlin'
				? { tags: [peerTag('berlin', ['mastodon.social'])], sources: [] }
				: NO_PEERS))
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'berlin')

			expect(axios.get).toHaveBeenCalledWith(
				`${API}/directories/hashtags`,
				{ params: { q: 'berlin', limit: 20 } },
			)
			expect(peerNames(wrapper)).toEqual(['#berlin'])
		})

		/**
		 * The list on screen ranks one window on one server. Filtering it by a
		 * word answers a question nobody asked.
		 */
		it('replaces the trending list rather than filtering it', async () => {
			serve([tag('nextcloud', 4)], [], (q) => (q === 'berlin'
				? { tags: [peerTag('berlin', ['mastodon.social'])], sources: [] }
				: NO_PEERS))
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'berlin')

			expect(names(wrapper)).toEqual([])
			expect(wrapper.find('.trending__periods').exists()).toBe(false)
		})

		it('waits for the typing to stop, so a word is one search', async () => {
			serve([], [], NO_PEERS)
			const wrapper = mountTrends()
			await flushPromises()
			const before = axios.get.mock.calls.length

			const field = wrapper.find('input[type="search"]')
			await field.setValue('be')
			await field.setValue('ber')
			await field.setValue('berl')
			await vi.advanceTimersByTimeAsync(500)
			await flushPromises()

			expect(axios.get.mock.calls.length - before).toBe(1)
		})

		it('asks nobody about a single letter', async () => {
			serve([], [], NO_PEERS)
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'b')

			expect(axios.get).toHaveBeenLastCalledWith(
				`${API}/directories/hashtags`,
				{ params: { q: '', limit: 20 } },
			)
		})

		it('says nobody is using it rather than showing an empty list', async () => {
			serve([tag('nextcloud', 4)], [], NO_PEERS)
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'nothingatall')

			expect(wrapper.text()).toContain('Nobody is using that')
		})

		it('says which servers did not answer, so an empty result can be read', async () => {
			serve([], [], {
				tags: [peerTag('berlin', ['up.example'])],
				sources: [
					{ host: 'up.example', label: 'up.example', status: 'ok' },
					{ host: 'down.example', label: 'down.example', status: 'failed' },
				],
			})
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'berlin')

			expect(wrapper.text()).toContain('down.example did not answer')
		})

		it('goes back to the trend when the box is emptied', async () => {
			serve([tag('nextcloud', 4)], [], NO_PEERS)
			const wrapper = mountTrends()
			await flushPromises()

			await type(wrapper, 'berlin')
			await type(wrapper, '')

			expect(names(wrapper)).toEqual(['#nextcloud'])
		})
	})
})
