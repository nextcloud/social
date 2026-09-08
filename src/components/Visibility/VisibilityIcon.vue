<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Earth v-if="visibility === 'public'" :size="22" />
	<AccountMultiple v-else-if="visibility === 'followers'" :size="22" />
	<LockOpen v-else-if="visibility === 'unlisted'" :size="22" />
	<At v-else-if="visibility === 'direct'" :size="22" />
	<!-- a visibility this client does not know is still a visibility: showing
	     nothing at all reads as "public", which may be exactly wrong -->
	<HelpCircleOutline v-else :size="22" />
</template>
<script>
import Earth from 'vue-material-design-icons/Earth.vue'
import LockOpen from 'vue-material-design-icons/LockOpen.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import At from 'vue-material-design-icons/At.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'

export default {
	name: 'VisibilityIcon',
	components: {
		Earth,
		LockOpen,
		AccountMultiple,
		At,
		HelpCircleOutline,
	},
	props: {
		visibility: {
			type: String,
			required: true,
		},
	},
	data() {
		return {}
	},
	computed: {

	},
	methods: {

	},
}
</script>
