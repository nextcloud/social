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
	computed: {

	},
	beforeMount() {
		this.$store.dispatch('changeTimelineTypeAccount', this.$route.params.account)
	},
}
</script>
