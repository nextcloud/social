<template>
	<div class="social__wrapper">
		<transition name="slide-fade">
			<div v-if="showInfo" class="social__welcome">
				<a class="close icon-close" href="#" @click="hideInfo()"><span class="hidden-visually">Close</span></a>
				<h2>🎉 {{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
				<p>
					{{ t('social', 'We automatically created a social account for you. Your social ID is the same as your federated cloud ID:') }}
					<span class="social-id">{{ socialId }}</span>
				</p>
			</div>
		</transition>
		<div class="social__timeline">
			<composer />
			<timeline-entry v-for="entry in timeline" :item="entry" :key="entry.id" />
			<infinite-loading ref="infiniteLoading" @infinite="infiniteHandler">
				<div slot="spinner"><div class="icon-loading" /></div>
				<div slot="no-more"><div class="list-end" /></div>
				<div slot="no-results">
					<div id="emptycontent">
						<img class="icon-illustration" src="../../img/undraw/posts.svg" alt="" />
						<h2>{{ t('social', 'No posts found') }}</h2>
						<p>{{ t('social', 'Posts from people you follow will show up here') }}</p>
					</div>
				</div>
			</infinite-loading>
		</div>
	</div>
</template>

<style scoped>

	.social__welcome {
		max-width: 600px;
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
		max-width: 600px;
		margin: 15px auto;
	}

	#app-content {
		position: relative;
	}

	.slide-fade-leave-active {
		position: relative;
		overflow: hidden;
		transition: all .5s ease-out;
		max-height: 200px;
	}
	.slide-fade-leave-to {
		max-height: 0;
		opacity: 0;
		padding-top: 0;
		padding-bottom: 0;
	}

</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect
} from 'nextcloud-vue'
import InfiniteLoading from 'vue-infinite-loading'
import TimelineEntry from './../components/TimelineEntry'
import Composer from './../components/Composer'
import CurrentUserMixin from './../mixins/currentUserMixin'

export default {
	name: 'Timeline',
	components: {
		PopoverMenu,
		AppNavigation,
		TimelineEntry,
		Multiselect,
		Composer,
		InfiniteLoading
	},
	mixins: [CurrentUserMixin],
	data: function() {
		return {
			infoHidden: false,
			state: []
		}
	},
	computed: {
		type: function() {
			if (this.$route.params.type) {
				return this.$route.params.type
			}
			return 'home'
		},
		url: function() {
			return OC.linkTo('social', 'img/nextcloud.png')
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		},
		showInfo() {
			return this.$store.getters.getServerData.firstrun && !this.infoHidden
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
					text: t('social', 'Profile')
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
		this.$store.dispatch('changeTimelineType', this.type)
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		},
		infiniteHandler($state) {
			this.$store.dispatch('fetchTimeline', {
				account: this.currentUser.uid
			}).then((response) => {
				if (response.status === -1) {
					OC.Notification.showTemporary('Failed to load more timeline entries')
					console.error('Failed to load more timeline entries', response)
					$state.complete()
					return
				}
				response.result.length > 0 ? $state.loaded() : $state.complete()
			}).catch((error) => {
				OC.Notification.showTemporary('Failed to load more timeline entries')
				console.error('Failed to load more timeline entries', error)
				$state.complete()
			})
		}
	}
}
</script>
