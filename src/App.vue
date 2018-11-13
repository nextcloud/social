<template>
	<div class="app-social">
		<div v-if="!serverData.public" id="app-navigation">
			<app-navigation :menu="menu" />
		</div>
		<div id="app-content">
			<router-view :key="$route.fullPath" />
		</div>
	</div>
</template>

<style scoped>
	.app-social {
		width: 100%;
	}
</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect,
	Avatar
} from 'nextcloud-vue'
import TimelineEntry from './components/TimelineEntry'
import ProfileInfo from './components/ProfileInfo'

export default {
	name: 'App',
	components: {
		PopoverMenu,
		AppNavigation,
		TimelineEntry,
		Multiselect,
		Avatar,
		ProfileInfo
	},
	data: function() {
		return {
			infoHidden: false,
			state: []
		}
	},
	computed: {
		url: function() {
			return OC.linkTo('social', 'img/nextcloud.png')
		},
		currentUser: function() {
			return OC.getCurrentUser()
		},
		socialId: function() {
			return '@' + OC.getCurrentUser().uid + '@' + OC.getHost()
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		serverData: function() {
			return this.$store.getters.getServerData
		},
		menu: function() {
			let defaultCategories = [
				{
					id: 'social-timeline',
					classes: [],
					icon: 'icon-category-monitoring',
					text: t('social', 'Timeline'),
					router: {
						name: 'timeline'
					}
				},
				{
					id: 'social-account',
					classes: [],
					icon: 'icon-user',
					text: t('social', 'Your account'),
					router: {
						name: 'profile',
						params: { account: this.currentUser.uid }
					}
				},
				{
					id: 'social-friends',
					classes: [],
					href: '#',
					icon: 'icon-category-social',
					text: t('social', 'Friends')
				},
				{
					id: 'social-favorites',
					classes: [],
					href: '#',
					icon: 'icon-favorite',
					text: t('social', 'Favorites')
				},
				{
					id: 'social-direct-messages',
					classes: [],
					href: '#',
					icon: 'icon-comment',
					utils: {
						counter: 3
					},
					text: t('social', 'Direct messages')
				}
			]
			return {
				items: defaultCategories,
				loading: false
			}
		}
	},
	beforeMount: function() {
		// importing server data into the store
		const serverDataElmt = document.getElementById('serverData')
		if (serverDataElmt !== null) {
			this.$store.commit('setServerData', JSON.parse(document.getElementById('serverData').dataset.server))
		}
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		}
	}
}
</script>
