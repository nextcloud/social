import { createRouter, createWebHistory } from 'vue-router'

const Timeline = () => import('./views/Timeline.vue')
const TimelineSinglePost = () => import('./views/TimelineSinglePost.vue')
const Profile = () => import(/* webpackChunkName: "profile" */'./views/Profile.vue')
const ProfileTimeline = () => import(/* webpackChunkName: "profile" */'./views/ProfileTimeline.vue')
const ProfileFollowers = () => import(/* webpackChunkName: "profile" */'./views/ProfileFollowers.vue')

function getBase() {
	if (window.OC && window.OC.webroot) {
		return window.OC.webroot + '/apps/social/'
	}
	const path = window.location.pathname
	const match = path.match(/^(.+?)\/apps\/social\//)
	return match ? match[1] + '/apps/social/' : '/apps/social/'
}

export default createRouter({
	history: createWebHistory(getBase()),
	base: getBase(),
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
