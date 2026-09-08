/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import timeline from '../../../src/store/timeline.js'
import logger from '../../../src/services/logger.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const freshState = () => ({
	statuses: {},
	timeline: [],
	parentsTimeline: [],
	type: 'home',
	params: {},
	account: '',
	composerDisplayStatus: false,
	searchQuery: '',
})

const makeStatus = (id, extra = {}) => ({
	id,
	uri: `https://cloud.example.org/users/alice/statuses/${id}`,
	content: `<p>post ${id}</p>`,
	created_at: '2026-01-01T10:00:00.000Z',
	favourited: false,
	favourites_count: 0,
	reblogged: false,
	reblogs_count: 0,
	account: { acct: 'alice', display_name: 'Alice' },
	...extra,
})

describe('timeline store mutations', () => {
	const { mutations } = timeline
	let state

	beforeEach(() => {
		state = freshState()
	})

	it('addToStatuses indexes a status by id and also the boosted status of a boost wrapper', () => {
		const inner = makeStatus('1')
		const wrapper = makeStatus('2', { reblog: inner, content: '' })

		mutations.addToStatuses(state, wrapper)

		expect(state.statuses['2']).toBe(wrapper)
		expect(state.statuses['1']).toBe(inner)
		expect(state.timeline).toEqual([])
	})

	it('addToTimeline appends ids in the order given and indexes every status', () => {
		const [a, b, c] = [makeStatus('1'), makeStatus('2'), makeStatus('3')]

		mutations.addToTimeline(state, [b, a, c])

		expect(state.timeline).toEqual(['2', '1', '3'])
		expect(Object.keys(state.statuses).sort()).toEqual(['1', '2', '3'])
		expect(state.parentsTimeline).toEqual([])
	})

	it('addToTimeline de-duplicates ids but refreshes the stored status', () => {
		mutations.addToTimeline(state, [makeStatus('1'), makeStatus('2')])
		const edited = makeStatus('2', { content: '<p>edited</p>' })

		mutations.addToTimeline(state, [edited, makeStatus('3')])

		expect(state.timeline).toEqual(['1', '2', '3'])
		expect(state.statuses['2']).toBe(edited)
	})

	it('addToTimeline with a status context puts ancestors and descendants in separate lists', () => {
		const parent = makeStatus('1')
		const reply = makeStatus('3')

		mutations.addToTimeline(state, { ancestors: [parent], descendants: [reply] })

		expect(state.parentsTimeline).toEqual(['1'])
		expect(state.timeline).toEqual(['3'])
		expect(state.statuses['1']).toBe(parent)
		expect(state.statuses['3']).toBe(reply)

		mutations.addToTimeline(state, { ancestors: [parent], descendants: [reply, makeStatus('4')] })

		expect(state.parentsTimeline).toEqual(['1'])
		expect(state.timeline).toEqual(['3', '4'])
	})

	it('removeStatus drops the id from the timeline but keeps the status indexed', () => {
		mutations.addToTimeline(state, [makeStatus('1'), makeStatus('2'), makeStatus('3')])

		mutations.removeStatus(state, { id: '2' })

		expect(state.timeline).toEqual(['1', '3'])
		expect(state.statuses['2']).toBeDefined()
	})

	it('removeStatus ignores ids that are not in the timeline', () => {
		mutations.addToTimeline(state, { ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })

		mutations.removeStatus(state, { id: 'missing' })

		expect(state.timeline).toEqual(['2'])
		expect(state.parentsTimeline).toEqual(['1'])
	})

	it('removeStatus leaves a non-empty parentsTimeline intact when the status is only in the timeline', () => {
		mutations.addToTimeline(state, {
			ancestors: [makeStatus('1'), makeStatus('2')],
			descendants: [makeStatus('3')],
		})

		mutations.removeStatus(state, { id: '3' })

		expect(state.timeline).toEqual([])
		expect(state.parentsTimeline).toEqual(['1', '2'])
	})

	it('removeStatus drops the status from both lists when it is in both', () => {
		mutations.addToTimeline(state, { ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })
		// the same status also sits in the timeline
		state.timeline.push('1')

		mutations.removeStatus(state, { id: '1' })

		expect(state.timeline).toEqual(['2'])
		expect(state.parentsTimeline).toEqual([])
	})

	it('removeStatusesByActor drops exactly that actor\'s statuses from both lists and the index', () => {
		const byBob = (id) => makeStatus(id, { account: { id: '22', acct: 'bob@remote.tld' } })
		const byCarol = (id) => makeStatus(id, { account: { id: '33', acct: 'carol@remote.tld' } })
		mutations.addToTimeline(state, {
			ancestors: [byBob('1'), byCarol('2')],
			descendants: [byBob('3'), byCarol('4')],
		})

		mutations.removeStatusesByActor(state, '22')

		expect(state.timeline).toEqual(['4'])
		expect(state.parentsTimeline).toEqual(['2'])
		expect(Object.keys(state.statuses).sort()).toEqual(['2', '4'])
	})

	it('removeStatusesByActor also matches a numeric account id against string status ids', () => {
		mutations.addToTimeline(state, [makeStatus('1', { account: { id: '22', acct: 'bob@remote.tld' } })])

		mutations.removeStatusesByActor(state, 22)

		expect(state.timeline).toEqual([])
		expect(state.statuses['1']).toBeUndefined()
	})

	it('removeStatusesByActor drops boosts that wrap a status of the actor', () => {
		const inner = makeStatus('1', { account: { id: '22', acct: 'bob@remote.tld' } })
		const boost = makeStatus('2', { reblog: inner, content: '', account: { id: '33', acct: 'carol@remote.tld' } })
		mutations.addToTimeline(state, [boost, makeStatus('3', { account: { id: '33', acct: 'carol@remote.tld' } })])

		mutations.removeStatusesByActor(state, '22')

		expect(state.timeline).toEqual(['3'])
		expect(state.statuses['1']).toBeUndefined()
		expect(state.statuses['2']).toBeUndefined()
		expect(state.statuses['3']).toBeDefined()
	})

	it('removeStatusesByActor leaves the state untouched when the actor has no statuses', () => {
		mutations.addToTimeline(state, [makeStatus('1', { account: { id: '33', acct: 'carol@remote.tld' } })])
		const statusesBefore = state.statuses

		mutations.removeStatusesByActor(state, '22')

		expect(state.timeline).toEqual(['1'])
		expect(state.statuses).toBe(statusesBefore)
	})

	it('resetTimeline empties both id lists but keeps the index and the type', () => {
		state.type = 'tags'
		mutations.addToTimeline(state, { ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })

		mutations.resetTimeline(state)

		expect(state.timeline).toEqual([])
		expect(state.parentsTimeline).toEqual([])
		expect(state.statuses['1']).toBeDefined()
		expect(state.type).toBe('tags')
	})

	it('setters replace their field', () => {
		mutations.setTimelineType(state, 'federated')
		mutations.setTimelineParams(state, { tag: 'nextcloud' })
		mutations.setAccount(state, 'bob@remote.tld')
		mutations.setSearchQuery(state, 'hello')
		mutations.setComposerDisplayStatus(state, true)

		expect(state).toMatchObject({
			type: 'federated',
			params: { tag: 'nextcloud' },
			account: 'bob@remote.tld',
			searchQuery: 'hello',
			composerDisplayStatus: true,
		})
	})

	it('likeStatus marks the status favourited and bumps the counter without touching the payload object', () => {
		const original = makeStatus('1', { favourites_count: 4 })
		mutations.addToStatuses(state, original)

		mutations.likeStatus(state, { status: original })

		expect(state.statuses['1']).toMatchObject({ favourited: true, favourites_count: 5 })
		expect(original).toMatchObject({ favourited: false, favourites_count: 4 })
	})

	it('unlikeStatus reverses a like', () => {
		mutations.addToStatuses(state, makeStatus('1', { favourited: true, favourites_count: 1 }))

		mutations.unlikeStatus(state, { status: { id: '1' } })

		expect(state.statuses['1']).toMatchObject({ favourited: false, favourites_count: 0 })
	})

	it('boostStatus and unboostStatus toggle reblogged and the reblogs counter', () => {
		mutations.addToStatuses(state, makeStatus('1', { reblogs_count: 2 }))

		mutations.boostStatus(state, { status: { id: '1' } })
		expect(state.statuses['1']).toMatchObject({ reblogged: true, reblogs_count: 3 })

		mutations.unboostStatus(state, { status: { id: '1' } })
		expect(state.statuses['1']).toMatchObject({ reblogged: false, reblogs_count: 2 })
	})

	it('like and boost mutations are no-ops for statuses that are not indexed', () => {
		mutations.likeStatus(state, { status: { id: 'x' } })
		mutations.unlikeStatus(state, { status: { id: 'x' } })
		mutations.boostStatus(state, { status: { id: 'x' } })
		mutations.unboostStatus(state, { status: { id: 'x' } })

		expect(state.statuses).toEqual({})
	})

	it('like and boost only touch the entry whose id is given, for boost wrappers and boosted statuses alike', () => {
		const inner = makeStatus('1', { favourites_count: 1, reblogs_count: 1 })
		const wrapper = makeStatus('2', { reblog: inner, content: '' })
		mutations.addToStatuses(state, wrapper)

		mutations.likeStatus(state, { status: inner })
		expect(state.statuses['1']).toMatchObject({ favourited: true, favourites_count: 2 })
		expect(state.statuses['2']).toMatchObject({ favourited: false, favourites_count: 0 })

		mutations.boostStatus(state, { status: wrapper })
		expect(state.statuses['2']).toMatchObject({ reblogged: true, reblogs_count: 1 })
		expect(state.statuses['1']).toMatchObject({ reblogged: false, reblogs_count: 1 })
	})

	it('updateStatus replaces an indexed status and ignores unknown ones', () => {
		mutations.addToStatuses(state, makeStatus('1'))
		const edited = makeStatus('1', { content: '<p>edited</p>' })

		mutations.updateStatus(state, edited)
		mutations.updateStatus(state, makeStatus('9'))

		expect(state.statuses['1']).toBe(edited)
		expect(state.statuses['9']).toBeUndefined()
	})
})

describe('timeline store getters', () => {
	const { getters, mutations } = timeline
	let state

	beforeEach(() => {
		vi.clearAllMocks()
		state = freshState()
	})

	it('getTimeline returns statuses newest first regardless of insertion order and skips unknown ids', () => {
		const older = makeStatus('1', { created_at: '2026-01-01T10:00:00.000Z' })
		const newer = makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z' })
		mutations.addToTimeline(state, [older, newer])
		state.timeline.push('ghost')

		expect(getters.getTimeline(state)).toEqual([newer, older])
	})

	it('getTimeline filters by content, display name and acct, case-insensitively', () => {
		mutations.addToTimeline(state, [
			makeStatus('1', { created_at: '2026-01-03T10:00:00.000Z', content: '<p>Hello Fediverse</p>' }),
			makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z', content: '<p>nothing</p>', account: { acct: 'bob@remote.tld', display_name: 'Bob' } }),
			makeStatus('3', { created_at: '2026-01-01T10:00:00.000Z', content: '<p>nothing</p>', account: { acct: 'carol', display_name: 'Carol FEDI' } }),
		])

		state.searchQuery = 'fedi'
		expect(getters.getTimeline(state).map(s => s.id)).toEqual(['1', '3'])

		state.searchQuery = 'REMOTE.TLD'
		expect(getters.getTimeline(state).map(s => s.id)).toEqual(['2'])

		state.searchQuery = ''
		expect(getters.getTimeline(state)).toHaveLength(3)
	})

	it('getParentsTimeline sorts and filters the ancestors the same way', () => {
		mutations.addToTimeline(state, {
			ancestors: [
				makeStatus('1', { created_at: '2026-01-01T10:00:00.000Z', content: '<p>root</p>' }),
				makeStatus('2', { created_at: '2026-01-02T10:00:00.000Z', content: '<p>middle</p>' }),
			],
			descendants: [makeStatus('3', { content: '<p>root reply</p>' })],
		})

		expect(getters.getParentsTimeline(state).map(s => s.id)).toEqual(['2', '1'])

		state.searchQuery = 'root'
		expect(getters.getParentsTimeline(state).map(s => s.id)).toEqual(['1'])
	})

	it('getStatus, getSinglePost, getSearchQuery and getComposerDisplayStatus read from the state', () => {
		const post = makeStatus('7')
		mutations.addToStatuses(state, post)
		state.params = { singlePost: '7' }
		state.searchQuery = 'q'
		state.composerDisplayStatus = true

		expect(getters.getStatus(state)('7')).toBe(post)
		expect(getters.getStatus(state)('8')).toBeUndefined()
		expect(getters.getSinglePost(state)).toBe(post)
		expect(getters.getSearchQuery(state)).toBe('q')
		expect(getters.getComposerDisplayStatus(state)).toBe(true)
	})

	it('getPostFromTimeline returns the status, or warns and returns undefined', () => {
		const post = makeStatus('7')
		mutations.addToStatuses(state, post)

		expect(getters.getPostFromTimeline(state)('7')).toBe(post)
		expect(logger.warn).not.toHaveBeenCalled()

		expect(getters.getPostFromTimeline(state)('8')).toBeUndefined()
		expect(logger.warn).toHaveBeenCalledWith('Could not find status in timeline', { statusId: '8' })
	})
})

describe('timeline store actions', () => {
	let store
	const tl = () => store.state.timeline

	beforeEach(() => {
		vi.resetAllMocks()
		// The module keeps a single state object that its actions read directly,
		// so it is reset in place rather than replaced.
		Object.assign(timeline.state, freshState())
		store = createStore({ modules: { timeline } })
	})

	it('changeTimelineType resets the lists and stores type and params', async () => {
		store.commit('addToTimeline', { ancestors: [makeStatus('1')], descendants: [makeStatus('2')] })
		store.commit('setAccount', 'bob@remote.tld')

		await store.dispatch('changeTimelineType', { type: 'tags', params: { tag: 'nextcloud' } })

		expect(tl()).toMatchObject({
			timeline: [],
			parentsTimeline: [],
			type: 'tags',
			params: { tag: 'nextcloud' },
			account: '',
		})
		expect(tl().statuses['1']).toBeDefined()
	})

	it('changeTimelineTypeAccount switches to the statuses of one account', async () => {
		store.commit('addToTimeline', [makeStatus('1')])

		await store.dispatch('changeTimelineTypeAccount', 'bob@remote.tld')

		expect(tl()).toMatchObject({ timeline: [], type: 'account', account: 'bob@remote.tld' })
	})

	it('addToTimeline action commits the given statuses', async () => {
		await store.dispatch('addToTimeline', [makeStatus('1'), makeStatus('2')])

		expect(tl().timeline).toEqual(['1', '2'])
	})

	describe('createMedia', () => {
		it('uploads the file as multipart form data and returns the media entity', async () => {
			const file = new File(['png'], 'cat.png', { type: 'image/png' })
			const media = { id: '42', url: 'https://cloud.example.org/media/42' }
			axios.post.mockResolvedValue({ data: media })

			const result = await store.dispatch('createMedia', file)

			expect(result).toEqual(media)
			const [url, body, config] = axios.post.mock.calls[0]
			expect(url).toBe(`${API}/media`)
			expect(body).toBeInstanceOf(FormData)
			expect(body.get('file')).toBe(file)
			expect([...body.keys()]).toEqual(['file'])
			expect(config.headers['Content-Type']).toBe('multipart/form-data')
		})

		it('shows an error and resolves to undefined when the upload fails', async () => {
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('createMedia', new File(['x'], 'x.txt'))).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not upload the attachment')
			expect(logger.error).toHaveBeenCalledWith('Failed to create a media', { error: expect.any(Error) })
		})
	})

	describe('post', () => {
		it('POSTs the status payload to /statuses', async () => {
			axios.post.mockResolvedValue({ data: { id: '1' } })
			const payload = { status: 'hello', visibility: 'public', spoiler_text: '', media_ids: ['42'] }

			await store.dispatch('post', payload)

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses`, payload)
			expect(showError).not.toHaveBeenCalled()
		})

		it('reports a failure instead of throwing', async () => {
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('post', { status: 'x' })).resolves.toBeUndefined()

			expect(showError).toHaveBeenCalledWith('Could not send the post')
			expect(logger.error).toHaveBeenCalledWith('Failed to create a status', { error: expect.any(Error) })
		})
	})

	describe('postEdit', () => {
		it('PUTs the new content and stores the status returned by the server', async () => {
			const status = makeStatus('1')
			store.commit('addToTimeline', [status])
			const edited = makeStatus('1', { content: '<p>edited</p>' })
			axios.put.mockResolvedValue({ data: edited })

			const response = await store.dispatch('postEdit', { status, content: 'edited', spoiler_text: 'cw', sensitive: true })

			expect(axios.put).toHaveBeenCalledWith(`${API}/statuses/1`, { status: 'edited', spoiler_text: 'cw', sensitive: true })
			expect(tl().statuses['1']).toEqual(edited)
			expect(response.data).toEqual(edited)
		})

		it('leaves the status untouched and shows an error when editing fails', async () => {
			const status = makeStatus('1')
			store.commit('addToTimeline', [status])
			axios.put.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('postEdit', { status, content: 'x', spoiler_text: '', sensitive: false })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toEqual(status)
			expect(showError).toHaveBeenCalledWith('Could not save the changes to the post')
		})
	})

	describe('postDelete', () => {
		it('removes the post from the timeline before sending DELETE with the post uri', async () => {
			const status = makeStatus('1')
			store.commit('addToTimeline', [status, makeStatus('2')])
			let timelineDuringRequest
			axios.delete.mockImplementation(async () => {
				timelineDuringRequest = [...tl().timeline]
				return { data: { status: 1, result: [] } }
			})

			await store.dispatch('postDelete', status)

			expect(axios.delete).toHaveBeenCalledWith(`${API}/post?id=${status.uri}`)
			expect(timelineDuringRequest).toEqual(['2'])
			expect(tl().timeline).toEqual(['2'])
			expect(showError).not.toHaveBeenCalled()
		})

		it('re-indexes the status, restores it to the timeline and shows an error when the deletion fails', async () => {
			const status = makeStatus('1')
			store.commit('addToTimeline', [status, makeStatus('2')])
			axios.delete.mockRejectedValue(new Error('boom'))

			await store.dispatch('postDelete', status)

			expect(tl().statuses['1']).toEqual(status)
			expect(tl().timeline).toContain('1')
			expect(tl().timeline).toEqual(['2', '1'])
			expect(showError).toHaveBeenCalledWith('Could not delete the post')
		})
	})

	describe.each([
		['postLike', 'favourite', 'favourited', 'favourites_count', false, 1, 'Could not like the post'],
		['postUnlike', 'unfavourite', 'favourited', 'favourites_count', true, -1, 'Could not remove the like'],
		['postBoost', 'reblog', 'reblogged', 'reblogs_count', false, 1, 'Could not boost the post'],
		['postUnBoost', 'unreblog', 'reblogged', 'reblogs_count', true, -1, 'Could not undo the boost'],
	])('%s', (action, endpoint, flag, counter, initialFlag, delta, errorMessage) => {
		const initial = () => makeStatus('1', { [flag]: initialFlag, [counter]: 3 })

		it(`applies the change optimistically, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = initial()
			store.commit('addToTimeline', [status])
			const serverCopy = makeStatus('1', { [flag]: !initialFlag, [counter]: 3 + delta, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store.dispatch(action, { status })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ [flag]: !initialFlag, [counter]: 3 + delta })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('rolls the optimistic change back and shows an error when the request fails', async () => {
			const status = initial()
			store.commit('addToTimeline', [status])
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch(action, { status })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ [flag]: initialFlag, [counter]: 3 })
			expect(tl().timeline).toEqual(['1'])
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe.each([
		['bookmark', true, 'Could not bookmark the post'],
		['unbookmark', false, 'Could not remove the bookmark'],
	])('postBookmark (%s)', (endpoint, bookmarked, errorMessage) => {
		it(`flips the flag, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = makeStatus('1', { bookmarked: !bookmarked })
			store.commit('addToTimeline', [status])
			const serverCopy = makeStatus('1', { bookmarked, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store.dispatch('postBookmark', { status, bookmarked })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ bookmarked })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('puts the flag back and reports when the server refuses', async () => {
			const status = makeStatus('1', { bookmarked: !bookmarked })
			store.commit('addToTimeline', [status])
			axios.post.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('postBookmark', { status, bookmarked })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ bookmarked: !bookmarked })
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe('unbookmarking on the bookmarks timeline', () => {
		it('takes the post off the list it is no longer on', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { bookmarked: false }) })
			store.commit('setTimelineType', 'bookmarks')
			store.commit('addToTimeline', [
				makeStatus('1', { bookmarked: true }),
				makeStatus('2', { bookmarked: true }),
			])

			await store.dispatch('postBookmark', { status: makeStatus('1', { bookmarked: true }), bookmarked: false })

			expect(tl().timeline).toEqual(['2'])
		})
	})

	describe.each([
		['pin', true, 'Could not pin the post'],
		['unpin', false, 'Could not unpin the post'],
	])('postPin (%s)', (endpoint, pinned, errorMessage) => {
		it(`flips the flag optimistically, POSTs to /statuses/:id/${endpoint} and stores the server copy`, async () => {
			const status = makeStatus('1', { pinned: !pinned })
			store.commit('addToTimeline', [status])
			const serverCopy = makeStatus('1', { pinned, content: '<p>from server</p>' })
			let duringRequest
			axios.post.mockImplementation(async () => {
				duringRequest = { ...tl().statuses['1'] }
				return { data: serverCopy }
			})

			const response = await store.dispatch('postPin', { status, pinned })

			expect(axios.post).toHaveBeenCalledWith(`${API}/statuses/1/${endpoint}`)
			expect(duringRequest).toMatchObject({ pinned })
			expect(tl().statuses['1']).toEqual(serverCopy)
			expect(response.data).toEqual(serverCopy)
			expect(showError).not.toHaveBeenCalled()
		})

		it('rolls the flag back and reports when the server refuses', async () => {
			const status = makeStatus('1', { pinned: !pinned })
			store.commit('addToTimeline', [status])
			axios.post.mockRejectedValue(new Error('too many pins'))

			await expect(store.dispatch('postPin', { status, pinned })).resolves.toBeUndefined()

			expect(tl().statuses['1']).toMatchObject({ pinned: !pinned })
			expect(showError).toHaveBeenCalledWith(errorMessage)
		})
	})

	describe('postUnlike on the Liked timeline', () => {
		const liked = () => makeStatus('1', { favourited: true, favourites_count: 3 })

		it('takes the post off the list of liked posts', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { favourited: false, favourites_count: 2 }) })
			store.commit('setTimelineType', 'favourites')
			store.commit('addToTimeline', [liked(), makeStatus('2', { favourited: true })])

			await store.dispatch('postUnlike', { status: liked() })

			expect(tl().timeline).toEqual(['2'])
			expect(tl().statuses['1']).toMatchObject({ favourited: false })
		})

		it('leaves the post where it is on every other timeline', async () => {
			axios.post.mockResolvedValue({ data: makeStatus('1', { favourited: false, favourites_count: 2 }) })
			store.commit('setTimelineType', 'home')
			store.commit('addToTimeline', [liked()])

			await store.dispatch('postUnlike', { status: liked() })

			expect(tl().timeline).toEqual(['1'])
			expect(tl().statuses['1']).toMatchObject({ favourited: false })
		})

		it('puts the post back unchanged when the server refuses the unlike', async () => {
			axios.post.mockRejectedValue(new Error('boom'))
			store.commit('setTimelineType', 'favourites')
			store.commit('addToTimeline', [liked()])

			await store.dispatch('postUnlike', { status: liked() })

			expect(tl().timeline).toEqual(['1'])
			// exactly the pre-unlike state: restoring the status AND re-liking
			// it used to leave favourites_count one too high
			expect(tl().statuses['1']).toMatchObject({ favourited: true, favourites_count: 3 })
			expect(showError).toHaveBeenCalledWith('Could not remove the like')
		})
	})

	describe('fetchTimeline', () => {
		const statuses = [makeStatus('1'), makeStatus('2')]

		beforeEach(() => {
			axios.get.mockResolvedValue({ data: statuses })
		})

		it.each([
			['home', {}, `${API}/timelines/home`, { limit: 15 }],
			['direct', {}, `${API}/timelines/direct`, { limit: 15 }],
			['favourites', {}, `${API}/timelines/favourites`, { limit: 15 }],
			['notifications', {}, `${API}/notifications`, { limit: 15 }],
			['timeline', {}, `${API}/timelines/public`, { limit: 15, local: true }],
			['federated', {}, `${API}/timelines/public`, { limit: 15 }],
			['tags', { tag: 'nextcloud' }, `${API}/timelines/tag/nextcloud`, { limit: 15 }],
		])('requests the %s timeline from its endpoint and appends the result', async (type, params, url, query) => {
			await store.dispatch('changeTimelineType', { type, params })

			const result = await store.dispatch('fetchTimeline')

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(axios.get).toHaveBeenCalledWith(url, { params: query })
			expect(result).toEqual(statuses)
			expect(tl().timeline).toEqual(['1', '2'])
		})

		it('requests the statuses of one account for the account timeline', async () => {
			await store.dispatch('changeTimelineTypeAccount', 'bob@remote.tld')

			await store.dispatch('fetchTimeline')

			expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/bob@remote.tld/statuses`, { params: { limit: 15 } })
			expect(tl().timeline).toEqual(['1', '2'])
		})

		it('loads the context of a single post and splits it into parents and replies', async () => {
			const parent = makeStatus('1')
			const reply = makeStatus('3')
			axios.get.mockResolvedValue({ data: { ancestors: [parent], descendants: [reply] } })
			await store.dispatch('changeTimelineType', { type: 'single-post', params: { id: '2', singlePost: '2' } })

			await store.dispatch('fetchTimeline')

			expect(axios.get).toHaveBeenCalledWith(`${API}/statuses/2/context`, { params: { limit: 15 } })
			expect(tl().parentsTimeline).toEqual(['1'])
			expect(tl().timeline).toEqual(['3'])
		})

		it('forwards since, max_id and an explicit limit', async () => {
			await store.dispatch('fetchTimeline', { since: '100', max_id: '50', limit: 30 })

			expect(axios.get).toHaveBeenCalledWith(`${API}/timelines/home`, { params: { since: '100', max_id: '50', limit: 30 } })
		})

		it('does not swallow request errors and leaves the timeline untouched', async () => {
			axios.get.mockRejectedValue(new Error('boom'))

			await expect(store.dispatch('fetchTimeline')).rejects.toThrow('boom')

			expect(tl().timeline).toEqual([])
			expect(showError).not.toHaveBeenCalled()
		})

		it('refreshTimeline re-fetches the current timeline', async () => {
			await store.dispatch('changeTimelineType', { type: 'federated', params: {} })

			const result = await store.dispatch('refreshTimeline')

			expect(axios.get).toHaveBeenCalledWith(`${API}/timelines/public`, { params: { limit: 15 } })
			expect(result).toEqual(statuses)
		})
	})
})
