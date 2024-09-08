/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

// Dynamic loading
const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')

Vue.use(Router)

export default new Router({
	mode: 'history',
	// if index.php is in the url AND we got this far, then it's working:
	// let's keep using index.php in the url
	base: generateUrl(''),
	linkActiveClass: 'active',
	routes: [
		{
			path: '/:index(index.php/)?apps/social/',
			redirect: { name: 'timeline' },
		},
		{
			path: '/:index(index.php/)?apps/social/timeline/:type?',
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
			path: '/:index(index.php/)?apps/social/@:account',
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
			path: '/:index(index.php/)?apps/social/@:account/:id',
			components: {
				default: TimelineSinglePost,
			},
			props: true,
			name: 'single-post',
		},
		{
			path: '/:index(index.php/)?apps/social/ostatus/follow',
			components: {
				default: Profile,
				details: ProfileTimeline,
			},
			props: true,
		},
	],
})
