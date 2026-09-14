/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

import eventBus from './services/eventBus.js'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')
const FollowRequests = () => import(/* webpackChunkName: "profile" */'./views/FollowRequests.vue')
const BlockedAccounts = () => import(/* webpackChunkName: "profile" */'./views/BlockedAccounts.vue')
const Discover = () => import('./views/Discover.vue')
const Settings = () => import(/* webpackChunkName: "profile" */'./views/Settings.vue')
const Statistics = () => import(/* webpackChunkName: "profile" */'./views/Statistics.vue')
const Search = () => import('./components/Search.vue')

/**
 * The path the app is actually served from, which is what the history base has
 * to be: vue-router can only strip a base that prefixes the current path, and
 * writes `base + path` into the address bar when it cannot.
 *
 * `generateUrl()` is the only thing that knows which of the two shapes this
 * instance uses — `/apps/social/` where mod_rewrite is working, and
 * `/index.php/apps/social/` where it is not. Building the base by hand from
 * the web root assumes the first and breaks the second: the address bar ends
 * up at `/nextcloud/apps/social/nextcloud/index.php/apps/social/`, and a
 * reload of that 404s.
 *
 * @return {string} the base path, without a trailing slash
 */
function getBase() {
	return generateUrl('/apps/social').replace(/\/+$/, '')
}

/**
 * How long a restored scroll offset waits for the page to be drawn before it is
 * applied anyway. Long enough for a list to come back from the store and for a
 * page of posts to arrive over a slow connection; short enough that a view that
 * never says anything still scrolls rather than staying where it was.
 */
const RENDER_TIMEOUT = 2000

/**
 * Resolves once the timeline has said it is on the page, or after
 * `RENDER_TIMEOUT`, whichever is first.
 *
 * @return {Promise<void>}
 */
function whenRendered() {
	return new Promise((resolve) => {
		const done = () => {
			clearTimeout(timer)
			eventBus.off('timeline:rendered', done)
			resolve()
		}

		const timer = setTimeout(done, RENDER_TIMEOUT)
		eventBus.on('timeline:rendered', done)
	})
}

const router = createRouter({
	history: createWebHistory(getBase()),
	linkActiveClass: 'active',
	/**
	 * Where to be after a navigation: back where Back came from, at what the
	 * address points to, and otherwise at the top.
	 *
	 * The offset Back remembers is waited for rather than returned outright.
	 * Vue Router scrolls as soon as the route has changed, and at that moment
	 * the timeline is one screen tall — the view has mounted but its posts have
	 * not been drawn yet — so the browser clamped a four-page offset to the
	 * bottom of what was there and the reader landed nowhere near where they
	 * had been.
	 *
	 * @param {object} to the route being entered
	 * @param {object} from the route being left
	 * @param {object|null} savedPosition where Back or Forward was last at
	 * @return {object|Promise<object>} the scroll target
	 */
	scrollBehavior(to, from, savedPosition) {
		if (savedPosition) {
			return whenRendered().then(() => savedPosition)
		}

		if (to.hash) {
			return { el: to.hash, behavior: 'smooth' }
		}

		return { top: 0 }
	},
	routes: [
		{
			path: '/',
			redirect: { name: 'timeline' },
		},
		{
			path: '/timeline/:type?',
			components: {
				default: Timeline,
			},
			props: true,
			name: 'timeline',
			children: [
				{
					path: 'tags/:tag',
					name: 'tags',
				},
				// a list's timeline: the id is the list's, and the page is the
				// same timeline view read through it
				{
					path: 'list/:id',
					name: 'list',
				},
			],
		},
		{
			path: '/@:account',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
			children: [
				{
					path: '',
					name: 'profile',
					components: {
						details: ProfileTimeline,
					},
				},
				{
					path: 'followers',
					name: 'profile.followers',
					components: {
						details: ProfileFollowers,
					},
				},
				{
					path: 'following',
					name: 'profile.following',

					components: {
						details: ProfileFollowers,
					},
				},
			],
		},
		{
			path: '/discover',
			components: {
				default: Discover,
			},
			name: 'discover',
		},
		{
			path: '/follow_requests',
			components: {
				default: FollowRequests,
			},
			name: 'follow-requests',
		},
		{
			path: '/blocked',
			components: {
				default: BlockedAccounts,
			},
			name: 'blocked-accounts',
		},
		{
			path: '/settings',
			components: {
				default: Settings,
			},
			name: 'settings',
		},
		{
			// Migration is a section of Settings now. The path stays so that a
			// bookmark, or a link somebody was sent, lands where the thing they
			// wanted actually is rather than on a 404.
			path: '/migration',
			redirect: { name: 'settings' },
		},
		{
			path: '/statistics',
			components: {
				default: Statistics,
			},
			name: 'statistics',
		},
		{
			path: '/search/:term?',
			components: {
				default: Search,
			},
			props: true,
			name: 'search',
		},
		{
			path: '/@:account/:id',
			components: {
				default: TimelineSinglePost,
			},
			props: true,
			name: 'single-post',
		},
		{
			path: '/ostatus/follow',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
		},
	],
})

export default router
