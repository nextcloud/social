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
				<h2>{{ t('social', 'Nextcloud becomes part of the federated social networks!') }}</h2>
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

		<div class="timeline-heading-row">
			<!-- the page had no heading at all outside tags and notifications, so
			     there was nothing to land on and nothing to say where you were -->
			<h1 class="timeline-heading" :class="{ 'hidden-visually': !headingIsVisible }">
				{{ heading }}
			</h1>
			<HashtagFollowButton v-if="type === 'tags'"
				:tag="$route.params.tag"
				@changed="onHashtagFollowChanged" />
		</div>

		<HashtagFollowedList v-if="type === 'tags'" ref="followedHashtags" />

		<TimelineList :type="type" />

		<!-- the first post somebody ever publishes here, marked once -->
		<FirstPostCelebration v-if="celebratingFirstPost" @done="endCelebration" />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import CurrentUserMixin from './../mixins/currentUserMixin.js'
import TimelineList from './../components/TimelineList.vue'
import FirstPostCelebration from './../components/FirstPostCelebration.vue'
import HashtagFollowButton from './../components/HashtagFollowButton.vue'
import HashtagFollowedList from './../components/HashtagFollowedList.vue'
import eventBus from './../services/eventBus.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Timeline',
	components: {
		Composer,
		FirstPostCelebration,
		HashtagFollowButton,
		HashtagFollowedList,
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
		...mapStores(useAccountStore, useSettingsStore, useTimelineStore),
		/** What this timeline is, in the words the sidebar uses for it. */
		heading() {
			switch (this.type) {
			case 'tags':
				return '#' + this.$route.params.tag
			case 'photos':
				return t('social', 'Photos')
			case 'notifications':
				return t('social', 'Notifications')
			case 'direct':
				return t('social', 'Direct messages')
			case 'timeline':
				// what the sidebar calls Local: the store asks for `local: true`
				return t('social', 'Local timeline')
			case 'federated':
				return t('social', 'Global timeline')
			case 'favourites':
				return t('social', 'Liked posts')
			case 'bookmarks':
				return t('social', 'Bookmarks')
			case 'single-post':
				return t('social', 'Post')
			default:
				return t('social', 'Home timeline')
			}
		},
		/**
		 * The two that were on the page before stay on the page; the rest name
		 * the view for a screen reader without changing what anyone sees.
		 */
		headingIsVisible() {
			// Photos is a view of its own rather than a filter of a list you
			// were already on, so it says which one you are looking at
			return this.type === 'tags' || this.type === 'notifications' || this.type === 'photos'
		},
		/** @return {string} what identifies this timeline, params included */
		timelineKey() {
			return this.type + '|' + JSON.stringify(this.params)
		},
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
			return this.settingsStore.getServerData.firstrun && !this.infoHidden
		},
		/** @return {boolean} whether the first-post celebration is on screen */
		celebratingFirstPost() {
			return this.timelineStore.isCelebratingFirstPost
		},
		isFollowingNextcloudAccount() {
			// not loaded yet: assume it is followed rather than offer a button
			// that would ask about an account nothing is known about
			if (this.accountStore.getAccount(this.nextcloudAccount) === undefined) {
				return true
			}
			return this.accountStore.isFollowingUser(this.nextcloudAccount)
		},
	},
	watch: {
		// the router-view is no longer keyed on the full path, so switching
		// from Home to Global reuses this view: without this the store would
		// keep serving the previous timeline
		timelineKey() {
			this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
		},
	},
	beforeMount() {
		this.timelineStore.changeTimelineType({ type: this.type, params: this.params })
		if (this.showInfo) {
			this.accountStore.fetchAccountInfo(this.nextcloudAccount)
		}
	},
	mounted() {
		eventBus.on('post-published', this.onPostPublished)
	},
	beforeUnmount() {
		eventBus.off('post-published', this.onPostPublished)
		// navigating away mid-celebration must not leave the flag standing for
		// whatever timeline mounts next
		if (this.celebratingFirstPost) {
			this.timelineStore.endFirstPostCelebration()
		}
	},
	methods: {
		hideInfo() {
			this.infoHidden = true
		},
		/**
		 * A post went out. Whether that is the reader's first is the store's
		 * decision; asking costs nothing and nothing here waits on the answer,
		 * so the post itself appears exactly as it did before.
		 */
		onPostPublished() {
			this.timelineStore.celebrateFirstPost()
		},
		/** The list of followed hashtags is stale the moment one is followed. */
		onHashtagFollowChanged() {
			this.$refs.followedHashtags?.refresh()
		},
		endCelebration() {
			this.timelineStore.endFirstPostCelebration()
		},
		followNextcloud() {
			this.accountStore.followAccount({ accountToFollow: this.nextcloudAccount })
		},
	},
}
</script>

<style scoped lang="scss">
/*
 * The column, and nothing about what is in it. `.social__timeline` is another
 * component's root element, and a scoped style still reaches a child's root —
 * so a rule here lands on it with the same specificity as the list's own and
 * wins or loses on bundle order. This view used to set `margin: 0` on it, which
 * beat the list's own `margin: 0 auto` and left the timeline flush to one side
 * while the composer beside it stayed centred.
 */
.social__wrapper {
	max-width: var(--social-column);
	margin: 0 auto;
	padding: 0;
}

.timeline-heading-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

.timeline-heading {
	font-size: 20px;
	font-weight: 700;
	margin: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-lighter);
	letter-spacing: -.01em;
}

.social__welcome {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
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
		inset-inline-end: 12px;
		padding: 12px;
		border-radius: 8px;
		color: var(--color-text-lighter);

		&:hover,
		&:focus {
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
			float: inline-end;
		}
	}
}

#app-content {
	position: relative;
}

.slide-fade-enter-active,
.slide-fade-leave-active {
	position: relative;
	overflow: hidden;
	transition: max-height .3s ease-out, opacity .3s ease-out;
	max-height: 200px;
}

.slide-fade-enter-from,
.slide-fade-leave-to {
	max-height: 0;
	opacity: 0;
	padding-top: 0;
	padding-bottom: 0;
}

@media (prefers-reduced-motion: reduce) {
	.slide-fade-enter-active,
	.slide-fade-leave-active {
		transition: none;
	}
}

</style>
