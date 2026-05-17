<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__wrapper">
		<transition name="slide-fade">
			<div v-if="showInfo" class="social__welcome">
				<a class="close icon-close" href="#" @click="hideInfo()">
					<span class="hidden-visually">
						{{ t('social', 'Close') }}
					</span>
				</a>
				<h2>🎉 {{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
				<p>{{ t('social', 'This application is currently in beta stage.') }}</p>
				<br>
				<p>
					{{ t('social', 'We automatically created a Social account for you. Your Social ID is the same as your Federated Cloud ID:') }}
					<span class="social-id">
						{{ socialId }}
					</span>
				</p>
				<div v-show="!isFollowingNextcloudAccount" class="follow-nextcloud">
					<p>{{ t('social', 'Since you are new to Social, start by following the official Nextcloud account so you don\'t miss any news') }}</p>
					<input :value="t('social', 'Follow Nextcloud on mastodon.xyz')"
						type="button"
						class="primary"
						@click="followNextcloud">
				</div>
			</div>
		</transition>

		<Composer v-if="type !== 'notifications' && type !== 'single-post'" :default-visibility="type === 'direct' ? 'direct' : undefined" />

		<h2 v-if="type === 'tags'">
			#{{ $route.params.tag }}
		</h2>

		<h2 v-if="type === 'notifications'">
			{{ t('social', 'Notifications') }}
		</h2>

		<TimelineList :type="type" />
	</div>
</template>

<script>
import Composer from './../components/Composer/Composer.vue'
import CurrentUserMixin from './../mixins/currentUserMixin.js'
import TimelineList from './../components/TimelineList.vue'

export default {
	name: 'Timeline',
	components: {
		Composer,
		TimelineList,
	},
	mixins: [
		CurrentUserMixin,
	],
	data() {
		return {
			infoHidden: false,
			nextcloudAccount: 'nextcloud@mastodon.xyz',
		}
	},
	computed: {
		params() {
			if (this.$route.name === 'tags') {
				return { tag: this.$route.params.tag }
			} else if (this.$route.name === 'single-post') {
				return this.$route.params
			}
			return {}
		},
		type() {
			if (this.$route.name === 'tags') {
				return 'tags'
			}
			if (this.$route.params.type) {
				return this.$route.params.type
			}
			return 'home'
		},
		showInfo() {
			return this.$store.getters.getServerData.firstrun && !this.infoHidden
		},
		isFollowingNextcloudAccount() {
			if (!this.$store.getters.accountLoaded(this.nextcloudAccount)) {
				return true
			}
			return this.$store.getters.isFollowingUser(this.nextcloudAccount)
		},
	},
	beforeMount() {
		this.$store.dispatch('changeTimelineType', { type: this.type, params: this.params })
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
		},
	},
}
</script>

<style scoped lang="scss">
.social__wrapper {
	max-width: 600px;
	margin: 0 auto;
	padding: 0;
}

.social__timeline {
	margin: 0;
}

h2 {
	font-size: 20px;
	font-weight: 700;
	margin: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-lighter);
	letter-spacing: -.01em;
}

.social__welcome {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 16px;
	margin: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 4);
	position: relative;

	h2 {
		font-size: 20px;
		font-weight: 700;
		margin: 0 0 12px 0;
	}

	h3 {
		margin-top: 0;
		font-size: 16px;
		font-weight: 600;
	}

	p {
		color: var(--color-text-lighter);
		line-height: 1.7;
		margin: 8px 0;
	}

	.icon-close {
		position: absolute;
		top: 12px;
		right: 12px;
		padding: 12px;
		opacity: .3;
		border-radius: 8px;
		transition: all .15s ease;

		&:hover,
		&:focus {
			opacity: 1;
			background: var(--color-background-hover);
		}
	}

	.social-id {
		font-weight: 700;
		color: var(--color-primary-element);
	}

	.follow-nextcloud {
		margin-top: 16px;
		padding-top: 16px;
		border-top: 1px solid var(--color-border);

		input[type=button] {
			float: right;
		}
	}
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
