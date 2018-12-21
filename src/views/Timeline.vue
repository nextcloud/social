<template>
	<div class="social__wrapper">
		<transition name="slide-fade">
			<div v-if="showInfo" class="social__welcome">
				<a class="close icon-close" href="#" @click="hideInfo()"><span class="hidden-visually">Close</span></a>
				<h2>🎉 {{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
				<p>
					{{ t('social', 'We automatically created a Social account for you. Your Social ID is the same as your federated cloud ID:') }}
					<span class="social-id">{{ socialId }}</span>
				</p>
				<div v-show="!isFollowingNextcloudAccount" class="follow-nextcloud">
					<p>{{ t('social', 'Since you are new to Social, start by following the official Nextcloud account so you don\'t miss any news') }}</p>
					<input :value="t('social', 'Follow Nextcloud on mastodon.xyz')" type="button" class="primary"
						@click="followNextcloud">
				</div>
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

	.social__welcome .follow-nextcloud {
		overflow: hidden;
		margin-top: 20px;
	}

	.social__welcome .follow-nextcloud input[type=button] {
		float: right;
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
import follow from './../mixins/follow'
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
	mixins: [
		CurrentUserMixin,
		follow
	],
	data: function() {
		return {
			infoHidden: false,
			nextcloudAccount: 'nextcloud@mastodon.xyz'
		}
	},
	computed: {
		type() {
			if (this.$route.params.type) {
				return this.$route.params.type
			}
			return 'home'
		},
		showInfo() {
			return this.$store.getters.getServerData.firstrun && !this.infoHidden
		},
		isFollowingNextcloudAccount() {
			return this.$store.getters.isFollowingUser(this.nextcloudAccount)
		}
	},
	beforeMount: function() {
		this.$store.dispatch('changeTimelineType', this.type)
		if (this.showInfo) {
			this.$store.dispatch('fetchAccountInfo', this.nextcloudAccount)
		}
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		},
		followNextcloud() {
			this.$store.dispatch('followAccount', { accountToFollow: this.nextcloudAccount })
		}
	}
}
</script>
