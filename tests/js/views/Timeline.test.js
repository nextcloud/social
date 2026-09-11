/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import Timeline from '../../../src/views/Timeline.vue'
import FirstPostCelebration from '../../../src/components/FirstPostCelebration.vue'
import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import HashtagFollowedList from '../../../src/components/HashtagFollowedList.vue'
import eventBus from '../../../src/services/eventBus.js'
import account from '../../../src/store/account.js'
import errors from '../../../src/store/errors.js'
import settings from '../../../src/store/settings.js'
import timeline from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const pristine = {
	account: structuredClone(account.state),
	timeline: structuredClone(timeline.state),
}

const ComposerStub = {
	name: 'Composer',
	props: ['defaultVisibility', 'initialMention'],
	template: '<div class="composer-stub" />',
}
const TimelineListStub = {
	name: 'TimelineList',
	props: ['type', 'showParents', 'reverseOrder'],
	template: '<ul class="timeline-list-stub" />',
}

const nextcloud = {
	id: 'https://mastodon.xyz/users/nextcloud',
	url: 'https://mastodon.xyz/users/nextcloud',
	acct: 'nextcloud@mastodon.xyz',
	username: 'nextcloud',
	display_name: 'Nextcloud',
}

let store
let dispatch

const makeStore = (serverData = {}) => {
	Object.assign(account.state, structuredClone(pristine.account))
	Object.assign(timeline.state, structuredClone(pristine.timeline))
	store = createStore({
		modules: {
			timeline,
			settings,
			errors,
			// network actions are replaced, the synchronous ones stay real
			account: { ...account, actions: { ...account.actions, fetchAccountInfo: vi.fn(), followAccount: vi.fn() } },
		},
	})
	store.commit('setServerData', { public: false, cloudAddress: 'https://cloud.example.org', firstrun: false, ...serverData })
	dispatch = vi.spyOn(store, 'dispatch')
	return store
}

const mountTimeline = (route = {}) => mount(Timeline, {
	global: {
		plugins: [store],
		mocks: { $route: { name: 'timeline', params: {}, ...route } },
		stubs: { Composer: ComposerStub, TimelineList: TimelineListStub, RouterLink: RouterLinkStub },
	},
})

describe('Timeline', () => {
	beforeEach(() => {
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('switches the store to the home timeline when no type is in the route', () => {
		const wrapper = mountTimeline()
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type: 'home', params: {} })
		expect(store.state.timeline.type).toBe('home')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('home')
		expect(wrapper.find('h2').exists()).toBe(false)
	})

	it.each(['direct', 'timeline', 'federated', 'favourites'])('switches the store to the %s timeline from the route', (type) => {
		const wrapper = mountTimeline({ params: { type } })
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type, params: {} })
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe(type)
	})

	it('resets the previously loaded posts when switching', () => {
		store.commit('addToTimeline', [{ id: 'old', created_at: '2026-01-01T00:00:00Z' }])
		mountTimeline({ params: { type: 'federated' } })
		expect(store.state.timeline.timeline).toEqual([])
	})

	// the types are the ones the sidebar and the store really use: `timeline`
	// is Local (the store sends `local: true` for it) and the liked timeline is
	// `favourites`, so Local used to be headed "Global timeline" and Liked
	// posts fell through to "Home timeline"
	it.each([
		[{ name: 'timeline', params: {} }, 'Home timeline', false],
		[{ name: 'timeline', params: { type: 'direct' } }, 'Direct messages', false],
		[{ name: 'timeline', params: { type: 'notifications' } }, 'Notifications', true],
		[{ name: 'timeline', params: { type: 'timeline' } }, 'Local timeline', false],
		[{ name: 'timeline', params: { type: 'federated' } }, 'Global timeline', false],
		[{ name: 'timeline', params: { type: 'favourites' } }, 'Liked posts', false],
		[{ name: 'timeline', params: { type: 'bookmarks' } }, 'Bookmarks', false],
	])('names the timeline %o for a reader who cannot see which one it is', (route, heading, visible) => {
		const wrapper = mountTimeline(route)
		const title = wrapper.find('h1')

		// every view has a heading now; only the two that always showed one stay visible
		expect(title.text()).toBe(heading)
		expect(title.classes('hidden-visually')).toBe(!visible)
	})

	it('loads a hashtag timeline with the tag as parameter and shows the tag as heading', () => {
		const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })
		expect(dispatch).toHaveBeenCalledWith('changeTimelineType', { type: 'tags', params: { tag: 'nextcloud' } })
		expect(wrapper.find('h1').text()).toBe('#nextcloud')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('tags')
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(true)
	})

	it('shows the composer on the home timeline without a preset visibility', () => {
		const composer = mountTimeline().findComponent(ComposerStub)
		expect(composer.exists()).toBe(true)
		expect(composer.props('defaultVisibility')).toBeUndefined()
	})

	it('presets the composer to direct messages on the direct timeline', () => {
		expect(mountTimeline({ params: { type: 'direct' } }).findComponent(ComposerStub).props('defaultVisibility')).toBe('direct')
	})

	it('hides the composer on the notifications timeline and titles it', () => {
		const wrapper = mountTimeline({ params: { type: 'notifications' } })
		expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		expect(wrapper.find('h1').text()).toBe('Notifications')
		expect(wrapper.findComponent(TimelineListStub).props('type')).toBe('notifications')
	})

	it('shows no search banner over a timeline it does not filter', async () => {
		// searching has its own route and asks the server; a banner saying
		// "Search: «…»" over the unfiltered timeline would be a lie
		const wrapper = mountTimeline()
		store.commit('setSearchQuery', 'fediverse')
		await nextTick()
		expect(wrapper.find('.search-active').exists()).toBe(false)
		expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
	})

	it('does not show the welcome box or look up the Nextcloud account after the first run', () => {
		const wrapper = mountTimeline()
		expect(wrapper.find('.social__welcome').exists()).toBe(false)
		expect(dispatch).not.toHaveBeenCalledWith('fetchAccountInfo', expect.anything())
	})

	describe('on the first run', () => {
		beforeEach(() => {
			makeStore({ firstrun: true })
		})

		it('welcomes the user with their social id and looks up the official account', () => {
			const wrapper = mountTimeline()
			expect(wrapper.find('.social__welcome').exists()).toBe(true)
			expect(wrapper.find('.social-id').text()).toBe('@alice@cloud.example.org')
			expect(dispatch).toHaveBeenCalledWith('fetchAccountInfo', 'nextcloud@mastodon.xyz')
		})

		it('can be closed', async () => {
			const wrapper = mountTimeline()
			await wrapper.find('.social__welcome .close').trigger('click')
			expect(wrapper.find('.social__welcome').exists()).toBe(false)
		})

		it('suggests following the Nextcloud account and dispatches the follow', async () => {
			const wrapper = mountTimeline()
			const follow = wrapper.find('.follow-nextcloud input[type="button"]')
			expect(follow.element.value).toBe('Follow Nextcloud on mastodon.xyz')
			await follow.trigger('click')
			expect(dispatch).toHaveBeenCalledWith('followAccount', { accountToFollow: 'nextcloud@mastodon.xyz' })
		})

		it('hides the suggestion while the account is unknown and once it is followed', async () => {
			const wrapper = mountTimeline()
			// v-show toggles display: none
			const hidden = () => wrapper.find('.follow-nextcloud').element.style.display === 'none'
			// unknown yet: treated as followed so nothing flashes
			expect(hidden()).toBe(true)

			store.commit('addAccount', { actorId: nextcloud.url, data: nextcloud })
			store.commit('addRelationship', { actorId: nextcloud.id, data: { id: nextcloud.id, following: false } })
			await nextTick()
			expect(hidden()).toBe(false)

			store.commit('followAccount', nextcloud.acct)
			await nextTick()
			expect(hidden()).toBe(true)
		})
	})
	// the composer emits `post-published` for every post that goes out; which
	// of them is worth a celebration is decided here
	describe('the first post somebody publishes here', () => {
		const alice = {
			id: '42',
			url: 'https://cloud.example.org/users/alice',
			acct: 'alice',
			username: 'alice',
			display_name: 'Alice',
		}

		/**
		 * Puts the reader's own account in the store, as the page load does.
		 *
		 * @param {number} statusesCount how much this account has posted before
		 */
		const readerWithPosts = (statusesCount) => {
			store.commit('addAccount', { actorId: alice.url, data: { ...alice, statuses_count: statusesCount } })
			store.commit('setCurrentAccount', 'alice@cloud.example.org')
		}

		const publish = async (wrapper) => {
			eventBus.emit('post-published', { id: '1', content: '<p>hello fediverse</p>' })
			await nextTick()
			return wrapper.findComponent(FirstPostCelebration)
		}

		beforeEach(() => {
			vi.useFakeTimers()
			// the bus is a module singleton: no timeline left over from another
			// test gets to answer for this one
			eventBus.all.clear()
			window.localStorage.clear()
			makeStore()
			readerWithPosts(0)
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		it('celebrates a first post, without the post waiting on it', async () => {
			const wrapper = mountTimeline()
			expect(wrapper.findComponent(FirstPostCelebration).exists()).toBe(false)

			const celebration = await publish(wrapper)

			expect(celebration.exists()).toBe(true)
			expect(celebration.text()).toContain('Your first post is out there')
			// the timeline the post lands in is untouched and still on screen
			expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)

			wrapper.unmount()
		})

		it('takes itself off screen again', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)

			vi.advanceTimersByTime(2600 + 300)
			await nextTick()

			expect(wrapper.findComponent(FirstPostCelebration).exists()).toBe(false)
			wrapper.unmount()
		})

		it('does not celebrate the second post', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)
			vi.advanceTimersByTime(2600 + 300)
			await nextTick()

			const again = await publish(wrapper)

			expect(again.exists()).toBe(false)
			wrapper.unmount()
		})

		it('never celebrates a reader who has posted before', async () => {
			readerWithPosts(1000)
			const wrapper = mountTimeline()

			expect((await publish(wrapper)).exists()).toBe(false)
			wrapper.unmount()
		})

		// a private window throws on every localStorage access; the timeline is
		// not allowed to go down with it
		it('survives a browser that refuses to store anything', async () => {
			vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
			vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
			const wrapper = mountTimeline()

			const celebration = await publish(wrapper)

			expect(celebration.exists()).toBe(true)
			expect(wrapper.findComponent(TimelineListStub).exists()).toBe(true)
			expect(wrapper.find('h1').text()).toBe('Home timeline')
			wrapper.unmount()
		})

		it('stops listening once the reader has navigated away', async () => {
			const wrapper = mountTimeline()
			wrapper.unmount()

			eventBus.emit('post-published', { id: '1' })
			await nextTick()

			expect(store.state.timeline.firstPostCelebration).toBe(false)
			expect(dispatch).not.toHaveBeenCalledWith('celebrateFirstPost')
		})

		it('leaves no celebration standing for the next timeline when the reader navigates away mid-flight', async () => {
			const wrapper = mountTimeline()
			await publish(wrapper)
			expect(store.state.timeline.firstPostCelebration).toBe(true)

			wrapper.unmount()

			expect(store.state.timeline.firstPostCelebration).toBe(false)
		})
	})

	describe('following the hashtag a timeline is of', () => {
		const tag = (name, following) => ({ name, url: `https://cloud.example.org/tags/${name}`, history: [], following })

		/**
		 * @param {boolean} following whether the viewer already follows #nextcloud
		 * @param {object[]} followed the tags `/followed_tags` answers with
		 */
		const answerWith = (following = false, followed = []) => {
			axios.get.mockImplementation((url) => Promise.resolve({
				data: url.endsWith('/followed_tags') ? followed : tag('nextcloud', following),
			}))
			axios.post.mockImplementation((url) => Promise.resolve({
				data: tag('nextcloud', url.endsWith('/follow')),
			}))
		}

		const mountTags = async () => {
			const wrapper = mountTimeline({ name: 'tags', params: { tag: 'nextcloud' } })
			await flushPromises()
			return wrapper
		}

		beforeEach(() => {
			vi.clearAllMocks()
			answerWith()
		})

		it('offers to follow the hashtag next to its heading', async () => {
			const wrapper = await mountTags()

			const row = wrapper.find('.timeline-heading-row')
			expect(row.find('h1').text()).toBe('#nextcloud')
			expect(axios.get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/tags/nextcloud')
			expect(row.find('button').text()).toBe('Follow')
			expect(row.find('button').attributes('aria-pressed')).toBe('false')
		})

		it('says which hashtags are already followed', async () => {
			answerWith(true)
			const wrapper = await mountTags()

			expect(wrapper.findComponent(HashtagFollowButton).find('button').text()).toBe('Following')
		})

		it('follows the hashtag from its own timeline', async () => {
			const wrapper = await mountTags()

			await wrapper.findComponent(HashtagFollowButton).find('button').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/tags/nextcloud/follow')
			expect(wrapper.findComponent(HashtagFollowButton).find('button').text()).toBe('Following')
		})

		it('has no hashtag to follow on any other timeline', async () => {
			expect((await mountTags()).findComponent(HashtagFollowButton).exists()).toBe(true)

			const wrapper = mountTimeline({ params: { type: 'federated' } })
			await flushPromises()

			expect(wrapper.findComponent(HashtagFollowButton).exists()).toBe(false)
			expect(wrapper.findComponent(HashtagFollowedList).exists()).toBe(false)
		})

		// nothing behind these routes answers without a viewer
		it('offers neither control to a reader who is not logged in', async () => {
			makeStore({ public: true })
			const wrapper = await mountTags()

			expect(wrapper.find('.timeline-heading-row button').exists()).toBe(false)
			expect(wrapper.find('.followed-hashtags').exists()).toBe(false)
			expect(axios.get).not.toHaveBeenCalled()

			makeStore({ public: false })
			const loggedIn = await mountTags()

			expect(loggedIn.find('.timeline-heading-row button').exists()).toBe(true)
			expect(loggedIn.find('.followed-hashtags').exists()).toBe(true)
		})

		it('lists the hashtags the reader follows, each linking to its timeline', async () => {
			answerWith(false, [tag('fediverse', true), tag('nextcloud', true)])
			const wrapper = await mountTags()

			await wrapper.find('.followed-hashtags__toggle').trigger('click')
			await flushPromises()

			const links = wrapper.findAllComponents(RouterLinkStub)
			expect(links.map((link) => link.text())).toEqual(['#fediverse', '#nextcloud'])
			expect(links[0].props('to')).toEqual({ name: 'tags', params: { tag: 'fediverse' } })
		})

		it('brings that list up to date when a hashtag is followed under it', async () => {
			answerWith(false, [tag('fediverse', true)])
			const wrapper = await mountTags()
			await wrapper.find('.followed-hashtags__toggle').trigger('click')
			await flushPromises()
			answerWith(true, [tag('fediverse', true), tag('nextcloud', true)])

			await wrapper.findComponent(HashtagFollowButton).find('button').trigger('click')
			await flushPromises()

			expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.text()))
				.toEqual(['#fediverse', '#nextcloud'])
		})
	})
})
