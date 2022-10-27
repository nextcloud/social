<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<div class="social__timeline">
		<transition-group name="list" tag="div">
			<TimelineEntry v-for="entry in timeline" :key="entry.id" :item="entry" />
		</transition-group>
		<InfiniteLoading ref="infiniteLoading" @infinite="infiniteHandler">
			<div slot="spinner">
				<div class="icon-loading" />
			</div>
			<div slot="no-more">
				<div class="list-end" />
			</div>
			<div slot="no-results">
				<EmptyContent v-if="timeline.length === 0" :item="emptyContentData" />
			</div>
		</InfiniteLoading>
	</div>
</template>

<script>
import InfiniteLoading from 'vue-infinite-loading'
import TimelineEntry from './TimelineEntry.vue'
import CurrentUserMixin from './../mixins/currentUserMixin.js'
import EmptyContent from './EmptyContent.vue'
import Logger from '../logger.js'

export default {
	name: 'TimelineList',
	components: {
		TimelineEntry,
		InfiniteLoading,
		EmptyContent,
	},
	mixins: [CurrentUserMixin],
	props: {
		type: { type: String, default: () => 'home' },
	},
	data() {
		return {
			infoHidden: false,
			state: [],
			emptyContent: {
				default: {
					image: 'img/undraw/posts.svg',
					title: t('social', 'No posts found'),
					description: t('social', 'Posts from people you follow will show up here'),
				},
				direct: {
					image: 'img/undraw/direct.svg',
					title: t('social', 'No direct messages found'),
					description: t('social', 'Posts directed to you will show up here'),
				},
				timeline: {
					image: 'img/undraw/local.svg',
					title: t('social', 'No local posts found'),
					description: t('social', 'Posts from other people on this instance will show up here'),
				},
				notifications: {
					image: 'img/undraw/notifications.svg',
					title: t('social', 'No notifications found'),
					description: t('social', 'You have not received any notifications yet'),
				},
				federated: {
					image: 'img/undraw/global.svg',
					title: t('social', 'No global posts found'),
					description: t('social', 'Posts from federated instances will show up here'),
				},
				liked: {
					image: 'img/undraw/likes.svg',
					title: t('social', 'No liked posts found'),
				},
				profile: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'You have not tooted yet'),
				},
				tags: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'No posts found for this tag'),
				},
				'single-post': {
					title: t('social', 'No replies found'),
				},
			},
		}
	},
	computed: {
		emptyContentData() {
			if (typeof this.emptyContent[this.$route.params.type] !== 'undefined') {
				return this.emptyContent[this.$route.params.type]
			}

			if (typeof this.emptyContent[this.$route.name] !== 'undefined') {
				const content = this.emptyContent[this.$route.name]
				// Change text on profile page when accessed by another user or a public (non-authenticated) user
				if (this.$route.name === 'profile' && (this.serverData.public || this.$route.params.account !== this.currentUser.uid)) {
					content.title = this.$route.params.account + ' ' + t('social', 'hasn\'t tooted yet')
				}
				return this.$route.name === 'timeline' ? this.emptyContent.default : content
			}

			// Fallback
			Logger.log('Did not find any empty content for this route', { routeType: this.$route.params.type, routeName: this.$route.name })
			return this.emptyContent.default
		},
		timeline() {
			return this.$store.getters.getTimeline
		},
	},
	beforeMount() {

	},
	methods: {
		infiniteHandler($state) {
			this.$store.dispatch('fetchTimeline', {
				account: this.currentUser.uid,
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
		},
	},
}
</script>

<style scoped>
	.list-item {
	}
	.list-enter-active, .list-leave-active {
		transition: all .5s;
	}
	.list-enter {
		opacity: 0;
		transform: translateY(-30px);
	}
	.list-leave-to {
		opacity: 0;
		transform: translateX(-100px);
	}
</style>
