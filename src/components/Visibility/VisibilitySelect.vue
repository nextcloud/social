<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions variant="tertiary" :menuName="selectedVisibilityDetails.text" :aria-label="t('social', 'Choose a visibility')">
		<template #icon>
			<VisibilityIcon :visibility="selectedVisibilityDetails.id" :size="20" />
		</template>
		<NcActionButton
			v-for="visibilityDetails of visibilitiesInfo"
			:key="visibilityDetails.id"
			:class="{'selected-visibility': visibilityDetails.id === selectedVisibilityDetails.id}"
			:closeAfterClick="true"
			@click="switchType(visibilityDetails)">
			<template #icon>
				<VisibilityIcon :visibility="visibilityDetails.id" :size="20" />
			</template>
			{{ visibilityDetails.description }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import { translate } from '@nextcloud/l10n'
import visibilitiesInfo from './VisibilitiesInfos.js'
import VisibilityIcon from './VisibilityIcon.vue'
import logger from '../../services/logger.js'

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

	emits: ['update:visibility'],

	data() {
		return {
			visibilitiesInfo,
		}
	},

	computed: {
		/**
		 * The visibility on the button. Falls back rather than returning
		 * undefined: the template reads `.text` off this, so one unknown id —
		 * a draft written by another version, say — took the whole composer
		 * down with it.
		 *
		 * @return {import('./VisibilitiesInfos.js').Visibility}
		 */
		selectedVisibilityDetails() {
			return visibilitiesInfo.find(({ id }) => this.visibility === id)
				?? visibilitiesInfo.find(({ id }) => id === 'followers')
		},
	},

	methods: {
		switchType(visibility) {
			this.$emit('update:visibility', visibility.id)
			try {
				// throws outright in a private window and where site data is
				// blocked, which is no reason for the choice not to take effect
				localStorage.setItem('social.lastPostType', visibility.id)
			} catch (error) {
				logger.debug('Could not remember the visibility', { error })
			}
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
