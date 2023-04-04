<!--
  - @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
  - @copyright Copyright (c) 2022 Carl Schwan <carl@carlschwan.eu>
  -
  - @author Julius Härtl <jus@bitgrid.net>
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
	border: 1px solid var(--color-success);
	border-left-width: 4px;
	border-radius: 6px;
	background: var(--color-background-hover);
}
</style>
