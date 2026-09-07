/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import router from '../../src/router.js'

// The views are lazy-loaded chunks; navigation would otherwise pull in the
// whole component tree. Stubs keep the routing table under test.
vi.mock('../../src/views/Timeline.vue', () => ({ default: { name: 'Timeline', render: () => null } }))
vi.mock('../../src/views/TimelineSinglePost.vue', () => ({ default: { name: 'TimelineSinglePost', render: () => null } }))
vi.mock('../../src/views/Profile.vue', () => ({ default: { name: 'Profile', render: () => null } }))
vi.mock('../../src/views/ProfileTimeline.vue', () => ({ default: { name: 'ProfileTimeline', render: () => null } }))
vi.mock('../../src/views/ProfileFollowers.vue', () => ({ default: { name: 'ProfileFollowers', render: () => null } }))

describe('router', () => {
	it('is served under the app web root and uses the "active" link class', () => {
		expect(router.options.history.base).toBe('/apps/social')
		expect(router.options.linkActiveClass).toBe('active')
	})

	it('redirects / to the default timeline', async () => {
		await router.push('/')

		const route = router.currentRoute.value
		expect(route.name).toBe('timeline')
		expect(route.path).toBe('/timeline')
		expect(route.redirectedFrom.path).toBe('/')
		expect(route.params.type).toBeFalsy()
	})

	it('exposes the timeline type as a route param passed to the view as a prop', () => {
		const route = router.resolve('/timeline/notifications')

		expect(route.name).toBe('timeline')
		expect(route.params).toEqual({ type: 'notifications' })
		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].props.default).toBe(true)
	})

	it.each(['home', 'direct', 'federated', 'favourites'])('builds /timeline/%s from the named timeline route', (type) => {
		expect(router.resolve({ name: 'timeline', params: { type } }).fullPath).toBe(`/timeline/${type}`)
	})

	it('routes hashtag timelines to the "tags" child of the timeline route', () => {
		const route = router.resolve('/timeline/tags/nextcloud')

		expect(route.name).toBe('tags')
		expect(route.params.tag).toBe('nextcloud')
		expect(route.matched.map(record => record.name)).toEqual(['timeline', 'tags'])
		expect(router.resolve({ name: 'tags', params: { tag: 'nextcloud' } }).fullPath).toBe('/timeline/tags/nextcloud')
	})

	it('routes /@account to the profile with the profile timeline in the details view', () => {
		const route = router.resolve('/@alice')

		expect(route.name).toBe('profile')
		expect(route.params).toEqual({ account: 'alice' })
		expect(route.matched).toHaveLength(2)
		expect(route.matched[0].path).toBe('/@:account')
		expect(Object.keys(route.matched[0].components)).toEqual(['default', 'details'])
		expect(route.matched[0].props.default).toBe(true)
		expect(Object.keys(route.matched[1].components)).toEqual(['details'])
	})

	it('keeps remote handles intact in the account param', () => {
		expect(router.resolve('/@bob@remote.tld').params.account).toBe('bob@remote.tld')
		expect(router.resolve({ name: 'profile', params: { account: 'bob@remote.tld' } }).fullPath).toBe('/@bob@remote.tld')
	})

	it.each([
		['/@alice/followers', 'profile.followers'],
		['/@alice/following', 'profile.following'],
		['/@bob@remote.tld/followers', 'profile.followers'],
	])('routes %s to the %s child view rather than to a single post', (path, name) => {
		const route = router.resolve(path)

		expect(route.name).toBe(name)
		expect(route.params.id).toBeUndefined()
		expect(route.matched[0].path).toBe('/@:account')
		expect(Object.keys(route.matched[1].components)).toEqual(['details'])
	})

	it('routes /@account/:id to the single post view', () => {
		const route = router.resolve('/@alice/12345')

		expect(route.name).toBe('single-post')
		expect(route.params).toEqual({ account: 'alice', id: '12345' })
		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].props.default).toBe(true)
		expect(router.resolve({ name: 'single-post', params: { account: 'bob@remote.tld', id: '99' } }).fullPath).toBe('/@bob@remote.tld/99')
	})

	it('serves the OStatus follow page with the profile layout', () => {
		const route = router.resolve('/ostatus/follow')

		expect(route.matched).toHaveLength(1)
		expect(route.matched[0].path).toBe('/ostatus/follow')
		expect(route.name).toBeUndefined()
		expect(Object.keys(route.matched[0].components)).toEqual(['default', 'details'])
		expect(route.matched[0].props.default).toBe(true)
	})

	it('does not match unknown paths', () => {
		expect(router.resolve('/nope/nope').matched).toEqual([])
	})
})

describe('router base detection', () => {
	afterEach(() => {
		window.OC.webroot = ''
		window.history.replaceState({}, '', '/')
	})

	it('prefers the server-provided web root', async () => {
		window.OC.webroot = '/nextcloud'
		vi.resetModules()

		const { default: freshRouter } = await import('../../src/router.js')

		expect(freshRouter.options.history.base).toBe('/nextcloud/apps/social')
	})

	it('falls back to the part of the current path before /apps/social/', async () => {
		window.history.replaceState({}, '', '/cloud/apps/social/timeline/home')
		vi.resetModules()

		const { default: freshRouter } = await import('../../src/router.js')

		expect(freshRouter.options.history.base).toBe('/cloud/apps/social')
	})

	it('uses /apps/social when nothing else is known', async () => {
		window.history.replaceState({}, '', '/somewhere/else')
		vi.resetModules()

		const { default: freshRouter } = await import('../../src/router.js')

		expect(freshRouter.options.history.base).toBe('/apps/social')
	})
})
