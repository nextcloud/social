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
	<NcButton :value="currentVisibilityPostLabel"
		:disabled="!disabled"
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
		type: {
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
			switch (this.type) {
			case 'public':
			case 'unlisted':
				return t('social', 'Post')
			case 'followers':
				return t('social', 'Post to followers')
			case 'direct':
				return t('social', 'Post to mentioned users')
			}
			return ''
		},
		/** @return {string} */
		currentVisibilityPostLabel() {
			return this.visibilityPostLabel(this.type)
		},
		/** @return {Function} */
		visibilityPostLabel() {
			return (type) => {
				if (typeof type === 'undefined') {
					type = this.type
				}
				switch (type) {
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

<style scoped lang="scss">
.new-post {
	padding: 10px;
	background-color: var(--color-main-background);
	position: sticky;
	z-index: 100;
	margin-bottom: 10px;
	top: 0;

	&-form {
		flex-grow: 1;
		position: relative;
		top: -10px;
		margin-left: 39px;
		&__emoji-picker {
			z-index: 1;
		}
	}
}

input[type=submit].inline {
	width: 44px;
	height: 44px;
	margin: 0;
	padding: 13px;
	background-color: transparent;
	border: none;
	opacity: 0.3;
	position: absolute;
	bottom: 0;
	right: 0;
}

</style>
