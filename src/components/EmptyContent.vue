<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
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
