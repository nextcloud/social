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
			     /timeline as active while /timeline/direct is open — so My Feed
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
		</template>
		<template #footer>
			<div class="navigation__footer">
				<!-- The way out of every app in Nextcloud is the thing at the
				     bottom with your face on it, so that is what this is: the
				     name the reader publishes under, their portrait, and
				     everything they go looking for rather than read, behind it.
				     `NcAppNavigationSettings` draws a cog and offers no slot to
				     replace it, so the picture is set as that icon's background
				     and the cog itself is hidden. -->
				<NcAppNavigationSettings
					class="navigation__more"
					:style="{ '--social-face': `url(${avatarUrl})` }"
					:name="profileName">
					<!-- `--entry-index` is what staggers the opening: the rows
					     each wait a little longer than the one above. It is
					     passed from here because the DOM cannot be counted --
					     every entry sits in a wrapper of its own, so each one is
					     its parent's first child and `nth-child` would give them
					     all the same delay. -->
					<NcAppNavigationItem
						v-for="(item, index) in menu.more"
						:key="item.key"
						:style="{ '--entry-index': index }"
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
						:style="{ '--entry-index': menu.more.length }"
						:name="t('social', 'Blocked and muted accounts')"
						:href="hrefFor({ name: 'blocked-accounts' })"
						:active="isActive({ to: { name: 'blocked-accounts' } })"
						@click="navigate({ name: 'blocked-accounts' }, $event)">
						<template #icon>
							<IconCancel :size="20" />
						</template>
					</NcAppNavigationItem>
					<!-- last, and on its own: everything above this menu is a
					     place to read something, and this is the one place to
					     change something -->
					<NcAppNavigationItem
						:style="{ '--entry-index': menu.more.length + 1 }"
						:name="t('social', 'Settings')"
						:href="hrefFor({ name: 'settings' })"
						:active="isActive({ to: { name: 'settings' } })"
						@click="navigate({ name: 'settings' }, $event)">
						<template #icon>
							<IconCog :size="20" />
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
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconAccountClock from 'vue-material-design-icons/AccountClock.vue'
import IconHeart from 'vue-material-design-icons/Heart.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconBookmark from 'vue-material-design-icons/Bookmark.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconChartBox from 'vue-material-design-icons/ChartBox.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { listen } from '@nextcloud/notify_push'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
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
		IconAccountCircle,
		IconBell,
		IconCommentAccount,
		IconHeart,
		IconPlus,
		IconBookmark,
		IconPound,
		IconCancel,
		IconCog,
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

		/**
		 * The reader's own face, as a URL for the button at the bottom.
		 *
		 * The server's avatar endpoint rather than the account's `avatar`
		 * field: it answers for every account — a generated set of initials
		 * when nobody has uploaded a picture — so the button is never a blank
		 * circle, and it is the same picture the rest of Nextcloud shows.
		 *
		 * @return {string} where the picture is
		 */
		avatarUrl() {
			const uid = this.currentUser?.uid
			if (!uid) {
				return ''
			}

			return generateUrl('/avatar/{uid}/64', { uid })
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
						// the switcher over the timelines has called this one "My Feed"
						// since it was written: next to Local and Global what
						// distinguishes it is whose posts it holds, not where it sits.
						// The sidebar was the last place still calling it Home, for the
						// same destination.
						title: t('social', 'My Feed'),
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
						// "Activities" rather than "Notifications": what this page
						// holds is everything that happened -- a mention, a like, a
						// boost, a follow -- and only some of it was ever notified.
						title: t('social', 'Activities'),
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
					// first, because it is the reader's own page rather than
					// somewhere they go looking: the button this menu hangs off
					// used to *be* the link to it, and moving that button here
					// without putting the link back would have left a profile
					// nobody could reach
					{
						key: 'social-profile',
						icon: IconAccountCircle,
						title: t('social', 'My profile'),
						to: { name: 'profile', params: { account: this.currentUser?.uid } },
					},
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
					{
						key: 'social-statistics',
						icon: IconChartBox,
						title: t('social', 'Statistics'),
						to: { name: 'statistics' },
					},
				],

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
			// different from the My Feed entry, which carries no params at all.
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
/* The button the More menu hangs off is the reader's own account: their
   portrait where the cog was, and the name they publish under beside it.
   `NcAppNavigationSettings` renders that cog from a hard-coded path with no
   slot to replace it, so the icon box carries the picture as its background
   and the cog inside it is hidden rather than fought with. */
.navigation__more :deep(.button-vue__icon) {
	background-image: var(--social-face);
	background-position: center;
	background-size: cover;
	border-radius: 50%;
	// the picture is the icon, so the glyph that was there must not show
	// through it — including its own background, which is not transparent
	overflow: hidden;

	svg {
		visibility: hidden;
	}
}

.navigation__more :deep(.button-vue__text) {
	// the button's wrapper has no gap of its own, so the name sits against the
	// portrait unless it is given one
	margin-inline-start: 8px;
	font-weight: 600;
}

.navigation__subname {
	font-size: 12px;
	color: var(--color-text-lighter);
}

/*
 * The account menu opens as a drawer: the rows come in from the leading edge,
 * one behind the next.
 *
 * `NcAppNavigationSettings` animates its own `max-height`, so the panel grew
 * and the rows inside it were simply there when it finished -- the container
 * moved and its contents did not. These slide in behind it, 34ms apart, in the
 * direction the sidebar itself runs.
 *
 * Driven off `aria-expanded`, which is the accordion's own state and the only
 * hook it offers: there is no `open` prop and no event, and the component's
 * internal class names are content-hashed, so anything keyed on those would
 * break on the next release of the library.
 *
 * The delay comes from `--entry-index`, set in the template. The DOM cannot be
 * counted here: `NcAppNavigationItem` puts every entry in a wrapper of its own,
 * so each one is its parent's first child and `nth-child` hands them all the
 * same delay. There is a test that they are laid out that way, so that this
 * comment stops being true loudly rather than quietly.
 *
 * All of it sits inside `@supports selector(:has(*))` on purpose. Without
 * `:has()` the browser drops the rule that brings the rows *back*, and a bare
 * `opacity: 0` would leave the menu permanently empty -- so where it is not
 * supported, nothing animates and the menu behaves exactly as it does today.
 */
@supports selector(:has(*)) {
	.navigation__more :deep(.app-navigation-entry) {
		opacity: 0;
		/* the leading edge, whichever side that is */
		transform: translateX(calc(var(--social-menu-slide, 1) * -14px));
		transition:
			opacity .24s ease,
			transform .34s cubic-bezier(.3, 1.25, .5, 1);
	}

	[dir="rtl"] .navigation__more {
		--social-menu-slide: -1;
	}

	.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry) {
		opacity: 1;
		transform: none;
		transition-delay: calc(var(--entry-index, 0) * 34ms);
	}

	/* A reader who asked for no movement still gets the menu, all at once and
	   without the stagger: a row that fades in a fifth of a second after the
	   one above it is the movement they turned off, even though nothing
	   travels. */
	@media (prefers-reduced-motion: reduce) {
		.navigation__more :deep(.app-navigation-entry) {
			transition: none;
			transform: none;
		}

		.navigation__more:has(button[aria-expanded="true"]) :deep(.app-navigation-entry) {
			transition-delay: 0ms;
		}
	}
}

/*
 * The composer draws its own frame, and in a dialog that is one frame too many.
 *
 * In a timeline it is a card among cards: a border, a radius and the app's
 * resting shadow are how it says it is the box that makes the posts below it,
 * and `position: sticky` keeps it at the top while they scroll past. A dialog
 * has already said all of that -- it is a panel over a dimmed page, with its
 * own edge -- so the inner border reads as a box drawn inside a box, and
 * sticking to the top of something that does not scroll does nothing at all.
 *
 * Undone here rather than in the composer: what changes is not the composer but
 * where it is, and this is the only place that knows.
 */
.modal-composer :deep(.new-post) {
	margin: 0;
	padding: 0;
	max-width: none;
	position: static;
	border: 0;
	border-radius: 0;
	box-shadow: none;
}

/* the lift on focus goes with it: there is nothing left to lift */
.modal-composer :deep(.new-post:focus-within) {
	box-shadow: none;
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

/* The call to action.
 *
 * Concentric: a pill that becomes briefly larger than itself. Two rings open
 * outward from its own outline while the disc grows inside it, so everything on
 * screen expands from the same two centres at once. Nothing fills, nothing
 * sweeps, no colour changes hands — the button is the primary colour at rest and
 * the primary colour when reached for, and the only thing that happens is size.
 *
 * The shape is why it works. At 44px tall a full pill is a 22px radius, so its
 * end caps are arcs of a 22px circle; the plus already sits in a 28px disc, a
 * 14px circle, with 8px of air around it. Rounded all the way, the two are the
 * same family of curve and a ring drawn around the outside is concentric with
 * the badge inside. At the 8px corner this button used to have, the same ring
 * cut across the disc instead of agreeing with it.
 *
 * Rings rather than a blurred shadow: `0 0 0 <spread>` is a hard outline offset
 * from the border box, which is the only kind of shadow that stays the button's
 * own shape at any distance from it.
 */

/* The class is written twice because it has to win, not because it is two
 * things. `.button-vue[data-v-…]` sets `border-radius`, `font-weight` and
 * `transition` at exactly the specificity a single scoped class has, which
 * leaves the winner to source order — and the order of this component's styles
 * against the library's is not something this file gets to decide. Everything
 * that would otherwise be a silent tie lives in here. */
.navigation__compose.navigation__compose {
	position: relative;
	margin: 2px 4px 8px;
	/* `wide` sets `width: 100%`, which is the container's full content width --
	   and the margin above it has nowhere to go. The button was as wide as the
	   whole list *and* shifted 4px right, so its right edge overhung the rows
	   below it and ran into the edge of the sidebar; the left looked correct
	   because there the margin pushed it inward. The width has to come off
	   explicitly: `auto` is no use on a `<button>`, which shrink-wraps, and that
	   is why NcButton reaches for `fit-content` and `100%` and never `auto`. */
	width: calc(100% - 8px);
	font-weight: 600;
	/* The height the design was drawn at, and the reason the rest of it works.
	   NcButton stands at `--default-clickable-area`, which this server sets to
	   34px — and the plus already sits in a 28px disc, so at 34 the badge has
	   three pixels of air and reads as jammed between the two edges rather than
	   held inside them. At 44 it has eight, the pill's end caps become arcs of a
	   22px circle against the disc's 14, and a ring drawn around the outside is
	   concentric with the badge inside instead of merely near it.

	   It does make this taller than every other control in the sidebar. That is
	   the one thing in there that is not a place to go, so it is the one thing
	   that may be. */
	min-height: 44px;
	/* a true pill: half of the 44 above */
	border-radius: 999px;
	/* NcButton draws a 1px border weighted to 2px along the bottom. On a solid
	   primary fill it is invisible either way, but rings drawn around an uneven
	   outline are not concentric with anything. */
	border: 0;
	/* NcButton pads to `--button-radius` + a baseline, which is 12px and was
	   drawn as 8: at 12 the disc sits too far inside its own end cap for the two
	   curves to read as the same shape. */
	padding-inline: 8px;
	/* The rings hang outside the button, so nothing may clip them. NcButton
	   sets `overflow: hidden` on itself, which does not affect a shadow on the
	   button but does cut one off a child -- and the rings are a child now, for
	   the reason below. */
	overflow: visible;
	transition: transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/*
 * The rings, on a layer of their own.
 *
 * They were two `box-shadow`s on the button, grown from zero spread. That reads
 * correctly and paints terribly: `box-shadow` is not a compositor property, so
 * every frame of the growth was a repaint on the main thread -- the same thread
 * that, while the app is still starting, is parsing chunks and mounting a
 * timeline that renders every post it has with a plain `v-for`. The rings lost
 * that race, which is why the button looked dead until the page settled.
 *
 * Here the shadow is painted once, at full size, and never animated. What
 * animates is `opacity` and `transform` on the layer holding it, and those two
 * are handled by the compositor: once the hover has been noticed the growth
 * runs off the main thread and keeps its frame rate no matter what Vue is doing
 * on the other side of the page.
 *
 * What this cannot fix is a main thread blocked solid. Noticing `:hover` at all
 * is a style recalculation, and a recalculation waits its turn like everything
 * else; through one long task the button still cannot respond. It responds
 * through all the short ones, which is most of a page load.
 */
.navigation__compose::after {
	content: '';
	position: absolute;
	z-index: -1;
	inset: 0;
	border-radius: inherit;
	box-shadow:
		0 0 0 3px color-mix(in srgb, var(--color-primary-element) 34%, transparent),
		0 0 0 9px color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	opacity: 0;
	transform: scale(.94);
	transition:
		opacity .28s ease,
		transform .32s cubic-bezier(.22, 1.2, .48, 1);
	pointer-events: none;
	/* promoted up front: a layer created at the moment of the hover would be
	   created by the main thread, which is the thread being waited on */
	will-change: opacity, transform;
}

/* Reached for, by pointer or by keyboard. The focus ring is the server's and
   arrives `!important`, so on focus the rings give way to it — which is the
   right way round: the ring that says "the keyboard is here" is the one that
   has to be unmissable. */
.navigation__compose.navigation__compose:is(:hover, :focus-visible) {
	transform: translateY(-1px);
}

.navigation__compose:is(:hover, :focus-visible)::after {
	opacity: 1;
	transform: scale(1);
}

.navigation__compose.navigation__compose:active {
	transform: translateY(0) scale(.985);
}

.navigation__compose:active::after {
	transform: scale(.97);
}

/* the plus sits in a disc of its own, which grows with the rings rather than
   kicking once and settling back: the whole gesture is one expansion, and a pop
   that returns to where it started would be the one thing on screen not doing
   it. It grows rather than turns, as it always did — a plus is the same plus at
   every right angle, so a rotation of one is movement nobody can see. */
/* the glyph rather than the icon box it sits in, which is nearly as tall as
   the button and would make a disc the size of the whole end of it */
.navigation__compose :deep(.plus-icon) {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	/* a wash of the label's own colour, so the disc belongs to the button
	   whatever the instance's primary colour is */
	background: color-mix(in srgb, var(--color-primary-element-text) 22%, transparent);
	transition:
		background-color .2s ease,
		transform .32s cubic-bezier(.22, 1.2, .48, 1);
}

/* the wrapper has no gap of its own, and the label sits against the disc */
.navigation__compose :deep(.button-vue__text) {
	margin-inline-start: 6px;
}

.navigation__compose:is(:hover, :focus-visible) :deep(.plus-icon) {
	transform: scale(1.12);
	background: color-mix(in srgb, var(--color-primary-element-text) 32%, transparent);
}

@media (prefers-reduced-motion: reduce) {
	.navigation__compose.navigation__compose,
	.navigation__compose::after,
	.navigation__compose :deep(.plus-icon) {
		transition: none;
	}

	/* the rings still open, they just open at once */
	.navigation__compose:is(:hover, :focus-visible)::after {
		transform: none;
	}

	.navigation__compose.navigation__compose:is(:hover, :focus-visible),
	.navigation__compose.navigation__compose:active {
		transform: none;
	}

	.navigation__compose:is(:hover, :focus-visible) :deep(.plus-icon) {
		transform: none;
	}

	/* the rings still open, they just open at once: a reader who asked for no
	   movement still has to be able to tell a hovered button from a resting
	   one, and with nothing else changing the rings are all there is to tell
	   them with */
}
</style>
