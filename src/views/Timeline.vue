<template>
	<div class="social__wrapper">
		<transition name="slide-fade">
			<div v-if="showInfo" class="social__welcome">
				<a class="close icon-close" href="#" @click="hideInfo()"><span class="hidden-visually">Close</span></a>
				<h2>🎉 {{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
				<p>
					{{ t('social', 'We automatically created a social account for you. Your social ID is the same as your federated cloud ID:') }}
					<span class="social-id">{{ socialId }}</span>
				</p>
			</div>
		</transition>
		<composer />
		<timeline-list />
	</div>
</template>

<style scoped>

	.social__welcome {
		max-width: 600px;
		margin: 15px auto;
		padding: 15px;
		border-bottom: 1px solid var(--color-border);
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.social__welcome .icon-close {
		float: right;
		padding: 22px;
		margin: -15px;
		opacity: .3;
	}

	.social__welcome .icon-close:hover,
	.social__welcome .icon-close:focus {
		opacity: 1;
	}

	.social__welcome .social-id {
		font-weight: bold;
	}

	.social__timeline {
		max-width: 600px;
		margin: 15px auto;
	}

	#app-content {
		position: relative;
	}

	.slide-fade-leave-active {
		position: relative;
		overflow: hidden;
		transition: all .5s ease-out;
		max-height: 200px;
	}
	.slide-fade-leave-to {
		max-height: 0;
		opacity: 0;
		padding-top: 0;
		padding-bottom: 0;
	}

</style>

<script>
import {
	PopoverMenu,
	AppNavigation,
	Multiselect
} from 'nextcloud-vue'
import InfiniteLoading from 'vue-infinite-loading'
import TimelineEntry from './../components/TimelineEntry'
import Composer from './../components/Composer'
import CurrentUserMixin from './../mixins/currentUserMixin'
import EmptyContent from './../components/EmptyContent'
import TimelineList from './../components/TimelineList'

export default {
	name: 'Timeline',
	components: {
		PopoverMenu,
		AppNavigation,
		TimelineEntry,
		Multiselect,
		Composer,
		InfiniteLoading,
		EmptyContent,
		TimelineList
	},
	mixins: [CurrentUserMixin],
	data: function() {
		return {
			infoHidden: false
		}
	},
	computed: {
		type: function() {
			if (this.$route.params.type) {
				return this.$route.params.type
			}
			return 'home'
		},
		showInfo() {
			return this.$store.getters.getServerData.firstrun && !this.infoHidden
		}
	},
	beforeMount: function() {
		this.$store.dispatch('changeTimelineType', this.type)
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		}
	}
}
</script>
