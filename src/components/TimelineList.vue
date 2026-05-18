<template>
	<div class="social__timeline">
		<transition-group name="list" tag="ul">
			<TimelineEntry v-for="entry in timeline"
				:key="entry.id"
				:item="entry"
				:type="type" />
		</transition-group>
		<div ref="sentinel" class="list-sentinel">
			<div v-if="loading" class="icon-loading" />
			<div v-else-if="!allLoaded" class="list-end" />
			<EmptyContent v-if="allLoaded && timeline.length === 0 && emptyContentData.title !== ''" :item="emptyContentData" />
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'

import TimelineEntry from './TimelineEntry.vue'
import CurrentUserMixin from './../mixins/currentUserMixin.js'
import EmptyContent from './EmptyContent.vue'
import logger from '../services/logger.js'

export default {
	name: 'TimelineList',
	components: {
		TimelineEntry,
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
		reverseOrder: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			infoHidden: false,
			state: [],
			intervalId: -1,
			loading: false,
			allLoaded: false,
			observer: null,
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
					title: this.showParents ? '' : t('social', 'No replies found'),
				},
			},
		}
	},
	computed: {
		searchQuery() {
			return this.$store.getters.getSearchQuery
		},
		emptyContentData() {
			if (this.searchQuery && this.timeline.length === 0) {
				return {
					title: t('social', 'No posts match your search'),
					description: t('social', 'Try a different search term'),
				}
			}
			if (typeof this.emptyContent[this.$route.params.type] !== 'undefined') {
				return this.emptyContent[this.$route.params.type]
			}

			if (typeof this.emptyContent[this.$route.name] !== 'undefined') {
				const content = this.emptyContent[this.$route.name]
				if (this.$route.name === 'profile' && (this.serverData.public || this.$route.params.account !== this.currentUser.uid)) {
					content.title = this.$route.params.account + ' ' + t('social', 'hasn\'t tooted yet')
				}
				return this.$route.name === 'timeline' ? this.emptyContent.default : content
			}

			logger.log('Did not find any empty content for this route', { routeType: this.$route.params.type, routeName: this.$route.name })
			return this.emptyContent.default
		},

		timeline() {
			let timeline = []

			if (this.showParents) {
				timeline = this.$store.getters.getParentsTimeline
			} else {
				timeline = this.$store.getters.getTimeline
			}

			if (this.reverseOrder) {
				return timeline.reverse()
			} else {
				return timeline
			}
		},
	},
	mounted() {
		this.infiniteHandler()
		this.intervalId = setInterval(() => this.fetchNewStatuses(), 30 * 1000)
		this.setupIntersectionObserver()
	},
	unmounted() {
		clearInterval(this.intervalId)
		if (this.observer) {
			this.observer.disconnect()
		}
	},
	methods: {
		setupIntersectionObserver() {
			this.observer = new IntersectionObserver((entries) => {
				if (entries[0].isIntersecting && !this.loading && !this.allLoaded) {
					this.infiniteHandler()
				}
			}, { rootMargin: '200px' })
			this.$nextTick(() => {
				if (this.$refs.sentinel) {
					this.observer.observe(this.$refs.sentinel)
				}
			})
		},
		async infiniteHandler() {
			if (this.loading) return
			this.loading = true

			const params = {}

			if (this.timeline.length !== 0) {
				if (this.reverseOrder) {
					params.min_id = Number.parseInt(this.timeline[0].id)
				} else {
					params.max_id = Number.parseInt(this.timeline[this.timeline.length - 1].id)
				}
			}

			try {
				const response = await this.$store.dispatch('fetchTimeline', params)
				if (response.length > 0) {
					this.loading = false
				} else {
					this.allLoaded = true
					this.loading = false
				}
			} catch (error) {
				showError('Failed to load more timeline entries')
				logger.error('Failed to load more timeline entries', { error })
				this.allLoaded = true
				this.loading = false
			}
		},
		async fetchNewStatuses() {
			if (this.showParents) {
				return
			}

			try {
				const response = await this.$store.dispatch('fetchTimeline', {
					min_id: this.timeline[0]?.id,
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

<style scoped lang="scss">
.social__timeline {
	max-width: 600px;
	margin: 0 auto;
	padding: 0 calc(var(--default-grid-baseline) * 2);

	ul {
		margin: 0;
		padding: 0;
	}

	.list-enter-active,
	.list-leave-active {
		transition: opacity .15s ease;
	}

	.list-enter, .list-leave-to {
		opacity: 0;
	}

	.icon-loading {
		height: 44px;
		margin: 20px auto;
	}

	.list-end {
		height: 1px;
	}

	.list-sentinel {
		min-height: 1px;
	}
}
</style>
