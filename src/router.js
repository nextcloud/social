/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')
const FollowRequests = () => import(/* webpackChunkName: "profile" */'./views/FollowRequests.vue')
const BlockedAccounts = () => import(/* webpackChunkName: "profile" */'./views/BlockedAccounts.vue')
const Discover = () => import('./views/Discover.vue')
const Migration = () => import(/* webpackChunkName: "profile" */'./views/Migration.vue')
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

const router = createRouter({
	history: createWebHistory(getBase()),
	linkActiveClass: 'active',
	/**
	 * Where to be after a navigation. Without this every route change landed
	 * at whatever offset the previous page happened to be at, and pressing
	 * Back out of a post left the reader at the top of the timeline with no
	 * way of finding their place again.
	 *
	 * @param {object} to the route being entered
	 * @param {object} from the route being left
	 * @param {object|null} savedPosition where Back or Forward was last at
	 * @return {object|Promise<object>} the scroll target
	 */
	scrollBehavior(to, from, savedPosition) {
		if (savedPosition) {
			return savedPosition
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
			path: '/migration',
			components: {
				default: Migration,
			},
			name: 'migration',
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
