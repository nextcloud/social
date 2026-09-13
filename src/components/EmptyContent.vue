<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline-empty" :class="{ 'timeline-empty--bare': !item.image }">
		<NcEmptyContent :name="item.title" :description="item.description">
			<template v-if="item.image" #icon>
				<img
					class="timeline-empty__image"
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
/*
 * `timeline-empty` rather than `empty-content`: NcEmptyContent's own root
 * carries that class, and a child component's root inherits the parent's scope
 * id — so a scoped rule on `.empty-content` here landed on both this wrapper
 * and the component inside it. Two elements were 60vh tall, one nested in the
 * other, and turning the outer one off left the inner one holding the page open.
 */
.timeline-empty {
	min-height: 60vh;
	display: flex;
	flex-direction: column;
	justify-content: center;
}

/*
 * Most of the 60vh is room for the illustration. A state that has none is a
 * line of text, and holding 60% of the window open under it — which is what
 * "No replies found" did under every post with no replies — says the page is
 * still loading something.
 */
.timeline-empty--bare {
	min-height: 0;
	padding: 20px 0 28px;
}

.timeline-empty__image {
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
	.timeline-empty__image {
		filter: brightness(.82) saturate(.9);
	}
}

[data-themes*='dark'] .timeline-empty__image {
	filter: brightness(.82) saturate(.9);
}

@media (prefers-reduced-motion: reduce) {
	.timeline-empty__image {
		animation: none;
	}
}

:deep(.empty-content__icon) {
	opacity: 1;
	margin-bottom: 90px;
}

/* that margin is the gap under the illustration; with none there is no gap */
.timeline-empty--bare :deep(.empty-content__icon) {
	margin-bottom: 0;
}
</style>
