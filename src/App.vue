<template>
	<div class="app-social">
		<div id="app-navigation" v-if="!serverData.public">
			<app-navigation :menu="menu"></app-navigation>
		</div>
		<div id="app-content">
			<router-view :key="$route.fullPath"></router-view>
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
	} from 'nextcloud-vue';
	import TimelineEntry from './components/TimelineEntry';
	import ProfileInfo from './components/ProfileInfo';

	export default {
		name: 'App',
		components: {
			PopoverMenu, AppNavigation, TimelineEntry, Multiselect, Avatar,
			ProfileInfo
		},
		data: function () {
			return {
				infoHidden: false,
				state: [],
			};
		},
		beforeMount: function() {
			// importing server data into the store
			const serverDataElmt = document.getElementById('serverData');
			if (serverDataElmt !== null) {
				this.$store.commit('setServerData', JSON.parse(document.getElementById('serverData').dataset.server));
			}

			let example = {
				message: 'Want to #DropDropbox? #DeleteGoogle? #decentralize? We got you covered, easy as a piece of 🥞\n' +
					'\n' +
					'Get started right now: https://nextcloud.com/signup',
				author: 'Nextcloud 📱☁️💻',
				authorId: '@nextcloud@mastodon.xyz',
				authorAvatar: OC.linkTo('social', 'img/nextcloud.png'),
				timestamp: '1 day ago'
			};
			let data = [];
			for (let i=0; i<3; i++) {
				example.id = Math.floor((Math.random() * 100));
				data.push(example);
			}
			data.push({
				message: 'Want to #DropDropbox? #DeleteGoogle? #decentralize? We got you covered, easy as a piece of 🥞\n' +
					'\n' +
					'Get started right now: https://nextcloud.com/signup',
				author: 'Admin☁️💻',
				authorId: 'admin',
				authorAvatar: OC.linkTo('social', 'img/nextcloud.png'),
				timestamp: '1 day ago'
			})
			this.$store.commit('addToTimeline', data);
		},
		methods: {
			hideInfo() {
				this.infoHidden = true;
			}
		},
		computed: {
			url: function() {
				return OC.linkTo('social', 'img/nextcloud.png');
			},
			currentUser: function() {
				return OC.getCurrentUser();
			},
			socialId: function() {
				return '@' + OC.getCurrentUser().uid + '@' + OC.getHost();
			},
			timeline: function() {
				return this.$store.getters.getTimeline;
			},
			serverData: function() {
				return this.$store.getters.getServerData;
			},
			menu: function () {
				let defaultCategories = [
					{
						id: 'social-timeline',
						classes: [],
						icon: 'icon-category-monitoring',
						text: t('social', 'Timeline'),
						router: {
							name: 'timeline',
						},
					},
					{
						id: 'social-account',
						classes: [],
						icon: 'icon-user',
						text: t('social', 'Your account'),
						router: {
							name: 'profile',
							params: {account: this.currentUser.uid }
						},
					},
					{
						id: 'social-friends',
						classes: [],
						href: '#',
						icon: 'icon-category-social',
						text: t('social', 'Friends'),
					},
					{
						id: 'social-favorites',
						classes: [],
						href: '#',
						icon: 'icon-favorite',
						text: t('social', 'Favorites'),
					},
					{
						id: 'social-direct-messages',
						classes: [],
						href: '#',
						icon: 'icon-comment',
						utils: {
							counter: 3,
						},
						text: t('social', 'Direct messages'),
					},
				];
				return {
					items: defaultCategories,
					loading: false
				}
			}
		}
	}
</script>
