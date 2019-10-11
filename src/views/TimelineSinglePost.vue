<template>
	<div class="social__wrapper">
		<profile-info v-if="accountLoaded && accountInfo" :uid="uid" />
		<timeline-entry :item="mainPost" />
		<timeline-list :type="$route.params.type" />
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
import ProfileInfo from '../components/ProfileInfo.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import accountMixins from '../mixins/accountMixins'
import serverData from '../mixins/serverData'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'TimelineSinglePost',
	components: {
		ProfileInfo,
		TimelineEntry,
		TimelineList
	},
	mixins: [
		accountMixins,
		serverData
	],
	data() {
		return {
			mainPost: {},
			uid: this.account
		}
	},
	computed: {
		// Extract the viewed account name from the URL
		account() {
			return window.location.href.split('/')[window.location.href.split('/').length - 2].substr(1)
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
