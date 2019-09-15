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
		this.mainPost = this.$store.getters.getPostFromTimeline(this.$route.params.id)

		// Prepare to display main post's replies in TimelineList component
		this.$store.dispatch('changeTimelineType', {
			type: this.$route.params.type,
			params: this.$route.params
		})
	},
	methods: {
	}
}
</script>
