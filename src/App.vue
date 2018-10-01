<template>
	<div id="content" class="app-social">
		<div id="app-navigation">
			<app-navigation :menu="menu">
				<!--<template slot="settings-content">Settings</template>-->
			</app-navigation>
		</div>
		<div id="app-content">
			<div class="social__container">
				<h2>🎉 Nextcloud becomes part of the federated social networks!</h2>
			</div>
		</div>
	</div>
</template>

<script>
	import {
		PopoverMenu,
		AppNavigation
	} from 'nextcloud-vue';

	export default {
		name: 'App',
		components: {
			PopoverMenu, AppNavigation
		},
		data: function () {
			return {
				isOpen: false,
				// example popover in the content
				menuPopover: [
					{
						icon: 'icon-delete',
						text: 'Delete item',
						action: () => {
							alert('Deleted!');
						}
					},
					{
						icon: 'icon-user',
						text: 'Nextcloud website',
						action: () => {},
						href: 'https://nextcloud.com'
					},
					{
						icon: 'icon-details',
						longtext: 'Add item',
						action: () => {
							alert('details');
						}
					}
				]
			};
		},
		computed: {
			// App navigation
			menu: function () {
				let defaultCategories = [
					{
						id: 'social-timeline',
						classes: [],
						href: '#',
						icon: 'icon-category-monitoring',
						text: t('social', 'Timeline'),
					},
					{
						id: 'social-your-posts',
						classes: [],
						href: '#',
						icon: 'icon-user',
						text: t('social', 'Your posts'),
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
					/*{
						caption: true,
						text: t('social', 'Popular topics'),
					},
					{
						id: 'social-topic-nextcloud',
						classes: [],
						icon: 'icon-tag',
						href: '#',
						utils: {
							actions: [
								{
									icon: 'icon-delete',
									text: t('settings', 'Remove topic'),
									action: function () {
										console.log('remove')
									}
								}
							]
						},
						text: t('settings', '#nextcloud'),
					},
					{
						id: 'social-topic-mastodon',
						classes: [],
						icon: 'icon-tag',
						href: '#',
						utils: {
							actions: [
								{
									icon: 'icon-delete',
									text: t('settings', 'Remove topic'),
									action: function () {
										console.log('remove')
									}
								}
							]
						},
						text: t('social', '#mastodon'),
					},
					{
						id: 'social-topic-privacy',
						classes: [],
						icon: 'icon-tag',
						href: '#',
						utils: {
							actions: [
								{
									icon: 'icon-delete',
									text: t('settings', 'Remove topic'),
									action: function () {
										console.log('remove')
									}
								}
							]
						},
						text: t('social', '#privacy'),
					},*/
				];
				return {
					items: defaultCategories,
					loading: false
				}
			}
		}
	}
</script>
