<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="switcher"
		role="radiogroup"
		:aria-label="label"
		@keydown="onKeydown">
		<!-- the pill that slides: one element that moves, rather than several
		     that light up, so the eye follows the change instead of finding it -->
		<span
			class="switcher__glider"
			:style="gliderStyle"
			aria-hidden="true" />

		<button
			v-for="(option, index) in options"
			:key="option.value"
			ref="options"
			class="switcher__option"
			:class="{ 'switcher__option--active': option.value === value }"
			role="radio"
			type="button"
			:aria-checked="option.value === value"
			:tabindex="option.value === value ? 0 : -1"
			@click="show(index)">
			<component
				:is="option.icon"
				class="switcher__icon"
				:size="18" />
			<span class="switcher__label">{{ option.label }}</span>
		</button>
	</div>
</template>

<script>
/**
 * A row of places to be, with a pill that travels between them.
 *
 * It was written for the three timelines a reader moves between all day, which
 * were a sidebar entry each — and a sidebar entry is where you go for a place
 * you *visit*. Those three are the same place seen from three distances, and
 * switching between them is something you do while reading rather than
 * something you navigate to; Mastodon and every client of it put them side by
 * side above the posts for that reason. A profile's Posts, Photos and Videos
 * are the same shape of choice, so they are the same control.
 *
 * Hand-built rather than `NcCheckboxRadioSwitch`, for the one thing that
 * component cannot do: a single indicator that *travels* between the options.
 * Several buttons that light up tell you where you landed; one pill that
 * slides tells you where you came from, which is what makes a switch feel like
 * a switch. The cost is the accessibility, which is therefore done here in
 * full — a real `radiogroup`, arrow keys, a roving tabindex — rather than left
 * to a set of links dressed up as tabs.
 *
 * It routes and nothing else: every option carries the route it stands for, so
 * the page it sits on stays the one answer to what is being shown. The words
 * in those routes are the route's own — `timeline` for the local feed,
 * `federated` for the global one, `image` and `video` for the kinds of
 * attachment the API knows — rather than the labels beside them, because two
 * vocabularies would be one more place for the two to disagree.
 */
export default {
	name: 'TimelineSwitcher',

	props: {
		/**
		 * What to choose between: `{ value, label, icon, to }` each, where
		 * `to` is a route the option pushes when it is chosen.
		 */
		options: {
			type: Array,
			required: true,
		},

		/** Which option's page is on screen, by its `value`. */
		value: {
			type: String,
			required: true,
		},

		/** What the group is choosing, for a screen reader. */
		label: {
			type: String,
			required: true,
		},
	},

	computed: {
		/** @return {number} which option is on screen, 0 when none is */
		activeIndex() {
			return Math.max(0, this.options.findIndex((option) => option.value === this.value))
		},

		/**
		 * Where the pill is, as a share of the track.
		 *
		 * Percentages of its own width rather than pixels: the control is as
		 * wide as its labels, which are translated, so nothing here may assume
		 * a measurement.
		 *
		 * The width is left to the stylesheet, which takes the track's padding
		 * off first: a share of the whole track is a couple of pixels wider
		 * than a share of the room the options actually have, and the pill
		 * would stick out past the one it is under. Only the count comes from
		 * here.
		 *
		 * @return {object} the inline style
		 */
		gliderStyle() {
			return {
				'--switcher-count': this.options.length,
				transform: `translateX(${this.activeIndex * 100}%)`,
			}
		},
	},

	methods: {
		/**
		 * @param {number} index the option to show
		 */
		show(index) {
			this.focus(index)

			const option = this.options[index]
			if (option === undefined || option.value === this.value) {
				// choosing the page you are already on is not a navigation
				return
			}

			this.$router.push(option.to)
		},

		/**
		 * Arrow keys move through the group, as they do in every radio group —
		 * a roving tabindex puts one stop on the control, not one per option.
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
			const next = (this.activeIndex + step + this.options.length) % this.options.length
			this.show(next)
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
