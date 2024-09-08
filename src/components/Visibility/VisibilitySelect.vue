<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions type="tertiary" :menu-title="selectedVisibilityDetails.text" :aria-label="t('social', 'Choose a visibility')">
		<template #icon>
			<VisibilityIcon :visibility="selectedVisibilityDetails.id" :size="20" />
		</template>
		<NcActionButton v-for="visibilityDetails of visibilitiesInfo"
			:key="visibilityDetails.id"
			:class="{'selected-visibility': visibilityDetails.id === selectedVisibilityDetails.id}"
			:close-after-click="true"
			@click="switchType(visibilityDetails)">
			<template #icon>
				<VisibilityIcon :visibility="visibilityDetails.id" :size="20" />
			</template>
			{{ visibilityDetails.description }}
		</NcActionButton>
	</NcActions>
</template>
<script>
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import { translate } from '@nextcloud/l10n'
import visibilitiesInfo from './VisibilitiesInfos.js'
import VisibilityIcon from './VisibilityIcon.vue'

export default {
	name: 'VisibilitySelect',
	components: {
		NcActions,
		NcActionButton,
		VisibilityIcon,
	},
	props: {
		visibility: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			visibilitiesInfo,
		}
	},
	computed: {
		/** @return {import('./VisibilitiesInfos.js').Visibility} */
		selectedVisibilityDetails() {
			return visibilitiesInfo.find(({ id }) => this.visibility === id)
		},
	},
	methods: {
		switchType(visibility) {
			this.$emit('update:visibility', visibility.id)
			// this.menuOpened = false
			localStorage.setItem('social.lastPostType', visibility.id)
		},

		t: translate,
	},
}
</script>
<style scoped>
.selected-visibility {
	outline: 1px solid var(--color-success);
	border-radius: 6px;
	background: var(--color-background-hover);
}
</style>
