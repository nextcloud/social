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
	<div v-click-outside="hidePopoverMenu" class="popovermenu-parent">
		<NcButton :title="t('social', 'Change visibility')"
			type="tertiary"
			:class="currentVisibilityIconClass"
			@click.prevent="togglePopoverMenu" />
		<div :class="{open: menuOpened}" class="popovermenu">
			<NcPopoverMenu :menu="visibilityPopover" />
		</div>
	</div>
</template>
<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcPopoverMenu from '@nextcloud/vue/dist/Components/NcPopoverMenu.js'
import visibilitiesInfo from '../VisibilitiesInfos.js'
import { translate } from '@nextcloud/l10n'

export default {
	name: 'VisibilitySelect',
	components: {
		NcPopoverMenu,
		NcButton,
	},
	props: {
		visibility: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			menuOpened: false,
		}
	},
	computed: {
		/** @return {string} */
		currentVisibilityIconClass() {
			return visibilitiesInfo.find(({ id }) => this.visibility === id).icon
		},

		/** @return {object[]} */
		visibilityPopover() {
			return visibilitiesInfo.map(visibilityInfo => {
				return {
					...visibilityInfo,
					action: () => this.switchType(visibilityInfo.id),
					active: this.visibility === visibilityInfo.id,
				}
			})
		},
	},
	methods: {
		togglePopoverMenu() {
			this.menuOpened = !this.menuOpened
		},

		hidePopoverMenu() {
			this.menuOpened = false
		},

		switchType(visibility) {
			this.$emit('update:visibility', visibility)
			this.menuOpened = false
			localStorage.setItem('social.lastPostType', visibility)
		},

		t: translate,
	},
}
</script>
<style scoped>
.popovermenu-parent {
	position: relative;
}

.popovermenu {
	top: 55px;
}
</style>
