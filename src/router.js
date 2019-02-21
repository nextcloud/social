/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 * @copyright Copyright (c) 2018 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
import Vue from 'vue'
import Router from 'vue-router'

// Dynamic loading
const Timeline = () => import('./views/Timeline')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers')

Vue.use(Router)

export default new Router({
	mode: 'history',
	// if index.php is in the url AND we got this far, then it's working:
	// let's keep using index.php in the url
	base: OC.generateUrl(''),
	linkActiveClass: 'active',
	routes: [
		{
			path: '/:index(index.php/)?apps/social/',
			redirect: { name: 'timeline' }
		},
		{
			path: '/:index(index.php/)?apps/social/timeline/:type?',
			components: {
				default: Timeline
			},
			props: true,
			name: 'timeline',
			children: [
				{
					path: 'tags/:tag',
					name: 'tags'
				}
			]
		},
		{
			path: '/:index(index.php/)?apps/social/@:account',
			components: {
				default: Profile,
				details: ProfileTimeline
			},
			props: true,
			children: [
				{
					path: '',
					name: 'profile',
					components: {
						details: ProfileTimeline
					}
				},
				{
					path: 'followers',
					name: 'profile.followers',
					components: {
						details: ProfileFollowers
					}
				},
				{
					path: 'following',
					name: 'profile.following',

					components: {
						details: ProfileFollowers
					}
				}
			]
		}
	]
})
