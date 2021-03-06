<template>
	<div class="social__wrapper">
		<profile-info v-if="accountLoaded && accountInfo" :uid="uid" />
		<composer v-show="composerDisplayStatus" />
		<timeline-entry class="main-post" :item="mainPost" />
		<timeline-list v-if="timeline" :type="$route.params.type" />
	</div>
</template>

<style scoped>

	.social__timeline {
		max-width: 600px;
		margin: 15px auto;
	}

	#app-content {
		position: relative;
	}

</style>

<script>
import Composer from '../components/Composer.vue'
import ProfileInfo from '../components/ProfileInfo.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import currentUserMixin from '../mixins/currentUserMixin'
import accountMixins from '../mixins/accountMixins'
import serverData from '../mixins/serverData'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'TimelineSinglePost',
	components: {
		Composer,
		ProfileInfo,
		TimelineEntry,
		TimelineList
	},
	mixins: [
		accountMixins,
		currentUserMixin,
		serverData
	],
	data() {
		return {
			mainPost: {},
			uid: this.$route.params.account
		}
	},
	computed: {
		/**
		 * @description Tells whether Composer shall be displayed or not
		 * @returns {boolean}
		 */
		composerDisplayStatus() {
			return this.$store.getters.getComposerDisplayStatus
		},
		/**
		 * @description Extracts the viewed account name from the URL
		 * @returns {String}
		 */
		account() {
			return window.location.href.split('/')[window.location.href.split('/').length - 2].substr(1)
		},
		/**
		 * @description Returns the timeline currently loaded in the store
		 * @returns {Object}
		 */
		timeline: function() {
			return this.$store.getters.getTimeline
		}
	},
	beforeMount: function() {

		// Get data of post clicked on
		if (typeof this.$route.params.id === 'undefined') {
			this.mainPost = loadState('social', 'item')
		} else {
			this.mainPost = this.$store.getters.getPostFromTimeline(this.$route.params.id)
		}

		// Fetch information of the related account
		this.$store.dispatch(this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo', this.account).then((response) => {
			// We need to update this.uid because we may have asked info for an account whose domain part was a host-meta,
			// and the account returned by the backend always uses a non host-meta'ed domain for its ID
			this.uid = response.account
		})

		// Fetch single post timeline
		let params = {
			account: this.account,
			id: window.location.href,
			localId: window.location.href.split('/')[window.location.href.split('/').length - 1],
			type: 'single-post'
		}
		this.$store.dispatch('changeTimelineType', {
			type: 'single-post',
			params: params
		})
	},
	methods: {
	}
}
</script>

<style>

</style>
