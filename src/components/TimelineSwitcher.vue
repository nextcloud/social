<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav class="timeline-switcher" :aria-label="t('social', 'Which posts to show')">
		<NcCheckboxRadioSwitch
			v-for="feed in feeds"
			:key="feed.type"
			:modelValue="current"
			:value="feed.type"
			name="timeline-switcher"
			type="radio"
			buttonVariant
			buttonVariantGrouped="horizontal"
			@update:modelValue="show">
			{{ feed.label }}
		</NcCheckboxRadioSwitch>
	</nav>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

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
 * `home` is the route with no `type` at all, which is why the values here are
 * the route's own words rather than the labels: `timeline` is what the store
 * calls the local one and `federated` the global one, and translating between
 * two vocabularies in a component that only routes would be one more place for
 * them to disagree.
 */
export default {
	name: 'TimelineSwitcher',

	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		/** The timeline being shown, as `Timeline.vue` names it. */
		type: {
			type: String,
			required: true,
		},
	},

	computed: {
		feeds() {
			return [
				// "My Feed" rather than "Home": next to Local and Global, what
				// distinguishes it is whose posts it holds, not where it sits
				{ type: 'home', label: t('social', 'My Feed') },
				{ type: 'timeline', label: t('social', 'Local') },
				{ type: 'federated', label: t('social', 'Global') },
			]
		},

		/** @return {string} which of the three is on screen */
		current() {
			return this.type
		},
	},

	methods: {
		/**
		 * @param {string} type the timeline to show
		 */
		show(type) {
			if (type === this.current) {
				return
			}

			// `home` is the bare route: passing `type: 'home'` would ask for a
			// timeline of that name, which nothing serves
			this.$router.push(type === 'home'
				? { name: 'timeline' }
				: { name: 'timeline', params: { type } })
		},
	},
}
</script>

<style scoped lang="scss">
.timeline-switcher {
	display: flex;
	justify-content: center;
	margin-block: 0 12px;

	// the group is one control: it stays on one line and each part keeps its
	// label readable rather than shrinking to fit a narrow column
	:deep(.checkbox-radio-switch) {
		flex: 0 1 auto;
		min-width: 0;
	}
}
</style>
