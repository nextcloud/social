<template>
	<div ref="socialWrapper" class="social__wrapper">
		<Composer v-show="composerDisplayStatus" />
		<TimelineList v-if="timeline"
			:show-parents="true"
			:type="$route.params.type"
			:reverse-order="true" />
		<TimelineEntry ref="mainPost"
			class="main-post"
			:item="singlePost"
			type="single-post"
			element="div" />
		<TimelineList v-if="timeline" class="descendants" :type="$route.params.type" />
	</div>
</template>

<script>
import Composer from '../components/Composer/Composer.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import currentUserMixin from '../mixins/currentUserMixin.js'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'TimelineSinglePost',
	components: {
		Composer,
		TimelineEntry,
		TimelineList,
	},
	mixins: [
		accountMixins,
		currentUserMixin,
		serverData,
	],
	data() {
		return {
			uid: this.$route.params.account,
		}
	},
	computed: {
		/** @return {Status?} */
		singlePost() {
			return this.$store.getters.getSinglePost
		},
		/**
		 * @description Tells whether Composer shall be displayed or not
		 * @return {boolean}
		 */
		composerDisplayStatus() {
			return this.$store.getters.getComposerDisplayStatus
		},
		/**
		 * @description Extracts the viewed account name from the URL
		 * @return {string}
		 */
		account() {
			return window.location.href.split('/')[window.location.href.split('/').length - 2].slice(1)
		},
		/**
		 * @description Returns the timeline currently loaded in the store
		 * @return {import('../types/Mastodon').Status}
		 */
		timeline() {
			return this.$store.getters.getTimeline
		},
		/**
		 * @description Returns the parents timeline currently loaded in the store
		 * @return {import('../types/Mastodon').Status}
		 */
		parentsTimeline() {
			return this.$store.getters.getParentsTimeline
		},
	},
	watch: {
		// Make sure to scroll mainPost into view on first load so it is not hidden after the parents.
		parentsTimeline(_, previousValue) {
			if (previousValue.length === 0 && this.$refs.socialWrapper.parentElement.scrollTop === 0) {
				this.$nextTick(() => this.$refs.mainPost.$el.scrollIntoView({ behavior: 'smooth', block: 'center' }))
			}
		},
	},
	async beforeMount() {
		const singlePost = this.$store.getters.getPostFromTimeline(this.$route.params.id) || loadState('social', 'item')

		// Fetch single post timeline
		this.$store.commit('addToStatuses', singlePost)
		this.$store.dispatch('changeTimelineType', {
			type: 'single-post',
			params: {
				account: this.account,
				id: this.$route.params.id,
				type: 'single-post',
				singlePost: this.$route.params.id || loadState('social', 'item').id,
			},
		})

		this.$root.$on('composer-reply', (item) => {
			this.$nextTick(() => {
				this.$refs.socialWrapper.querySelector(`[data-social-status="${item.id}"]`).scrollIntoView({ behavior: 'smooth', block: 'center' })
			})
		})

		// Fetch information of the related account
		const response = await this.$store.dispatch(this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo', this.account)
		// We need to update this.uid because we may have asked info for an account whose domain part was a host-meta,
		// and the account returned by the backend always uses a non host-meta'ed domain for its ID
		this.uid = response.username
	},
}
</script>

<style scoped>
.social__wrapper {
	padding-bottom: 25%;
}

.social__timeline {
	margin-left: 16px;
}

.main-post {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	box-sizing: content-box;
	margin: 16px 0;
}

#app-content {
	position: relative;
}
</style>
