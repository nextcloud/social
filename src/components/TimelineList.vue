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
		<timeline-entry v-for="entry in timeline" :key="entry.id" :item="entry" />
		<infinite-loading ref="infiniteLoading" @infinite="infiniteHandler">
			<div slot="spinner">
				<div class="icon-loading" />
			</div>
			<div slot="no-more">
				<div class="list-end" />
			</div>
			<div slot="no-results">
				<empty-content :item="emptyContentData" />
			</div>
		</infinite-loading>
	</div>
</template>

<script>
import InfiniteLoading from 'vue-infinite-loading'
import TimelineEntry from './../components/TimelineEntry'
import CurrentUserMixin from './../mixins/currentUserMixin'
import EmptyContent from './../components/EmptyContent'

export default {
	name: 'Timeline',
	components: {
		TimelineEntry,
		InfiniteLoading,
		EmptyContent
	},
	mixins: [CurrentUserMixin],
	data: function() {
		return {
			infoHidden: false,
			state: [],
			emptyContent: {
				default: {
					image: 'img/undraw/posts.svg',
					title: t('social', 'No posts found'),
					description: t('social', 'Posts from people you follow will show up here')
				},
				direct: {
					image: 'img/undraw/direct.svg',
					title: t('social', 'No direct messages found'),
					description: t('social', 'Posts directed to you will show up here')
				},
				timeline: {
					image: 'img/undraw/local.svg',
					title: t('social', 'No local posts found'),
					description: t('social', 'Posts from other people on this instance will show up here')
				},
				federated: {
					image: 'img/undraw/global.svg',
					title: t('social', 'No global posts found'),
					description: t('social', 'Posts from federated instances will show up here')
				},
				tags: {
					image: 'img/undraw/profile.svg',
					title: t('social', 'No posts found for this tag')
				}
			}
		}
	},
	computed: {
		emptyContentData() {
			if (typeof this.emptyContent[this.$route.name] !== 'undefined') {
				return this.emptyContent[this.$route.name]
			}
			if (typeof this.emptyContent[this.$route.params.type] !== 'undefined') {
				return this.emptyContent[this.$route.params.type]
			}
			return this.emptyContent.default
		},
		timeline: function() {
			return this.$store.getters.getTimeline
		}
	},
	beforeMount: function() {

	},
	methods: {
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
