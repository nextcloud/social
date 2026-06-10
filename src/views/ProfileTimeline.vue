<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<TimelineList />
</template>

<script>
import TimelineList from './../components/TimelineList.vue'

export default {
	name: 'ProfileTimeline',
	components: {
		TimelineList,
	},
	watch: {
		'$route.params.account': 'loadTimeline',
	},
	beforeMount() {
		this.loadTimeline()
	},
	methods: {
		loadTimeline() {
			if (this.$route.params.account) {
				this.$store.dispatch('changeTimelineTypeAccount', this.$route.params.account)
			}
		},
	},
}
</script>
