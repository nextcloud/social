<!--
  - @copyright Copyright (c) 2023 Louis Chmn <louis@chmn.me>
  -
  - @2023 Louis Chmn <louis@chmn.me>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<Earth v-if="visibility === 'public'" :size="22" />
	<AccountMultiple v-else-if="visibility === 'followers'" :size="22" />
	<LockOpen v-else-if="visibility === 'unlisted'" :size="22" />
	<At v-else-if="visibility === 'direct'" :size="22" />
</template>
<script>
import Earth from 'vue-material-design-icons/Earth.vue'
import LockOpen from 'vue-material-design-icons/LockOpen.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import At from 'vue-material-design-icons/At.vue'

export default {
	name: 'VisibilityIcon',
	components: {
		Earth,
		LockOpen,
		AccountMultiple,
		At,
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
