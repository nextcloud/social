<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation>
		<template #search>
			<NcAppNavigationSearch
				v-model="localSearch"
				:label="t('social', 'Search …')"
				@update:modelValue="onSearchInput" />
		</template>
		<template #list>
			<!-- The one thing in the sidebar that is not a place to go: it is
			     what the app is for, so it is a call to action rather than a
			     row among rows. It was an NcAppNavigationItem with no `to`,
			     which renders href="#" and needed a .prevent to stop the bare
			     fragment becoming a history entry; a button has nowhere to go
			     by construction. NcButton rather than a bare <button> because
			     the server's own button rules all exclude `.button-vue` — a
			     hand-rolled one has to win an argument with them in every
			     state, and loses the pressed one. -->
			<NcButton
				class="navigation__compose"
				variant="primary"
				wide
				alignment="start"
				@click="showComposer = true">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('social', 'New post') }}
			</NcButton>

			<NcAppNavigationItem
				v-if="hasErrors"
				:name="t('social', 'Errors')"
				@click.prevent="showErrors = true">
				<template #icon>
					<IconAlertCircle class="error-icon" :size="20" />
				</template>
				<!-- `counter` is a slot in @nextcloud/vue 9, not a prop: passing
				     the number as `:counter` rendered nothing at all -->
				<template #counter>
					<NcCounterBubble :count="errorCount" type="highlighted" />
				</template>
			</NcAppNavigationItem>

			<!-- `href` rather than `to`: with `to`, the component ORs its own
			     router-derived state into `active`, and vue-router counts
			     /timeline as active while /timeline/direct is open — so Home
			     stayed lit next to whichever timeline was actually chosen.
			     navigate() keeps the click in the SPA while leaving a modified
			     click (new tab, new window) to the browser. -->
			<NcAppNavigationItem
				v-for="item in menu.timelines"
				:key="item.key"
				:name="item.title"
				:href="hrefFor(item.to)"
				:active="isActive(item)"
				@click="navigate(item.to, $event)">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
				<template v-if="item.counter > 0" #counter>
					<NcCounterBubble :count="item.counter" type="highlighted" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer />

			<NcAppNavigationCaption v-if="trending.length > 0" :name="t('social', 'Trending')" />
			<NcAppNavigationItem
				v-for="tag in trending"
				:key="`trend-${tag.name}`"
				class="navigation__trend"
				:name="`#${tag.name}`"
				:href="hrefFor({ name: 'tags', params: { tag: tag.name } })"
				:active="isTagActive(tag)"
				@click="navigate({ name: 'tags', params: { tag: tag.name } }, $event)">
				<template #icon>
					<IconPound :size="20" />
				</template>
				<template #extra>
					<span class="navigation__subname">
						{{ n('social', '%n post', '%n posts', usesOf(tag)) }}
					</span>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer v-if="trending.length > 0" />

			<!-- the account, as an account: the name people know the reader by,
			     next to a portrait large enough to recognise. What stood here was
			     the Nextcloud login name, pushed to the far edge of the row by the
			     slot it was in — neither the name they publish under nor their
			     handle, and aligned with nothing. -->
			<NcAppNavigationItem
				class="navigation__profile"
				:name="profileName"
				:href="hrefFor(menu.profile.to)"
				:active="isActive(menu.profile)"
				@click="navigate(menu.profile.to, $event)">
				<template #icon>
					<NcAvatar
						:user="currentUser?.uid"
						:displayName="currentUser?.displayName"
						:size="36"
						:disableTooltip="true"
						:disableMenu="true" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<div class="navigation__footer">
				<NcAppNavigationSettings :name="t('social', 'More')">
					<NcAppNavigationItem
						v-for="item in menu.more"
						:key="item.key"
						:name="item.title"
						:href="hrefFor(item.to)"
						:active="isActive(item)"
						@click="navigate(item.to, $event)">
						<template #icon>
							<component :is="item.icon" :size="20" />
						</template>
						<template v-if="item.counter > 0" #counter>
							<NcCounterBubble :count="item.counter" type="highlighted" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="t('social', 'Blocked and muted accounts')"
						:href="hrefFor({ name: 'blocked-accounts' })"
						:active="isActive({ to: { name: 'blocked-accounts' } })"
						@click="navigate({ name: 'blocked-accounts' }, $event)">
						<template #icon>
							<IconCancel :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationSettings>
			</div>
		</template>
	</NcAppNavigation>

	<NcModal
		v-if="showComposer"
		:name="t('social', 'New post')"
		@close="showComposer = false">
		<div class="modal-composer">
			<!-- the box emptied and the modal stayed open, which reads as if
			     nothing had been sent -->
			<Composer startExpanded @posted="showComposer = false" />
		</div>
	</NcModal>

	<NcModal
		v-if="showErrors"
		:name="t('social', 'Errors')"
		@close="showErrors = false">
		<div class="modal-errors">
			<div v-for="error in appErrors" :key="error.id" class="modal-errors__item">
				<div class="modal-errors__title">
					{{ error.title }}
				</div>
				<div class="modal-errors__message">
					{{ error.message }}
				</div>
				<NcButton variant="tertiary" @click="dismissError(error.id)">
					{{ t('social', 'Dismiss') }}
				</NcButton>
			</div>
			<NcButton v-if="appErrors.length > 1" variant="tertiary" @click="clearAllErrors">
				{{ t('social', 'Dismiss all') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationSpacer from '@nextcloud/vue/components/NcAppNavigationSpacer'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'

import { defineAsyncComponent } from 'vue'

import IconHome from 'vue-material-design-icons/Home.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import IconPlayBoxMultiple from 'vue-material-design-icons/PlayBoxMultiple.vue'
import IconBell from 'vue-material-design-icons/Bell.vue'
import IconCommentAccount from 'vue-material-design-icons/CommentAccount.vue'
import IconAccountClock from 'vue-material-design-icons/AccountClock.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconBookmark from 'vue-material-design-icons/Bookmark.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { listen } from '@nextcloud/notify_push'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useErrorsStore } from '../store/errors.js'
import { useNotificationsStore } from '../store/notifications.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'

// the composer pulls the emoji picker and the attachment stack with it:
// its own chunk keeps all of that out of the entry bundle
const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'./Composer/Composer.vue'))

/** how often to re-read the badge when the server cannot push */
const UNREAD_POLL_MS = 60 * 1000

/** how long to let the typing settle before searching */
const SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'Navigation',
	components: {
		NcAppNavigation,
		NcAppNavigationSearch,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcAppNavigationSpacer,
		NcAppNavigationSettings,
		NcAvatar,
		NcModal,
		NcButton,
		NcCounterBubble,
		Composer,
		IconHome,
		IconBell,
		IconCommentAccount,
		IconHeart,
		IconPlus,
		IconBookmark,
		IconPound,
		IconCancel,
		IconAlertCircle,
	},

	emits: ['search'],
	setup() {
		const { currentUser } = useCurrentUser()

		return { currentUser }
	},

	data() {
		return {
			/** the hashtags the instance is using most, newest counts first */
			trending: [],
			localSearch: '',
			showComposer: false,
			showErrors: false,
			stopListening: null,
			pollTimer: null,
			searchTimer: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useErrorsStore, useNotificationsStore, useTimelineStore),
		hasErrors() {
			return this.errorsStore.hasErrors
		},

		errorCount() {
			return this.errorsStore.appErrors.length
		},

		unreadNotifications() {
			return this.notificationsStore.unreadNotifications
		},

		appErrors() {
			return this.errorsStore.appErrors
		},

		/**
		 * @return {string} the name the reader publishes under, falling back to
		 *                  the login name only while the account is still loading
		 */
		profileName() {
			return this.currentAccount?.display_name
				|| this.currentUser?.displayName
				|| this.currentUser?.uid
				|| ''
		},

		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** what is being searched for, as the URL says it */
		searchQuery() {
			return this.timelineStore.getSearchQuery ?? ''
		},

		menu() {
			return {
				timelines: [
					{
						key: 'social-home',
						icon: IconHome,
						title: t('social', 'Home'),
						to: { name: 'timeline' },
						// Local and Global are scopes of this page rather than
						// pages of their own now that the switcher sets them,
						// so the entry stays lit while the reader is on one:
						// otherwise the sidebar shows nothing chosen at all
						covers: ['', 'timeline', 'federated'],
					},
					{
						key: 'social-photos',
						icon: IconImageMultiple,
						title: t('social', 'Photos'),
						to: { name: 'timeline', params: { type: 'photos' } },
					},
					// its own entry rather than a filter inside Photos: a video
					// is watched rather than glanced at, and the two are mixed
					// together nowhere else on the fediverse either — PeerTube
					// publishes nothing but videos and Pixelfed nothing but
					// pictures
					{
						key: 'social-videos',
						icon: IconPlayBoxMultiple,
						title: t('social', 'Videos'),
						to: { name: 'timeline', params: { type: 'videos' } },
					},
					{
						key: 'social-notifications',
						icon: IconBell,
						title: t('social', 'Notifications'),
						to: { name: 'timeline', params: { type: 'notifications' } },
						counter: this.unreadNotifications,
					},
					{
						key: 'social-direct',
						icon: IconCommentAccount,
						title: t('social', 'Direct messages'),
						to: { name: 'timeline', params: { type: 'direct' } },
					},
					{
						key: 'social-discover',
						icon: IconCompass,
						title: t('social', 'Discover'),
						to: { name: 'discover' },
					},
				],

				// The sidebar's top level is for the timelines a reader moves
				// between all day; these three are things they go looking for,
				// and eight equal-weight entries made the first five harder to
				// pick out. They keep their routes, their icons and their
				// active state — only where they are drawn changes.
				more: [
					{
						key: 'social-follow-requests',
						icon: IconAccountClock,
						title: t('social', 'Follow requests'),
						to: { name: 'follow-requests' },
					},
					{
						key: 'social-liked',
						icon: IconHeart,
						title: t('social', 'Liked posts'),
						to: { name: 'timeline', params: { type: 'favourites' } },
					},
					{
						key: 'social-bookmarks',
						icon: IconBookmark,
						title: t('social', 'Bookmarks'),
						to: { name: 'timeline', params: { type: 'bookmarks' } },
					},
				],

				profile: {
					key: 'social-profile',
					icon: 'user',
					title: t('social', 'Profile'),
					to: { name: 'profile', params: { account: this.currentUser?.uid } },
				},
			}
		},
	},

	watch: {
		// the box has to follow the store, not just read it once: synced only
		// in mounted() it kept showing a term the reader had already navigated
		// away from
		searchQuery: {
			immediate: true,
			handler(query) {
				this.localSearch = query
			},
		},
	},

	mounted() {
		this.fetchTrending()
		this.notificationsStore.fetchUnreadNotifications()

		// the badge is only honest if it keeps up: with notify_push the server
		// says when something arrived, and without it a slow poll is enough
		this.stopListening = listen('social_timeline', () => {
			this.notificationsStore.fetchUnreadNotifications()
		})
		if (!this.stopListening) {
			this.pollTimer = setInterval(
				() => this.notificationsStore.fetchUnreadNotifications(),
				UNREAD_POLL_MS,
			)
		}
	},

	beforeUnmount() {
		if (typeof this.stopListening === 'function') {
			this.stopListening()
		}
		if (this.pollTimer !== null) {
			clearInterval(this.pollTimer)
		}
		if (this.searchTimer !== null) {
			window.clearTimeout(this.searchTimer)
		}
	},

	methods: {
		t: translate,
		n: translatePlural,
		/**
		 * What the instance is talking about. The counts are kept by cron, so
		 * this is one cheap read; a failure leaves the section out rather than
		 * bothering anyone about it.
		 */
		async fetchTrending() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/trends/tags'), {
					params: { limit: 5 },
				})
				this.trending = Array.isArray(data) ? data : []
			} catch {
				this.trending = []
			}
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {number} how often it was used in the window the server chose
		 */
		usesOf(tag) {
			return Number.parseInt(tag.history?.[0]?.uses ?? 0) || 0
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {boolean} whether its timeline is the one being shown
		 */
		/**
		 * @param {object} to a route location
		 * @return {string} where it points, so the entry is a real link that can
		 *                  be opened in a new tab or copied
		 */
		hrefFor(to) {
			return this.$router.resolve(to).href
		},

		/**
		 * Follows the entry inside the app, unless the reader asked the browser
		 * for something else — the modifier keys and the middle button belong to
		 * them, which is the rule router-link itself applies.
		 *
		 * @param {object} to a route location
		 * @param {MouseEvent} event the click
		 */
		navigate(to, event) {
			if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0)) {
				return
			}

			event?.preventDefault()
			this.$router.push(to)
		},

		/**
		 * @param {object} tag a Tag entity
		 * @return {boolean} whether its timeline is the one being shown
		 */
		isTagActive(tag) {
			return this.$route?.name === 'tags' && this.$route?.params?.tag === tag.name
		},

		dismissError(id) {
			this.errorsStore.dismissAppError(id)
		},

		clearAllErrors() {
			this.errorsStore.clearErrors()
		},

		/**
		 * Searching now costs a request, so it waits for the typing to stop.
		 * Un-debounced, every keystroke went straight through.
		 */
		onSearchInput() {
			if (this.searchTimer !== null) {
				window.clearTimeout(this.searchTimer)
			}
			this.searchTimer = window.setTimeout(() => {
				this.searchTimer = null
				this.$emit('search', this.localSearch)
			}, SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Whether an entry is the page on screen. An entry matches its own
		 * route and any dot-namespaced child of it, so Profile stays lit on
		 * `profile.followers`, while a sibling that differs only by a param
		 * does not match.
		 *
		 * @param {object} item one of the menu entries
		 * @return {boolean} whether it is the current page
		 */
		isActive(item) {
			const route = this.$route
			const to = item.to
			const name = String(route.name ?? '')
			if (name !== to.name && !name.startsWith(to.name + '.')) {
				return false
			}

			// an entry whose page is read at more than one scope owns all of
			// them: the type says which, and the switcher on the page says
			// which one of the entry's own scopes is being read
			if (item.covers !== undefined) {
				return item.covers.includes(String(route.params.type ?? ''))
			}

			// An optional param the URL leaves out still arrives as '', so the
			// two sides are compared value by value across both key sets:
			// counting keys makes /timeline, whose params are {type: ''}, look
			// different from the Home entry, which carries no params at all.
			const wanted = to.params ?? {}
			const actual = route.params ?? {}
			for (const key of new Set([...Object.keys(wanted), ...Object.keys(actual)])) {
				if (String(wanted[key] ?? '') !== String(actual[key] ?? '')) {
					return false
				}
			}

			return true
		},
	},
}
</script>

<style scoped lang="scss">
.navigation__profile :deep(.app-navigation-entry-link),
.navigation__profile :deep(.app-navigation-entry-button) {
	// the icon box is one clickable area wide and the portrait is larger than
	// the icon it replaces, so the row grows with it instead of clipping it
	height: auto;
	min-height: 48px;
	align-items: center;
}

.navigation__profile :deep(.app-navigation-entry-icon) {
	width: 44px;
	min-width: 44px;
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.modal-composer {
	padding: calc(var(--default-grid-baseline) * 4);
}

.modal-errors {
	padding: calc(var(--default-grid-baseline) * 4);

	&__item {
		padding: calc(var(--default-grid-baseline) * 2) 0;
		border-bottom: 1px solid var(--color-border);
	}

	&__title {
		font-weight: 700;
		margin-bottom: 4px;
	}

	&__message {
		color: var(--color-text-lighter);
		margin-bottom: 8px;
		overflow-wrap: break-word;
	}
}

.error-icon {
	color: var(--color-error);
}

:deep(.app-navigation-entry) {
	border-radius: 8px;
	margin: 2px 0;

	&:hover {
		background: var(--color-background-hover);
	}

	&.active {
		background: var(--color-background-dark);
	}
}

/* The call to action: the app's one shadow under it, a lift when you reach
   for it, and a light that crosses it once on the way in. */
.navigation__compose {
	position: relative;
	overflow: hidden;
	margin: 2px 4px 8px;
	box-shadow: var(--social-elevation-resting);
	font-weight: 600;
	transition: transform .25s cubic-bezier(.22, 1.2, .48, 1), box-shadow .25s ease;

	&:hover {
		transform: translateY(-2px);
		box-shadow: var(--social-elevation-raised);
	}

	&:active {
		transform: translateY(0) scale(.98);
		box-shadow: var(--social-elevation-resting);
	}
}

/* the plus sits in a disc of its own, which kicks when you reach for it — the
   same kick the timeline switcher's icons give. It grows rather than turns: a
   plus is the same plus at every right angle, so a rotation of one is movement
   nobody can see. */
/* the glyph rather than the icon box it sits in, which is nearly as tall as
   the button and would make a disc the size of the whole end of it */
.navigation__compose :deep(.plus-icon) {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	// a wash of the label's own colour, so the disc belongs to the button
	// whatever the instance's primary colour is
	background: color-mix(in srgb, var(--color-primary-element-text) 22%, transparent);
	transition: background-color .2s ease;
}

/* the wrapper has no gap of its own, and the label sits against the disc */
.navigation__compose :deep(.button-vue__text) {
	margin-inline-start: 4px;
}

.navigation__compose:hover :deep(.plus-icon) {
	background: color-mix(in srgb, var(--color-primary-element-text) 32%, transparent);
	animation: compose-pop .45s cubic-bezier(.34, 1.56, .64, 1);
}

/* the light, as a layer of the button rather than an element in it */
.navigation__compose::after {
	content: '';
	position: absolute;
	z-index: 0;
	inset-block: 0;
	inset-inline-start: -60%;
	width: 50%;
	background: linear-gradient(
		100deg,
		transparent,
		color-mix(in srgb, var(--color-primary-element-text) 26%, transparent),
		transparent
	);
	transform: skewX(-18deg);
	pointer-events: none;
}

.navigation__compose:hover::after {
	animation: compose-sheen .7s ease-out;
}

@keyframes compose-pop {
	0% { transform: scale(1); }
	55% { transform: scale(1.25); }
	100% { transform: scale(1); }
}

@keyframes compose-sheen {
	0% { inset-inline-start: -60%; }
	100% { inset-inline-start: 130%; }
}

@media (prefers-reduced-motion: reduce) {
	.navigation__compose {
		transition: none;
	}

	.navigation__compose:hover,
	.navigation__compose:active {
		transform: none;
	}

	.navigation__compose:hover :deep(.plus-icon),
	.navigation__compose:hover::after {
		animation: none;
	}
}
</style>
