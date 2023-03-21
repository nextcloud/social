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
			<TimelineEntry v-for="entry in timeline"
				:key="entry.id"
				:item="entry"
				:type="type" />
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

import { showError } from '@nextcloud/dialogs'

import TimelineEntry from './TimelineEntry.vue'
import CurrentUserMixin from './../mixins/currentUserMixin.js'
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'

export default {
	name: 'TimelineList',
	components: {
		TimelineEntry,
		InfiniteLoading,
		EmptyContent,
	},
	mixins: [CurrentUserMixin],
	props: {
		type: {
			type: String,
			default: () => 'home',
		},
		showParents: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			infoHidden: false,
			state: [],
			intervalId: -1,
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
				favourites: {
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
			logger.log('Did not find any empty content for this route', { routeType: this.$route.params.type, routeName: this.$route.name })
			return this.emptyContent.default
		},

		/**
		 * @return {import('../store/timeline.js').APObject[]}
		 */
		timeline() {
			if (this.showParents) {
				return this.$store.getters.getParentsTimeline
			} else {
				return this.$store.getters.getTimeline
			}
		},
	},
	mounted() {
		this.intervalId = setInterval(() => this.fetchNewStatuses(), 30 * 1000)
	},
	destroyed() {
		clearInterval(this.intervalId)
	},
	methods: {
		async infiniteHandler($state) {
			try {
				const response = await this.$store.dispatch('fetchTimeline', {
					account: this.currentUser.uid,
					max_id: this.timeline.length > 0 ? Number.parseInt(this.timeline[this.timeline.length - 1].id) : undefined,
				})

				response.length > 0 ? $state.loaded() : $state.complete()
			} catch (error) {
				showError('Failed to load more timeline entries')
				logger.error('Failed to load more timeline entries', { error })
				$state.complete()
			}
		},
		async fetchNewStatuses() {
			try {
				const response = await this.$store.dispatch('fetchTimeline', {
					account: this.currentUser.uid,
					min_id: this.timeline[0]?.id ?? undefined,
				})

				if (response.length > 0) {
					this.fetchNewStatuses()
				}
			} catch (error) {
				showError('Failed to load newer timeline entries')
				logger.error('Failed to load newer timeline entries', { error })
			}
		},
	},
}
</script>

<style scoped>
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
