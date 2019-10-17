<template>
	<div class="social__wrapper">
		<composer v-if="currentUser.uid!==''" />
		<timeline-entry :item="mainPost" />
		<!-- Do not show replies when composing a reply to a remote post -->
		<timeline-list v-if="$route.name==='single-post'" />
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
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import CurrentUserMixin from '../mixins/currentUserMixin'

export default {
	name: 'TimelineSinglePost',
	components: {
		Composer,
		TimelineEntry,
		TimelineList
	},
	mixins: [
		CurrentUserMixin
	],
	data() {
		return {
			mainPost: {}
		}
	},
	computed: {
	},
	mounted: function() {
		// Interacting with a post from a remote instance
		this.$nextTick(function() {
			if (this.$route.name === 'interact-remote') {
				// Automaticaly like, boost, or prepare reply
				switch (this.$route.query.type) {
				case ('boost'):
					setTimeout(this.$store.dispatch('postBoost', { post: this.mainPost }), 2000)
					break
				case ('like'):
					setTimeout(this.$store.dispatch('postLike', { post: this.mainPost }), 2000)
					break
				case ('reply'):
					setTimeout(this.$root.$emit('composer-reply', this.mainPost), 2000)
					break
				}
			}
		})
	},
	beforeMount: function() {
		// Get data of post clicked on
		if (typeof this.$route.params.id === 'undefined') {
			// Displaying the single post timeline for a non logged-in user
			// or in case of a redirection from a remote instance (eg: a reply to remote post)
			this.mainPost = JSON.parse(document.getElementById('postData').dataset.server)
			this.$store.dispatch('addToTimeline', {
				data: this.mainPost
			})
		} else {
			this.mainPost = this.$store.getters.getPostFromTimeline(this.$route.params.id)
		}

		// We don't show the TimelineList component when interacting with a remote post
		if (this.$route.name === 'interact-remote') {
			return
		}

		// Set params for the TimelineList component
		let params = {
			account: window.location.href.split('/')[window.location.href.split('/').length - 2].substr(1),
			id: window.location.href,
			localId: window.location.href.split('/')[window.location.href.split('/').length - 1],
			type: this.$route.name
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
