/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import Discover from '../../../src/views/Discover.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	n: (app, one, many, count) => String(count),
	// the view now renders ActorAvatar, and @nextcloud/vue reads the text
	// direction out of this module while its own module body runs
	isRTL: () => false,
	getLanguage: () => 'en',
	getCanonicalLocale: () => 'en',
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => String(count),
}))

/**
 * The methods are exercised directly against a plain state object: what is
 * worth pinning is which route each tab asks and how a pack behaves, none of
 * which needs a DOM.
 *
 * @param {object} overrides anything to change about the state
 * @return {object} a `this` for calling the component's methods against
 */
function view(overrides = {}) {
	return {
		active: 'accounts',
		loading: false,
		error: null,
		posts: [],
		tags: [],
		accounts: [],
		packs: [],
		openPack: null,
		packLoading: false,
		followingPack: '',
		loaded: [],
		...Discover.methods,
		...overrides,
	}
}

describe('Discover', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	describe('tabs', () => {
		it('puts People first, because that is the question somebody has here', () => {
			const tabs = Discover.computed.tabs.call({})

			expect(tabs[0].value).toBe('accounts')
			expect(tabs[1].value).toBe('packs')
		})

		it('offers Videos beside Pictures, and the links last', () => {
			const tabs = Discover.computed.tabs.call({})

			expect(tabs.map((tab) => tab.value))
				.toEqual(['accounts', 'packs', 'posts', 'videos', 'tags', 'news'])
		})

		it('lets the switcher hand the pick back rather than routing', () => {
			// the control pushes a route where an option carries one, and
			// which of these four lists is on screen is not a route
			const tabs = Discover.computed.tabs.call({})

			expect(tabs.every((tab) => tab.to === undefined)).toBe(true)
			expect(tabs.every((tab) => tab.icon !== undefined)).toBe(true)
		})

		it('opens on People rather than on content', () => {
			expect(Discover.data().active).toBe('accounts')
		})

		/**
		 * Without `media` the route answers with every post that has an
		 * attachment, which is Pixelfed's meaning of it and would put the same
		 * video in both grids.
		 */
		it('asks for one kind of media per grid', async () => {
			get.mockResolvedValue({ data: [] })
			const self = view()

			await self.load.call(self, 'posts')
			await self.load.call(self, 'videos')

			expect(get).toHaveBeenNthCalledWith(1, '/apps/social/api/v2/discover/posts?media=image')
			expect(get).toHaveBeenNthCalledWith(2, '/apps/social/api/v2/discover/posts?media=video')
		})

		it('keeps the two grids apart', () => {
			const posts = [{ id: '1' }]
			const videos = [{ id: '2' }, { id: '3' }]

			expect(Discover.computed.media.call({ active: 'posts', posts, videos })).toBe(posts)
			expect(Discover.computed.media.call({ active: 'videos', posts, videos })).toBe(videos)
		})

		it('says which kind the empty grid is empty of', () => {
			expect(Discover.computed.emptyMediaDescription.call({ active: 'videos' }))
				.toContain('Videos')
			expect(Discover.computed.emptyMediaDescription.call({ active: 'posts' }))
				.toContain('Pictures')
		})

		it('keeps where a suggestion came from, so a colleague can be said to be one', async () => {
			get.mockResolvedValue({ data: [
				{ sources: ['featured'], account: { id: '1', acct: 'bob@cloud.example' } },
				{ sources: ['friends_of_friends'], account: { id: '2', acct: 'carol@remote.example' } },
			] })
			const self = view()

			await self.load.call(self, 'accounts')

			expect(self.accounts.map((a) => Discover.methods.isColleague(a))).toEqual([true, false])
		})

		it('asks the starter pack index for the packs tab', async () => {
			get.mockResolvedValue({ data: [{ id: 'a', name: 'A', size: 2 }] })
			const self = view()

			await self.load.call(self, 'packs')

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs')
			expect(self.packs).toHaveLength(1)
		})

		it('does not ask twice for a tab already loaded', async () => {
			get.mockResolvedValue({ data: [] })
			const self = view({ loaded: ['packs'] })

			await self.load.call(self, 'packs')

			expect(get).not.toHaveBeenCalled()
		})

		it('reports a failure as its own state rather than an empty list', async () => {
			get.mockRejectedValue(new Error('boom'))
			const self = view()

			await self.load.call(self, 'packs')

			expect(self.error).toBeTruthy()
		})
	})

	describe('opening a pack', () => {
		it('shows the name at once and fills in the accounts after', async () => {
			const pack = { id: 'friends', name: 'Friends', size: 2 }
			get.mockResolvedValue({ data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }] } })
			const self = view({ packs: [pack] })

			await self.loadPack.call(self, 'friends')

			expect(get).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs/friends')
			expect(self.openPack.accounts).toHaveLength(1)
			expect(self.packLoading).toBe(false)
		})

		/**
		 * A handle that will not resolve is usually a server that is busy, and
		 * the next try often works — so the pack says which ones and offers
		 * the try. Asking again is the same call that opened it.
		 */
		it('can be asked again for the accounts it could not reach', async () => {
			const pack = { id: 'friends', name: 'Friends', size: 3 }
			get.mockResolvedValue({
				data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }], unresolved: ['c@d.e', 'f@g.h'] },
			})
			const self = view({ packs: [pack] })

			await self.loadPack.call(self, 'friends')
			expect(self.openPack.unresolved).toEqual(['c@d.e', 'f@g.h'])

			get.mockResolvedValue({
				data: { ...pack, accounts: [{ id: '1', acct: 'a@b.c' }, { id: '2', acct: 'c@d.e' }], unresolved: [] },
			})
			await self.loadPack.call(self, 'friends')

			expect(get).toHaveBeenCalledTimes(2)
			expect(self.openPack.accounts).toHaveLength(2)
			expect(self.openPack.unresolved).toEqual([])
		})

		it('closes back to the list without leaving an error behind', () => {
			const self = view({ openPack: { id: 'x' }, error: 'something' })

			self.closePack.call(self)

			expect(self.openPack).toBeNull()
			expect(self.error).toBeNull()
		})

		it('does not leave half a pack on screen when the fetch fails', async () => {
			get.mockRejectedValue(new Error('gone'))
			const self = view({ packs: [{ id: 'x', name: 'X' }] })

			await self.loadPack.call(self, 'x')

			expect(self.openPack).toBeNull()
			expect(self.error).toBeTruthy()
			expect(self.packLoading).toBe(false)
		})
	})

	describe('following a pack', () => {
		/**
		 * The server skips whoever it cannot reach rather than failing the lot,
		 * so the message has to say how many were actually followed — not how
		 * many are in the pack.
		 */
		it('reports how many were actually followed, not how many were asked', async () => {
			post.mockResolvedValue({ data: { followed: ['a@b.c', 'd@e.f'] } })
			const self = view()

			await self.followPack.call(self, 'friends')

			expect(post).toHaveBeenCalledWith('/apps/social/api/v1/starter_packs/friends/follow')
			expect(showSuccess).toHaveBeenCalledWith('2')
		})

		it('says none rather than crashing when the answer carries no list', async () => {
			post.mockResolvedValue({ data: {} })
			const self = view()

			await self.followPack.call(self, 'friends')

			expect(showSuccess).toHaveBeenCalledWith('0')
		})

		it('tells the reader when it could not follow anybody', async () => {
			post.mockRejectedValue(new Error('nope'))
			const self = view()

			await self.followPack.call(self, 'friends')

			expect(showError).toHaveBeenCalled()
			expect(self.followingPack).toBe('')
		})

		it('clears the in-flight marker whichever way it ends', async () => {
			post.mockResolvedValue({ data: { followed: [] } })
			const self = view()

			await self.followPack.call(self, 'friends')

			expect(self.followingPack).toBe('')
		})
	})
})
