<!--
  - @copyright Copyright (c) 2018 Jan-Christoph Borchardt (https://jancborchardt.net)
  -
  - @author Jan-Christoph Borchardt (https://jancborchardt.net)
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
	<NcEmptyContent :title="item.title" :description="item.description">
		<template v-if="item.image" #icon>
			<img class="empty-content__image"
				:src="imageUrl"
				alt="">
		</template>
	</NcEmptyContent>
</template>

<script>

import { linkTo } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

export default {
	name: 'EmptyContent',
	components: {
		NcEmptyContent,
	},
	props: {
		item: {
			type: Object,
			default: () => {},
		},
	},
	computed: {
		/** @return {string} */
		imageUrl() {
			return linkTo('social', this.item.image)
		},
	},
}
</script>
<style scoped>
.empty-content__image {
	height: 256px;
	width: 256px;
}

:deep(.empty-content__icon) {
	opacity: 1;
	margin-bottom: 90px;
}
</style>
