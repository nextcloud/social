<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton :value="currentVisibilityPostLabel"
		:disabled="disabled"
		native-type="submit"
		type="primary"
		@click.prevent="handleClick">
		<template #icon>
			<Send title="" :size="22" decorative />
		</template>
		{{ postTo }}
	</NcButton>
</template>

<script>

import Send from 'vue-material-design-icons/Send.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'SubmitStatusButton',
	components: {
		NcButton,
		Send,
	},
	props: {
		visibility: {
			type: String,
			required: true,
		},
		disabled: {
			type: Boolean,
			default: true,
		},
	},
	computed: {
		/** @return {string} */
		postTo() {
			switch (this.visibility) {
			case 'public':
			case 'unlisted':
				return t('social', 'Post')
			case 'followers':
				return t('social', 'Post to followers')
			case 'direct':
				return t('social', 'Send message to mentioned users')
			}
			return ''
		},
		/** @return {string} */
		currentVisibilityPostLabel() {
			return this.visibilityPostLabel(this.visibility)
		},
		/** @return {Function} */
		visibilityPostLabel() {
			return (visibility) => {
				if (visibility === undefined) {
					visibility = this.visibility
				}
				switch (visibility) {
				case 'public':
					return t('social', 'Post publicly')
				case 'followers':
					return t('social', 'Post to followers')
				case 'direct':
					return t('social', 'Post to recipients')
				case 'unlisted':
					return t('social', 'Post unlisted')
				}
			}
		},
	},
	methods: {
		handleClick() {
			this.$emit('click')
		},
	},
}

</script>
