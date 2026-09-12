<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="empty-content">
		<NcEmptyContent :name="item.title" :description="item.description">
			<template v-if="item.image" #icon>
				<img
					class="empty-content__image"
					:src="imageUrl"
					alt="">
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>

import { linkTo } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

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
.empty-content {
	min-height: 60vh;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

.empty-content__image {
	height: 256px;
	width: 256px;
	/* they arrive at their own size and settle in */
	animation: empty-content-settle .45s cubic-bezier(.22, 1, .36, 1) both;
}

@keyframes empty-content-settle {
	from {
		opacity: 0;
		transform: scale(.94);
	}

	to {
		opacity: 1;
		transform: none;
	}
}

/**
 * The artwork is flat SVG served as <img>, so its palette cannot follow the
 * instance's accent without inlining every file into the bundle. What it can
 * do is stop glaring on a dark background: the light pastels are dimmed and
 * pulled slightly towards the surrounding surface.
 */
@media (prefers-color-scheme: dark) {
	.empty-content__image {
		filter: brightness(.82) saturate(.9);
	}
}

[data-themes*='dark'] .empty-content__image {
	filter: brightness(.82) saturate(.9);
}

@media (prefers-reduced-motion: reduce) {
	.empty-content__image {
		animation: none;
	}
}

:deep(.empty-content__icon) {
	opacity: 1;
	margin-bottom: 90px;
}
</style>
