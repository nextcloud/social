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
		<NcButton v-tooltip="t('social', 'Visibility')"
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
import { translate } from '@nextcloud/l10n'

export default {
	name: 'VisibilitySelect',
	components: {
		NcPopoverMenu,
		NcButton,
	},
	props: {
		type: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			menuOpened: false,
			test: false,
			typeToClass: {
				public: 'icon-link',
				followers: 'icon-contacts-dark',
				direct: 'icon-external',
				unlisted: 'icon-password',
			},
		}
	},
	computed: {
		/** @return {string} */
		currentVisibilityIconClass() {
			return this.typeToClass[this.type]
		},
		/** @return {Array} */
		visibilityPopover() {
			return [
				{
					action: () => this.switchType('public'),
					icon: this.typeToClass.public,
					active: this.type === 'public',
					text: t('social', 'Public'),
					longtext: t('social', 'Post to public timelines'),
				},
				{
					action: () => this.switchType('unlisted'),
					icon: this.typeToClass.unlisted,
					active: this.type === 'unlisted',
					text: t('social', 'Unlisted'),
					longtext: t('social', 'Do not post to public timelines'),
				},
				{
					action: () => this.switchType('followers'),
					icon: this.typeToClass.followers,
					active: this.type === 'followers',
					text: t('social', 'Followers'),
					longtext: t('social', 'Post to followers only'),
				},
				{
					action: () => this.switchType('direct'),
					icon: this.typeToClass.direct,
					active: this.type === 'direct',
					text: t('social', 'Direct'),
					longtext: t('social', 'Post to mentioned users only'),
				},
			]
		},
	},
	methods: {
		togglePopoverMenu() {
			this.menuOpened = !this.menuOpened
		},

		hidePopoverMenu() {
			this.menuOpened = false
		},

		switchType(type) {
			this.$emit('update:type', type)
			this.menuOpened = false
			localStorage.setItem('social.lastPostType', type)
		},

		t: translate,
	},
}
</script>
