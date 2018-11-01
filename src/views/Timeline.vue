<template>
	<div class="social__wrapper">
		<div class="social__container">
			<div v-if="!infoHidden" class="social__welcome">
				<a class="close icon-close" href="#" @click="hideInfo()"><span class="hidden-visually">Close</span></a>
				<h2>🎉 {{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
				<p>
					{{ t('social', 'We automatically created a social account for you. Your social ID is the same as your federated cloud ID:') }}
					<span class="social-id">{{ socialId }}</span>
				</p>
			</div>
			<div class="social__timeline">
				<div class="new-post" data-id="">
					<div class="new-post-author">
						<avatar :user="currentUser.uid" :display-name="currentUser.displayName" :size="32" />
					</div>
					<form class="new-post-form">
						<div class="author currentUser">
							{{ currentUser.displayName }}
							<span class="social-id">{{ socialId }}</span>
						</div>
						<div contenteditable="true" class="message" placeholder="Share a thought…" />
						<input class="submit icon-confirm has-tooltip" type="submit" value=""
							title="" data-original-title="Post">
						<div class="submitLoading icon-loading-small hidden" />
					</form>
				</div>
				<!--<timeline-entry v-for="entry in timeline" :item="entry" :key="entry.id" /> //-->
				<div v-for="entry in timeline">
					{{entry.content}}
				<pre style="height: 200px; overflow:scroll;">{{entry}}</pre>
				</div>
				<infinite-loading @infinite="infiniteHandler" ref="infiniteLoading">
				<div slot="spinner"><div class="icon-loading"></div></div>
				<div slot="no-more"><div class="list-end"></div></div>
				<div slot="no-results">
					<div id="emptycontent">
						<div class="icon-social"></div>
						<h2>{{t('social', 'No posts found.')}}</h2>
					</div>
				</div>
				</infinite-loading>
			</div>
		</div>
	</div>
</template>

<style scoped>
	.social__wrapper {
		display: flex;
	}

	.social__container {
		flex-grow: 1;
	}
	.social__profile {
		max-width: 500px;
		flex-grow: 1;
		border-right: 1px solid var(--color-background-dark);
		text-align: center;
		padding-top: 20px;
	}
	.social__welcome {
		max-width: 700px;
		margin: 15px auto;
		padding: 15px;
		border-bottom: 1px solid var(--color-border);
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.social__welcome .icon-close {
		float: right;
		padding: 22px;
		margin: -15px;
		opacity: .3;
	}

	.social__welcome .icon-close:hover,
	.social__welcome .icon-close:focus {
		opacity: 1;
	}

	.social__welcome .social-id {
		font-weight: bold;
	}

	.social__timeline {
		max-width: 700px;
		margin: 15px auto;
	}

	.new-post {
		display: flex;
		padding: 10px;
		background-color: var(--color-main-background);
		position: sticky;
		top: 47px;
		z-index: 100;
		margin-bottom: 10px;
	}
	.new-post-author {
		padding: 5px;
	}
	.author .social-id {
		opacity: .5;
	}
	.new-post-form {
		flex-grow: 1;
		position: relative;
	}
	.message {
		width: 100%;
	}
	[contenteditable=true]:empty:before{
		content: attr(placeholder);
		display: block; /* For Firefox */
		opacity: .5;
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

	#app-content {
		position: relative;
	}

</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect,
	Avatar
} from 'nextcloud-vue'
import InfiniteLoading from 'vue-infinite-loading'
import TimelineEntry from './../components/TimelineEntry'

export default {
	name: 'Timeline',
	components: {
		PopoverMenu, AppNavigation, TimelineEntry, Multiselect, Avatar,
		InfiniteLoading
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
		menu: function() {
			let defaultCategories = [
				{
					id: 'social-timeline',
					classes: [],
					href: '#',
					icon: 'icon-category-monitoring',
					text: t('social', 'Timeline')
				},
				{
					id: 'social-account',
					classes: [],
					href: '#',
					icon: 'icon-category-user',
					text: t('social', 'Your account')
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

	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		},
		infiniteHandler($state) {
			this.$store.dispatch('fetchTimeline', {
				account: this.currentUser.uid
			}).then((response) => { response.length > 0 ? $state.loaded() : $state.complete() });
		},
	}
}
</script>
