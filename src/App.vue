<template>
	<div id="content" class="app-social">
		<div id="app-navigation">
			<app-navigation :menu="menu"></app-navigation>
		</div>
		<div id="app-content">
			<div class="social__container">
				<div class="social__welcome">
					<a class="close icon-close" href="#"><span class="hidden-visually">Close</span></a>
					<h3>🎉{{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h3>
					<p>
						{{ t('social', 'We have automatically created a social account for you. Your social id is the same as the federated cloud id:') }}
						<span class="social-id">{{ socialId }}</span>
					</p>
				</div>
				<div class="social__timeline">
					<div class="new-post" data-id="">
						<div class="new-post-author">
							<div class="avatar currentUser" data-username="admin"><img src="/index.php/avatar/admin/32?v=1" alt=""></div>
						</div>
						<form class="new-post-form">
							<div class="author currentUser">Julius Haertl</div>
							<div contenteditable="true" class="message" data-placeholder="New comment …"></div>
							<input class="submit icon-confirm has-tooltip" type="submit" value="" title="" data-original-title="Post">
							<div class="submitLoading icon-loading-small hidden"></div>
						</form>
					</div>
					<timeline-entry v-for="entry in timeline" :key="entry.id" :item="entry"></timeline-entry>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
	.social__welcome {
		padding: 15px;
		background-color: var(--color-background-dark);
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.social__welcome .icon-close {
		float:right;
	}

	.social-id {
		font-weight: bold;
	}
	.new-post {
		display: flex;
		padding: 10px;
		border-bottom: 1px solid var(--color-background-dark);

	}
	.new-post-author {
		padding: 5px;
	}
	.new-post-form {
		flex-grow: 1;
		position: relative;
	}
	.message {
		width: 100%;
	}
	input[type=submit] {
		width: 44px;
		height: 44px;
		margin: 0;
		padding: 13px;
		background-color: transparent;
		border: none;
		opacity: 0.3;
		position: absolute;
		bottom: 0;
		right: 0;
	}
</style>


<script>
	import {
		PopoverMenu,
		AppNavigation
	} from 'nextcloud-vue';
	import TimelineEntry from './components/TimelineEntry';

	export default {
		name: 'App',
		components: {
			PopoverMenu, AppNavigation, TimelineEntry
		},
		data: function () {
			return {

			};
		},
		beforeMount: function() {
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
			for (let i=0; i<20; i++) {
				example.id = Math.floor((Math.random() * 100));
				data.push(example);
			}
			this.$store.commit('addToTimeline', data);
		},
		computed: {
			socialId: function() {
				return '@' + OC.getCurrentUser().uid + '@' + OC.getHost();
			},
			timeline: function() {
				return this.$store.getters.getTimeline;
			},
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
				];
				return {
					items: defaultCategories,
					loading: false
				}
			}
		}
	}
</script>
