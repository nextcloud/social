/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHistory } from 'vue-router'
import { transitionsWanted } from './utils/viewTransition.js'
import { generateUrl } from '@nextcloud/router'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')
const FollowRequests = () => import(/* webpackChunkName: "profile" */'./views/FollowRequests.vue')
const BlockedAccounts = () => import(/* webpackChunkName: "profile" */'./views/BlockedAccounts.vue')

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

/** @return {Promise<void>} resolves on the next paint */
const nextFrame = () => new Promise((resolve) => window.requestAnimationFrame(resolve))

/**
 * Route changes go through a view transition where the browser has one, so
 * moving between a timeline, a profile and a single post reads as one surface
 * changing rather than two unrelated pages. Browsers without the API, and
 * viewers who asked for less motion, navigate exactly as before.
 */
router.beforeResolve(async () => {
	if (!transitionsWanted()) {
		return true
	}

	// the transition is started here and finished by the DOM update Vue
	// performs right after this hook resolves
	await new Promise((resolve) => {
		document.startViewTransition(() => {
			resolve()

			return nextFrame()
		})
	})

	return true
})

export default router
