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
					<input
						:value="t('social', 'Follow Nextcloud on mastodon.xyz')"
						type="button"
						class="primary"
						@click="followNextcloud">
				</div>
			</div>
		</transition>

		<Composer v-if="type !== 'notifications' && type !== 'single-post'" :defaultVisibility="type === 'direct' ? 'direct' : undefined" />

		<!-- the three timelines that are the same place seen from three
		     distances: switching between them is something a reader does while
		     reading, not something they navigate to -->
		<TimelineSwitcher
			v-if="isFeed"
			:options="scopes"
			:value="scope"
			:label="t('social', 'Which posts to show')" />

		<div class="timeline-heading-row">
			<!-- the page had no heading at all outside tags and notifications, so
			     there was nothing to land on and nothing to say where you were -->
			<h1 class="timeline-heading" :class="{ 'hidden-visually': !headingIsVisible }">
				{{ heading }}
			</h1>
			<HashtagFollowButton
				v-if="type === 'tags'"
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
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHome from 'vue-material-design-icons/Home.vue'
import TimelineList from './../components/TimelineList.vue'
import TimelineSwitcher from './../components/TimelineSwitcher.vue'
import FirstPostCelebration from './../components/FirstPostCelebration.vue'
import HashtagFollowButton from './../components/HashtagFollowButton.vue'
import HashtagFollowedList from './../components/HashtagFollowedList.vue'
import eventBus from './../services/eventBus.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'Timeline',
	components: {
		Composer,
		FirstPostCelebration,
		HashtagFollowButton,
		HashtagFollowedList,
		TimelineList,
		TimelineSwitcher,
	},

	setup() {
		const { socialId } = useCurrentUser()

		return { socialId }
	},

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
				case 'videos':
					return t('social', 'Videos')
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
		 * @return {boolean} whether the three scopes are what this page shows
		 */
		isFeed() {
			return ['home', 'timeline', 'federated', 'photos', 'videos'].includes(this.type)
		},

		/**
		 * Whether this page is one page read at three scopes rather than three
		 * pages. Photos and Videos both are: the sidebar entry stays lit
		 * whichever of the three the reader chose, which it would not if each
		 * scope were a `type` of its own.
		 *
		 * @return {boolean}
		 */
		isScopedPage() {
			return this.type === 'photos' || this.type === 'videos'
		},

		/**
		 * The three distances the same posts can be read at, as the switcher
		 * above them offers them.
		 *
		 * On Photos and Videos they are the same three, so those stay one page
		 * with a scope on it: the scope rides in the query, which keeps the
		 * sidebar entry lit whichever is chosen and keeps each of them one
		 * timeline rather than three that look alike. On the feed itself each
		 * scope is its own route, and `home` is the route with no `type` at
		 * all — passing `type: 'home'` would ask for a timeline of that name,
		 * which nothing serves.
		 *
		 * @return {object[]} what to give the switcher
		 */
		scopes() {
			const routeFor = (scope) => {
				if (this.isScopedPage) {
					return {
						name: 'timeline',
						params: { type: this.type },
						query: scope === 'home' ? {} : { scope },
					}
				}

				return scope === 'home'
					? { name: 'timeline' }
					: { name: 'timeline', params: { type: scope } }
			}

			return [
				// "My Feed" rather than "Home": next to Local and Global, what
				// distinguishes it is whose posts it holds, not where it sits
				{ value: 'home', label: t('social', 'My Feed'), icon: IconHome, to: routeFor('home') },
				{ value: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple, to: routeFor('timeline') },
				{ value: 'federated', label: t('social', 'Global'), icon: IconEarth, to: routeFor('federated') },
			]
		},

		/**
		 * Which of the three scopes is being read.
		 *
		 * Photos and Videos are each one page with a scope on it rather than
		 * three pages, so on them the scope comes from the query; everywhere
		 * else the type *is* the scope. A query that says anything else is read
		 * as the default rather than trusted: it arrives from the address bar.
		 *
		 * @return {string} `home`, `timeline` or `federated`
		 */
		scope() {
			if (!this.isScopedPage) {
				return this.type
			}

			const scope = String(this.$route.query.scope ?? '')

			return ['timeline', 'federated'].includes(scope) ? scope : 'home'
		},

		/**
		 * The two that were on the page before stay on the page; the rest name
		 * the view for a screen reader without changing what anyone sees.
		 */
		headingIsVisible() {
			// Photos and Videos are views of their own rather than a filter of
			// a list you were already on, so they say which one you are
			// looking at
			return this.type === 'tags' || this.type === 'notifications' || this.isScopedPage
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
			} else if (this.isScopedPage) {
				// part of what identifies this timeline, so that changing the
				// scope refetches rather than leaving the previous photos up
				return { scope: this.scope }
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
