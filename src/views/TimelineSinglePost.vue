<template>
	<div class="social__wrapper">
		<ProfileInfo v-if="accountLoaded && accountInfo" :uid="uid" />
		<Composer v-show="composerDisplayStatus" />
		<TimelineList v-if="timeline"
			:show-parents="true"
			:type="$route.params.type"
			:reverse-order="true" />
		<TimelineEntry class="main-post" :item="mainPost" type="single-post" />
		<TimelineList v-if="timeline" class="descendants" :type="$route.params.type" />
	</div>
</template>

<script>
import Composer from '../components/Composer/Composer.vue'
import ProfileInfo from '../components/ProfileInfo.vue'
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
		ProfileInfo,
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
			mainPost: {},
			uid: this.$route.params.account,
		}
	},
	computed: {
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
		 * @return {object}
		 */
		timeline() {
			return this.$store.getters.getTimeline
		},
	},
	beforeMount() {
		// Get data of post clicked on
		this.mainPost = this.$store.getters.getPostFromTimeline(this.$route.params.id) || loadState('social', 'item')

		// Fetch information of the related account
		this.$store.dispatch(this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo', this.account).then((response) => {
			// We need to update this.uid because we may have asked info for an account whose domain part was a host-meta,
			// and the account returned by the backend always uses a non host-meta'ed domain for its ID
			this.uid = response.username
		})

		// Fetch single post timeline
		const params = {
			account: this.account,
			id: this.$route.params.id,
			type: 'single-post',
		}
		this.$store.dispatch('changeTimelineType', {
			type: 'single-post',
			params,
		})
	},
	methods: {
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
