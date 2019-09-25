<template>
	<div class="social__wrapper">
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
import Logger from '../logger'
import TimelineEntry from './../components/TimelineEntry.vue'
import TimelineList from './../components/TimelineList.vue'

export default {
	name: 'TimelineSinglePost',
	components: {
		TimelineEntry,
		TimelineList
	},
	mixins: [
	],
	data() {
		return {
			mainPost: {}
		}
	},
	computed: {
	},
	beforeMount: function() {

		// Get data of post clicked on
		if (typeof this.$route.params.id === 'undefined') {
			Logger.debug('displaying the single post timeline for a non logged-in user')
			this.mainPost = JSON.parse(document.getElementById('postData').dataset.server)
		} else {
			this.mainPost = this.$store.getters.getPostFromTimeline(this.$route.params.id)
		}

		// Set params for the TimelineList component
		let params = {
			account: window.location.href.split('/')[window.location.href.split('/').length - 2].substr(1),
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
