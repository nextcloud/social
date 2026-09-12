<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="switcher"
		role="radiogroup"
		:aria-label="t('social', 'Which posts to show')"
		@keydown="onKeydown">
		<!-- the pill that slides: one element that moves, rather than three
		     that light up, so the eye follows the change instead of finding it -->
		<span
			class="switcher__glider"
			:style="gliderStyle"
			aria-hidden="true" />

		<button
			v-for="(feed, index) in feeds"
			:key="feed.type"
			ref="options"
			class="switcher__option"
			:class="{ 'switcher__option--active': feed.type === type }"
			role="radio"
			type="button"
			:aria-checked="feed.type === type"
			:tabindex="feed.type === type ? 0 : -1"
			@click="show(feed.type, index)">
			<component
				:is="feed.icon"
				class="switcher__icon"
				:size="18" />
			<span class="switcher__label">{{ feed.label }}</span>
		</button>
	</div>
</template>

<script>
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconEarth from 'vue-material-design-icons/Earth.vue'
import IconHome from 'vue-material-design-icons/Home.vue'

/**
 * The three timelines a reader moves between all day, on the page rather than
 * in the sidebar.
 *
 * They were a sidebar entry each, which is where you go for a place you visit;
 * these three are the same place seen from three distances, and switching
 * between them is something you do while reading rather than something you
 * navigate to. Mastodon and every client of it put them side by side above the
 * posts for that reason.
 *
 * Hand-built rather than `NcCheckboxRadioSwitch`, for the one thing that
 * component cannot do: a single indicator that *travels* between the three.
 * Three buttons that light up tell you where you landed; one pill that slides
 * tells you where you came from, which is what makes a switch feel like a
 * switch. The cost is the accessibility, which is therefore done here in full —
 * a real `radiogroup`, arrow keys, a roving tabindex — rather than left to a
 * set of links dressed up as tabs.
 *
 * `home` is the route with no `type` at all, which is why the values here are
 * the route's own words rather than the labels: `timeline` is what the store
 * calls the local one and `federated` the global one, and translating between
 * two vocabularies in a component that only routes would be one more place for
 * them to disagree. Photos uses the same three words in its `scope` query for
 * the same reason.
 */
export default {
	name: 'TimelineSwitcher',

	props: {
		/** Which of the three is being shown, in the route's own words. */
		type: {
			type: String,
			required: true,
		},

		/**
		 * Whether the three are the *photo* feeds rather than the whole ones.
		 *
		 * Photos is one page with a scope on it rather than three pages, so
		 * the scope rides in the query: the sidebar's Photos entry stays lit
		 * whichever of the three is chosen, which it would not if each were a
		 * `type` of its own.
		 */
		photos: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		feeds() {
			return [
				// "My Feed" rather than "Home": next to Local and Global, what
				// distinguishes it is whose posts it holds, not where it sits
				{ type: 'home', label: t('social', 'My Feed'), icon: IconHome },
				{ type: 'timeline', label: t('social', 'Local'), icon: IconAccountMultiple },
				{ type: 'federated', label: t('social', 'Global'), icon: IconEarth },
			]
		},

		/** @return {number} which of the three is on screen, 0 when none is */
		activeIndex() {
			return Math.max(0, this.feeds.findIndex((feed) => feed.type === this.type))
		},

		/**
		 * Where the pill is, as a share of the track.
		 *
		 * Percentages of its own width rather than pixels: the control is as
		 * wide as its labels, which are translated, so nothing here may assume
		 * a measurement.
		 *
		 * The width is left to the stylesheet, which takes the track's padding
		 * off first: a third of the whole track is two pixels wider than a
		 * third of the room the options actually share, and the pill would
		 * stick out past the one it is under. Only the count comes from here.
		 *
		 * @return {object} the inline style
		 */
		gliderStyle() {
			return {
				'--switcher-count': this.feeds.length,
				transform: `translateX(${this.activeIndex * 100}%)`,
			}
		},
	},

	methods: {
		/**
		 * @param {string} type the timeline to show
		 * @param {number} index where it is in the group
		 */
		show(type, index) {
			this.focus(index)

			if (type === this.type) {
				return
			}

			this.$router.push(this.routeFor(type))
		},

		/**
		 * The same three words in both families, so nothing here translates
		 * between two vocabularies.
		 *
		 * @param {string} type one of the three
		 * @return {object} where to go for it
		 */
		routeFor(type) {
			if (this.photos) {
				// the page is the same one; only the scope on it changes
				return {
					name: 'timeline',
					params: { type: 'photos' },
					query: type === 'home' ? {} : { scope: type },
				}
			}

			// `home` is the bare route: passing `type: 'home'` would ask for a
			// timeline of that name, which nothing serves
			return type === 'home'
				? { name: 'timeline' }
				: { name: 'timeline', params: { type } }
		},

		/**
		 * Arrow keys move through the group, as they do in every radio group —
		 * a roving tabindex puts one stop on the control, not three.
		 *
		 * @param {KeyboardEvent} event the key
		 */
		onKeydown(event) {
			const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key]
			if (step === undefined) {
				return
			}

			event.preventDefault()
			// wraps, so the end of the group is never a dead stop
			const next = (this.activeIndex + step + this.feeds.length) % this.feeds.length
			this.show(this.feeds[next].type, next)
		},

		/**
		 * @param {number} index the option to put the focus on
		 */
		focus(index) {
			const options = this.$refs.options
			const option = Array.isArray(options) ? options[index] : undefined
			option?.focus?.()
		},
	},
}
</script>

<style scoped lang="scss">
.switcher {
	position: relative;
	display: flex;
	align-items: stretch;
	width: fit-content;
	max-width: 100%;
	margin: 0 auto 14px;
	padding: 3px;
	border-radius: var(--border-radius-pill, 100px);
	// the track is a well rather than a surface: darker than the page, with no
	// shadow of its own, so the only thing casting one is the pill in it
	background: var(--color-background-dark);
}

/* the pill, behind the labels and travelling between them */
.switcher__glider {
	position: absolute;
	z-index: 0;
	top: 3px;
	bottom: 3px;
	inset-inline-start: 3px;
	// the track's own 3px of padding is not the options' to share
	width: calc((100% - 6px) / var(--switcher-count, 3));
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-primary-element);
	// the app's one shadow, as every other raised thing in the timeline uses:
	// a hand-rolled rgba here and the page stops looking like one surface
	box-shadow: var(--social-elevation-resting);
	// overshoots and settles, which is what makes it read as a thing that
	// moved rather than a colour that changed
	transition: transform .42s cubic-bezier(.22, 1.4, .36, 1);
}

/* Written from inside the track, and every state of it said out loud.
   Nextcloud styles bare `button` elements, and its rule for the hovered and
   focused ones is `button:not(.button-vue, [class^="vs__"]):hover` — more
   specific than one class, so a plain `.switcher__option:hover` loses and the
   server paints its own colour straight over the pill. `.switcher &` outranks
   it, and what is not restated here is inherited from the server. */
.switcher .switcher__option {
	position: relative;
	z-index: 1;
	// every option as wide as the widest, so a pill of one third of the track
	// lands exactly on one of them — "My Feed" is a wider word than "Local"
	flex: 1 1 0;
	display: flex;
	gap: 6px;
	align-items: center;
	justify-content: center;
	min-width: 0;
	min-height: 36px;
	padding: 0 16px;
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	color: var(--color-text-maxcontrast);
	font-size: inherit;
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
	transition: color .2s ease, background-color .2s ease, transform .2s cubic-bezier(.22, 1.2, .48, 1);

	// the pill is the only background in the control, in every state
	&,
	&:hover,
	&:focus,
	&:active {
		background: transparent;
	}

	&:active {
		transform: scale(.96);
	}

	// the ring belongs to the option, not to the track it sits in
	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 1px;
	}
}

/* the two you did not choose warm up under the pointer: a ghost of the pill,
   so hovering shows you where it would land */
.switcher .switcher__option:not(.switcher__option--active) {
	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
		color: var(--color-main-text);
		transform: translateY(-1px);
	}
}

.switcher .switcher__option--active {
	color: var(--color-primary-element-text);
}

.switcher__icon {
	display: flex;
	transition: transform .2s cubic-bezier(.22, 1.2, .48, 1);
}

/* the icon of the one you just chose gives a little kick */
.switcher__option--active .switcher__icon {
	animation: switcher-pop .45s cubic-bezier(.34, 1.56, .64, 1);
}

/* and the globe turns, because it is a globe */
.switcher__option--active :deep(.earth-icon) {
	animation: switcher-spin .6s cubic-bezier(.4, 0, .2, 1);
}

@keyframes switcher-pop {
	0% { transform: scale(.6); }
	60% { transform: scale(1.18); }
	100% { transform: scale(1); }
}

@keyframes switcher-spin {
	0% { transform: rotate(-160deg) scale(.7); }
	100% { transform: rotate(0) scale(1); }
}

/* the labels shrink away before the control does, so three stay on one line */
@media (max-width: 500px) {
	.switcher__option {
		padding: 0 12px;
	}

	.switcher__label {
		display: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.switcher__glider,
	.switcher .switcher__option,
	.switcher__icon {
		transition: none;
	}

	.switcher__option--active .switcher__icon,
	.switcher__option--active :deep(.earth-icon) {
		animation: none;
	}

	// the same specificity the states above needed, for the same reason
	.switcher .switcher__option:not(.switcher__option--active):hover,
	.switcher .switcher__option:active {
		transform: none;
	}
}
</style>
